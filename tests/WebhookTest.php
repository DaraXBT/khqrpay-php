<?php

namespace KHQRPay\Tests;

use KHQRPay\KHQRPayError;
use KHQRPay\Webhook;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private static function vectors(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/test-vectors.json'), true);
    }

    /**
     * Every case in test-vectors.json, named by its "name".
     *
     * @return array<string,array{0:array<string,mixed>,1:string}>
     */
    public static function cases(): array
    {
        $vectors = self::vectors();
        $cases = [];
        foreach ($vectors['cases'] as $case) {
            $secret = isset($case['secret']) ? $case['secret'] : $vectors['secret'];
            $cases[$case['name']] = [$case, $secret];
        }

        return $cases;
    }

    public function testEveryVectorIsCovered(): void
    {
        $this->assertCount(12, self::cases());
    }

    /**
     * @dataProvider cases
     *
     * @param array<string,mixed> $case
     */
    public function testVerify(array $case, string $secret): void
    {
        $this->assertSame(
            $case['valid'],
            Webhook::verify($case['body'], $case['header'], $secret, 300, $case['now'])
        );
        // The default tolerance is 300 seconds.
        $this->assertSame(
            $case['valid'],
            Webhook::verify($case['body'], $case['header'], $secret, Webhook::DEFAULT_TOLERANCE, $case['now'])
        );
    }

    /**
     * @dataProvider cases
     *
     * @param array<string,mixed> $case
     */
    public function testConstructEvent(array $case, string $secret): void
    {
        if ($case['valid']) {
            $event = Webhook::constructEvent($case['body'], $case['header'], $secret, 300, $case['now']);
            $this->assertSame(json_decode($case['body'], true), $event);

            return;
        }

        try {
            Webhook::constructEvent($case['body'], $case['header'], $secret, 300, $case['now']);
            $this->fail('Expected a KHQRPayError for "' . $case['name'] . '"');
        } catch (KHQRPayError $e) {
            $this->assertSame('invalid_signature', $e->getErrorCode());
            $this->assertSame(400, $e->getStatus());
            $this->assertNull($e->getRetryAfter());
            $this->assertStringNotContainsString($secret, $e->getMessage());
        }
    }

    public function testConstructEventReturnsTheParsedPayment(): void
    {
        $vectors = self::vectors();
        $case = $vectors['cases'][0];

        $event = Webhook::constructEvent($case['body'], $case['header'], $vectors['secret'], 300, $case['now']);

        $this->assertSame('payment.completed', $event['type']);
        $this->assertSame('16.88', $event['data']['payment']['amount']);
        $this->assertSame(['cart_id' => 'c_1'], $event['data']['payment']['metadata']);
    }

    public function testUnicodeBodyIsHashedAsUtf8Bytes(): void
    {
        $vectors = self::vectors();
        $case = $vectors['cases'][11];
        $this->assertSame('unicode body', $case['name']);

        $event = Webhook::constructEvent($case['body'], $case['header'], $vectors['secret'], 300, $case['now']);
        $this->assertSame('ការទូទាត់ ✓', $event['note']);
    }

    public function testHexIsCaseInsensitive(): void
    {
        $vectors = self::vectors();
        $case = $vectors['cases'][0];
        $upper = 't=1793750400,v1=' . strtoupper(substr($case['header'], strlen('t=1793750400,v1=')));

        $this->assertTrue(Webhook::verify($case['body'], $upper, $vectors['secret'], 300, $case['now']));
    }

    public function testToleranceIsAdjustable(): void
    {
        $vectors = self::vectors();
        $case = $vectors['cases'][2];
        $this->assertSame('timestamp 6 minutes old', $case['name']);

        $this->assertFalse(Webhook::verify($case['body'], $case['header'], $vectors['secret'], 300, $case['now']));
        $this->assertTrue(Webhook::verify($case['body'], $case['header'], $vectors['secret'], 600, $case['now']));
    }

    public function testNowDefaultsToTheClock(): void
    {
        $secret = 'whsec_clock_test';
        $body = '{"id":"evt_now","type":"payment.scanned"}';
        $t = (string) time();
        $header = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret);

        $this->assertTrue(Webhook::verify($body, $header, $secret));
        $this->assertSame('evt_now', Webhook::constructEvent($body, $header, $secret)['id']);

        $old = (string) (time() - 3600);
        $oldHeader = 't=' . $old . ',v1=' . hash_hmac('sha256', $old . '.' . $body, $secret);
        $this->assertFalse(Webhook::verify($body, $oldHeader, $secret));
    }

    /**
     * @return array<string,array{0:mixed,1:mixed,2:mixed,3:mixed,4:mixed}>
     */
    public static function junk(): array
    {
        $vectors = self::vectors();
        $case = $vectors['cases'][0];
        $body = $case['body'];
        $header = $case['header'];
        $secret = $vectors['secret'];
        $now = $case['now'];

        return [
            'null header' => [$body, null, $secret, 300, $now],
            'array header' => [$body, [$header], $secret, 300, $now],
            'null body' => [null, $header, $secret, 300, $now],
            'array body' => [json_decode($body, true), $header, $secret, 300, $now],
            'null secret' => [$body, $header, null, 300, $now],
            'false secret' => [$body, $header, false, 300, $now],
            'empty secret' => [$body, $header, '', 300, $now],
            'string tolerance' => [$body, $header, $secret, 'lots', $now],
            'negative tolerance' => [$body, $header, $secret, -1, $now],
            'string now' => [$body, $header, $secret, 300, 'now'],
            'only commas' => [$body, ',,,', $secret, 300, $now],
            'empty values' => [$body, 't=,v1=', $secret, 300, $now],
            'non-integer t' => [$body, str_replace('t=1793750400', 't=1793750400.5', $header), $secret, 300, $now],
            'trailing newline on v1' => [$body, $header . "\n", $secret, 300, $now],
            'v1 too long' => [$body, $header . '00', $secret, 300, $now],
        ];
    }

    /**
     * @dataProvider junk
     *
     * @param mixed $body
     * @param mixed $header
     * @param mixed $secret
     * @param mixed $tolerance
     * @param mixed $now
     */
    public function testVerifyNeverThrows($body, $header, $secret, $tolerance, $now): void
    {
        $this->assertFalse(Webhook::verify($body, $header, $secret, $tolerance, $now));
    }

    public function testASignedBodyThatIsNotJsonIsRefused(): void
    {
        $secret = 'whsec_not_json';
        $body = 'not json';
        $header = 't=1793750400,v1=' . hash_hmac('sha256', '1793750400.' . $body, $secret);

        $this->assertTrue(Webhook::verify($body, $header, $secret, 300, 1793750400));

        try {
            Webhook::constructEvent($body, $header, $secret, 300, 1793750400);
            $this->fail('Expected a KHQRPayError');
        } catch (KHQRPayError $e) {
            $this->assertSame('invalid_payload', $e->getErrorCode());
            $this->assertSame(400, $e->getStatus());
        }
    }
}
