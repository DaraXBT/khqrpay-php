<?php

namespace KHQRPay\Tests;

use KHQRPay\Client;
use KHQRPay\KHQRPayError;
use KHQRPay\Tests\Support\FakeServer;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const KEY = 'kp_test_51HqLyjWDarjtT1zdp7dcXs9u';

    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    /** @var FakeServer */
    private $server;

    /** @var array<string,mixed> */
    private $vectors;

    protected function setUp(): void
    {
        $this->server = new FakeServer();
        $this->vectors = json_decode((string) file_get_contents(__DIR__ . '/test-vectors.json'), true);
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    private function client(array $options = []): Client
    {
        return new Client(self::KEY, array_merge(['base_url' => $this->server->baseUrl()], $options));
    }

    public function testCreateSendsTheRequestAndReturnsThePayment(): void
    {
        $this->server->respondJson(201, $this->vectors['paymentResponse']);

        $payment = $this->client()->payments->create($this->vectors['createRequest']['body']);

        $this->assertSame($this->vectors['paymentResponse'], $payment);

        $request = $this->server->lastRequest();
        $this->assertSame($this->vectors['createRequest']['method'], $request['method']);
        $this->assertSame($this->vectors['createRequest']['path'], $request['uri']);
        $this->assertSame('Bearer ' . self::KEY, $request['headers']['authorization']);
        $this->assertSame('application/json', $request['headers']['content-type']);
        $this->assertSame('khqrpay-php/0.1.0', $request['headers']['user-agent']);
        $this->assertMatchesRegularExpression(self::UUID_V4, $request['headers']['idempotency-key']);

        // The body is the createRequest body exactly: same keys, order and types.
        $this->assertSame($this->vectors['createRequest']['body'], json_decode($request['body'], true));
        $this->assertSame(
            '{"amount":"16.88","currency":"USD","reference_id":"INV-2048","metadata":{"cart_id":"c_1"}}',
            $request['body']
        );
    }

    public function testCreateGeneratesAFreshIdempotencyKeyEachCall(): void
    {
        $this->server->respondJson(201, $this->vectors['paymentResponse']);
        $client = $this->client();

        $client->payments->create(['amount' => '1.00']);
        $first = $this->server->lastRequest()['headers']['idempotency-key'];
        $client->payments->create(['amount' => '1.00']);
        $second = $this->server->lastRequest()['headers']['idempotency-key'];

        $this->assertMatchesRegularExpression(self::UUID_V4, $first);
        $this->assertMatchesRegularExpression(self::UUID_V4, $second);
        $this->assertNotSame($first, $second);
    }

    public function testCreatePassesTheIdempotencyKeyThrough(): void
    {
        $this->server->respondJson(200, $this->vectors['paymentResponse']);

        $this->client()->payments->create(
            $this->vectors['createRequest']['body'],
            ['idempotency_key' => 'order-2048-attempt']
        );

        $request = $this->server->lastRequest();
        $this->assertSame('order-2048-attempt', $request['headers']['idempotency-key']);
        $this->assertSame($this->vectors['createRequest']['body'], json_decode($request['body'], true));
    }

    public function testCreateReusesAnIdempotencyKeyGivenInTheBody(): void
    {
        $this->server->respondJson(201, $this->vectors['paymentResponse']);

        $this->client()->payments->create(['amount' => '1.00', 'idempotency_key' => 'body-key-1']);

        $request = $this->server->lastRequest();
        $this->assertSame('body-key-1', $request['headers']['idempotency-key']);
        $this->assertSame(['amount' => '1.00', 'idempotency_key' => 'body-key-1'], json_decode($request['body'], true));
    }

    public function testCreateSendsKhrAmountsAsStrings(): void
    {
        $this->server->respondJson(201, $this->vectors['paymentResponse']);

        $this->client()->payments->create(['amount' => '20000', 'currency' => 'KHR', 'metadata' => []]);

        $this->assertSame(
            '{"amount":"20000","currency":"KHR","metadata":{}}',
            $this->server->lastRequest()['body']
        );
    }

    public function testErrorResponseThrowsKHQRPayError(): void
    {
        $error = $this->vectors['errorResponse'];
        $this->server->respondJson($error['status'], $error['body']);

        try {
            $this->client()->payments->create(['amount' => '16.88']);
            $this->fail('Expected a KHQRPayError');
        } catch (KHQRPayError $e) {
            $this->assertSame('invalid_request', $e->getErrorCode());
            $this->assertSame(400, $e->getStatus());
            $this->assertSame(400, $e->getCode());
            $this->assertSame('amount is required', $e->getMessage());
            $this->assertNull($e->getRetryAfter());
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }
    }

    public function testRateLimitSetsRetryAfter(): void
    {
        $this->server->respondJson(
            429,
            ['error' => 'rate_limited', 'message' => 'Too many requests.'],
            ['Retry-After' => '30']
        );

        try {
            $this->client()->payments->create(['amount' => '16.88']);
            $this->fail('Expected a KHQRPayError');
        } catch (KHQRPayError $e) {
            $this->assertSame('rate_limited', $e->getErrorCode());
            $this->assertSame(429, $e->getStatus());
            $this->assertSame(30, $e->getRetryAfter());
        }
    }

    public function testNonJson502GivesInvalidResponse(): void
    {
        $this->server->respond(502, '<html><body>Bad gateway</body></html>', ['Content-Type' => 'text/html']);

        try {
            $this->client()->payments->retrieve('pay_1');
            $this->fail('Expected a KHQRPayError');
        } catch (KHQRPayError $e) {
            $this->assertSame('invalid_response', $e->getErrorCode());
            $this->assertSame(502, $e->getStatus());
            $this->assertNull($e->getRetryAfter());
        }
    }

    public function testNonJsonSuccessGivesInvalidResponse(): void
    {
        $this->server->respond(200, 'OK', ['Content-Type' => 'text/plain']);

        try {
            $this->client()->usage->retrieve();
            $this->fail('Expected a KHQRPayError');
        } catch (KHQRPayError $e) {
            $this->assertSame('invalid_response', $e->getErrorCode());
            $this->assertSame(200, $e->getStatus());
        }
    }

    public function testListBuildsTheQueryStringAndOmitsUnsetParams(): void
    {
        $page = ['object' => 'list', 'data' => [], 'has_more' => false, 'next_cursor' => null];
        $this->server->respondJson(200, $page);
        $client = $this->client();

        $this->assertSame($page, $client->payments->list());
        $request = $this->server->lastRequest();
        $this->assertSame('GET', $request['method']);
        $this->assertSame('/v1/payments', $request['uri']);
        $this->assertArrayNotHasKey('idempotency-key', $request['headers']);
        $this->assertArrayNotHasKey('content-type', $request['headers']);

        $client->payments->list(['status' => 'paid', 'limit' => 10]);
        $this->assertSame('/v1/payments?status=paid&limit=10', $this->server->lastRequest()['uri']);

        $client->payments->list(['cursor' => 'eyJ0IjoxfQ==+/x ~&', 'status' => null]);
        $this->assertSame('/v1/payments?cursor=eyJ0IjoxfQ%3D%3D%2B%2Fx%20~%26', $this->server->lastRequest()['uri']);

        $client->payments->list(['limit' => 5, 'cursor' => 'c_2', 'status' => 'pending']);
        $this->assertSame('/v1/payments?status=pending&limit=5&cursor=c_2', $this->server->lastRequest()['uri']);
    }

    public function testRetrieveUrlEncodesTheId(): void
    {
        $this->server->respondJson(200, $this->vectors['paymentResponse']);

        $payment = $this->client()->payments->retrieve('pay/../x y?z#1');

        $this->assertSame($this->vectors['paymentResponse'], $payment);
        $request = $this->server->lastRequest();
        $this->assertSame('GET', $request['method']);
        $this->assertSame('/v1/payments/pay%2F..%2Fx%20y%3Fz%231', $request['uri']);
        $this->assertSame('Bearer ' . self::KEY, $request['headers']['authorization']);
        $this->assertSame('khqrpay-php/0.1.0', $request['headers']['user-agent']);
        $this->assertSame('', $request['body']);
    }

    public function testSimulatePostsTheStatus(): void
    {
        $this->server->respondJson(200, $this->vectors['paymentResponse']);

        $this->client()->payments->simulate('pFQ3xV8aN2rL7kTm9sB4cWd6hJ1yZ0eU', 'paid');

        $request = $this->server->lastRequest();
        $this->assertSame('POST', $request['method']);
        $this->assertSame('/v1/test/payments/pFQ3xV8aN2rL7kTm9sB4cWd6hJ1yZ0eU/simulate', $request['uri']);
        $this->assertSame('application/json', $request['headers']['content-type']);
        $this->assertSame('{"status":"paid"}', $request['body']);
    }

    public function testUsageRetrieve(): void
    {
        $usage = [
            'plan' => 'free',
            'limit' => 100,
            'used' => 3,
            'remaining' => 97,
            'period_start' => '2026-11-01T00:00:00.000Z',
            'period_end' => '2026-12-01T00:00:00.000Z',
        ];
        $this->server->respondJson(200, $usage);

        $this->assertSame($usage, $this->client()->usage->retrieve());
        $request = $this->server->lastRequest();
        $this->assertSame('GET', $request['method']);
        $this->assertSame('/v1/usage', $request['uri']);
    }

    public function testBaseUrlWithATrailingSlashAndAPath(): void
    {
        $this->server->respondJson(200, ['plan' => 'free']);

        $client = new Client(self::KEY, ['base_url' => $this->server->baseUrl() . '/proxy/']);
        $client->usage->retrieve();

        $this->assertSame('/proxy/v1/usage', $this->server->lastRequest()['uri']);
    }

    public function testRedirectsAreNotFollowed(): void
    {
        $this->server->respond(302, '', ['Location' => 'http://127.0.0.1:1/elsewhere']);

        try {
            $this->client()->usage->retrieve();
            $this->fail('Expected a KHQRPayError');
        } catch (KHQRPayError $e) {
            $this->assertSame('invalid_response', $e->getErrorCode());
            $this->assertSame(302, $e->getStatus());
        }
    }

    public function testConnectionFailureGivesNetworkError(): void
    {
        $client = new Client(self::KEY, ['base_url' => 'http://127.0.0.1:' . FakeServer::freePort(), 'timeout' => 5]);

        try {
            $client->usage->retrieve();
            $this->fail('Expected a KHQRPayError');
        } catch (KHQRPayError $e) {
            $this->assertSame('network_error', $e->getErrorCode());
            $this->assertSame(0, $e->getStatus());
            $this->assertNull($e->getRetryAfter());
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    public function testTimeoutGivesNetworkError(): void
    {
        $this->server->respond(200, '{}', ['Content-Type' => 'application/json'], 3000);
        $client = $this->client(['timeout' => 0.5]);

        $started = microtime(true);
        try {
            $client->usage->retrieve();
            $this->fail('Expected a KHQRPayError');
        } catch (KHQRPayError $e) {
            $this->assertSame('network_error', $e->getErrorCode());
            $this->assertSame(0, $e->getStatus());
        }
        $this->assertLessThan(2.5, microtime(true) - $started);
    }

    public function testCreateRejectsANonStringAmountBeforeSending(): void
    {
        $client = $this->client();

        foreach ([['amount' => 16.88], ['amount' => 20000], ['amount' => null], ['currency' => 'USD']] as $params) {
            try {
                $client->payments->create($params);
                $this->fail('Expected an InvalidArgumentException for ' . json_encode($params));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('amount', $e->getMessage());
            }
        }

        $this->assertNoRequestWasSent();
    }

    public function testUnknownOptionsAreRefused(): void
    {
        $client = $this->client();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('idempotency_key');
        $client->payments->create(['amount' => '1.00'], ['idempotencyKey' => 'k']);
    }

    public function testEmptyIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->payments->retrieve('');
    }

    /**
     * @return array<string,array{0:mixed}>
     */
    public static function badKeys(): array
    {
        return [
            'empty' => [''],
            'null' => [null],
            'false' => [false],
            'wrong prefix' => ['sk_test_123'],
            'upper case prefix' => ['KP_TEST_123'],
            'unknown mode' => ['kp_prod_123'],
            'prefix only' => ['kp_test_'],
            'leading space' => [' kp_test_123'],
            'trailing newline' => ["kp_live_123\n"],
            'webhook secret' => ['whsec_123'],
        ];
    }

    /**
     * @dataProvider badKeys
     *
     * @param mixed $key
     */
    public function testConstructionRefusesABadKey($key): void
    {
        try {
            new Client($key);
            $this->fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
            if (is_string($key) && strlen($key) > 8) {
                $this->assertStringNotContainsString($key, $e->getMessage());
            }
        }
    }

    public function testConstructionAcceptsLiveAndTestKeysAndDefaults(): void
    {
        $this->assertInstanceOf(Client::class, new Client('kp_live_abc123'));
        $this->assertInstanceOf(Client::class, new Client('kp_test_abc123'));
        $this->assertSame('0.1.0', Client::VERSION);
        $this->assertSame('khqrpay-php/0.1.0', Client::USER_AGENT);
        $this->assertSame('https://khqrpay.dev', Client::DEFAULT_BASE_URL);
        $this->assertSame(30, Client::DEFAULT_TIMEOUT);
    }

    public function testConstructionRefusesBadOptions(): void
    {
        foreach ([['baseUrl' => 'x'], ['base_url' => 'ftp://x'], ['timeout' => 0], ['timeout' => '30']] as $options) {
            try {
                new Client(self::KEY, $options);
                $this->fail('Expected an InvalidArgumentException for ' . json_encode($options));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testTheSecretKeyStaysOutOfDebugOutput(): void
    {
        $client = $this->client();

        $this->assertStringNotContainsString(self::KEY, print_r($client, true));
        ob_start();
        var_dump($client);
        $dump = (string) ob_get_clean();
        $this->assertStringNotContainsString(self::KEY, $dump);
        $this->assertStringContainsString('[redacted]', $dump);
    }

    private function assertNoRequestWasSent(): void
    {
        $sent = true;
        try {
            $this->server->lastRequest();
        } catch (\RuntimeException $e) {
            $sent = false;
        }
        $this->assertFalse($sent, 'A request reached the server although local validation failed.');
    }
}
