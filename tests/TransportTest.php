<?php

namespace KHQRPay\Tests;

use KHQRPay\Client;
use KHQRPay\KHQRPayError;
use PHPUnit\Framework\TestCase;

/** The transport option: a host's own HTTP layer (WordPress's wp_remote_request) instead of ext-curl. */
final class TransportTest extends TestCase
{
    /** @var array<int,array<string,mixed>> */
    private $calls = [];

    private function client(array $answer): Client
    {
        $calls = &$this->calls;
        $transport = static function ($method, $url, $headers, $body, $timeout) use (&$calls, $answer) {
            $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout];
            if (isset($answer['throw'])) {
                throw new \RuntimeException($answer['throw']);
            }

            return $answer;
        };

        return new Client('kp_test_example0000000000000000', ['transport' => $transport, 'timeout' => 12]);
    }

    public function testSendsTheSameRequestAsCurlWould(): void
    {
        $vectors = json_decode((string) file_get_contents(__DIR__ . '/test-vectors.json'), true);
        $client = $this->client(['status' => 201, 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode($vectors['paymentResponse'])]);
        $payment = $client->payments->create($vectors['createRequest']['body'], ['idempotency_key' => 'order-1-attempt-1']);

        $this->assertSame($vectors['paymentResponse'], $payment);
        $call = $this->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://khqrpay.dev/v1/payments', $call['url']);
        $this->assertSame('Bearer kp_test_example0000000000000000', $call['headers']['Authorization']);
        $this->assertSame('application/json', $call['headers']['Content-Type']);
        $this->assertSame('order-1-attempt-1', $call['headers']['Idempotency-Key']);
        $this->assertSame(Client::USER_AGENT, $call['headers']['User-Agent']);
        $this->assertArrayNotHasKey('Expect', $call['headers']);
        $this->assertSame($vectors['createRequest']['body'], json_decode($call['body'], true));
        $this->assertSame(12.0, $call['timeout']);
    }

    public function testMapsApiErrorsAndRetryAfterWhateverTheHeaderCase(): void
    {
        $client = $this->client(['status' => 429, 'headers' => ['Retry-After' => '30'], 'body' => '{"error":"rate_limited","message":"Too many requests"}']);
        try {
            $client->usage->retrieve();
            $this->fail('expected an error');
        } catch (KHQRPayError $e) {
            $this->assertSame('rate_limited', $e->getErrorCode());
            $this->assertSame(429, $e->getStatus());
            $this->assertSame(30, $e->getRetryAfter());
        }
    }

    public function testReportsATransportFailureAsANetworkError(): void
    {
        $client = $this->client(['throw' => 'cURL error 28: timed out']);
        try {
            $client->usage->retrieve();
            $this->fail('expected an error');
        } catch (KHQRPayError $e) {
            $this->assertSame('network_error', $e->getErrorCode());
            $this->assertSame(0, $e->getStatus());
        }
    }

    public function testRefusesARedirectFromTheTransport(): void
    {
        $client = $this->client(['status' => 302, 'headers' => ['Location' => 'https://evil.test/'], 'body' => '']);
        try {
            $client->usage->retrieve();
            $this->fail('expected an error');
        } catch (KHQRPayError $e) {
            $this->assertSame('invalid_response', $e->getErrorCode());
            $this->assertSame(302, $e->getStatus());
        }
    }

    public function testRefusesATransportThatIsNotCallable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client('kp_test_example0000000000000000', ['transport' => 'not a function at all']);
    }
}
