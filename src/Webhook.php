<?php

namespace KHQRPay;

/**
 * Verifies KHQRPay webhooks. No API key needed.
 *
 * IMPORTANT: pass the raw request body, the exact bytes you received, BEFORE
 * any JSON parsing. A body that was decoded and encoded again has different
 * bytes and fails the check.
 *
 *     $event = \KHQRPay\Webhook::constructEvent(
 *         file_get_contents('php://input'),
 *         $_SERVER['HTTP_X_KHQRPAY_SIGNATURE'] ?? '',
 *         getenv('KHQRPAY_WEBHOOK_SECRET')
 *     );
 *
 * The signature header is "t=<unix seconds>,v1=<hex>", where v1 is the
 * HMAC-SHA256 of "<t>.<raw body>" keyed with the whole whsec_ secret.
 */
final class Webhook
{
    /** Seconds a signature's timestamp may differ from now. */
    public const DEFAULT_TOLERANCE = 300;

    private function __construct()
    {
    }

    /**
     * Checks a webhook signature. Never throws: anything wrong returns false.
     *
     * @param string   $rawBody         The exact request body, before json_decode().
     * @param string   $signatureHeader The X-KHQRPay-Signature header.
     * @param string   $secret          The endpoint's signing secret, whsec_...
     * @param int      $toleranceSec    Allowed clock difference in seconds, default 300.
     * @param int|null $now             Current Unix time in seconds; null for time().
     */
    public static function verify(
        $rawBody,
        $signatureHeader,
        #[\SensitiveParameter]
        $secret,
        $toleranceSec = self::DEFAULT_TOLERANCE,
        $now = null
    ): bool {
        if (!is_string($rawBody) || !is_string($secret) || $secret === '') {
            return false;
        }

        // 1. The header is missing or empty.
        if (!is_string($signatureHeader) || $signatureHeader === '') {
            return false;
        }

        $fields = [];
        foreach (explode(',', $signatureHeader) as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) === 2) {
                $fields[$pair[0]] = $pair[1];
            }
        }

        // 2. t is missing or not an integer.
        if (!isset($fields['t']) || !preg_match('/^[0-9]+$/D', $fields['t'])) {
            return false;
        }

        // 3. v1 is missing or not 64 hex characters.
        if (!isset($fields['v1']) || !preg_match('/^[0-9a-fA-F]{64}$/D', $fields['v1'])) {
            return false;
        }

        // 4. The timestamp is outside the tolerance.
        if ((!is_int($toleranceSec) && !is_float($toleranceSec)) || $toleranceSec < 0) {
            return false;
        }
        if ($now === null) {
            $now = time();
        } elseif (!is_int($now) && !is_float($now)) {
            return false;
        }
        if (abs($now - (int) $fields['t']) > $toleranceSec) {
            return false;
        }

        $expected = hash_hmac('sha256', $fields['t'] . '.' . $rawBody, $secret);

        return hash_equals($expected, strtolower($fields['v1']));
    }

    /**
     * Verifies the signature, then returns the parsed event.
     *
     * @param string   $rawBody         The exact request body, before json_decode().
     * @param string   $signatureHeader The X-KHQRPay-Signature header.
     * @param string   $secret          The endpoint's signing secret, whsec_...
     * @param int      $toleranceSec    Allowed clock difference in seconds, default 300.
     * @param int|null $now             Current Unix time in seconds; null for time().
     *
     * @return array<string,mixed> The event: id, type, livemode, created, data.
     *
     * @throws KHQRPayError "invalid_signature" (status 400) when the check fails,
     *                      "invalid_payload" (status 400) when a signed body is not a JSON object.
     */
    public static function constructEvent(
        $rawBody,
        $signatureHeader,
        #[\SensitiveParameter]
        $secret,
        $toleranceSec = self::DEFAULT_TOLERANCE,
        $now = null
    ): array {
        if (!self::verify($rawBody, $signatureHeader, $secret, $toleranceSec, $now)) {
            throw new KHQRPayError(
                'invalid_signature',
                'The webhook signature is invalid or too old. Check the signing secret, and verify the raw body before parsing it.',
                400
            );
        }

        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            throw new KHQRPayError('invalid_payload', 'The webhook body is signed but is not a JSON object.', 400);
        }

        return $event;
    }
}
