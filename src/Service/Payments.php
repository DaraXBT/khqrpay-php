<?php

namespace KHQRPay\Service;

use KHQRPay\Internal\HttpClient;
use KHQRPay\KHQRPayError;

/**
 * Create, retrieve, list and simulate payments. Reach it as $client->payments.
 */
final class Payments
{
    /** @var HttpClient */
    private $http;

    /**
     * @internal Built by KHQRPay\Client.
     */
    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * Creates a payment: POST /v1/payments.
     *
     * The params are sent as JSON exactly as given. "amount" is required and
     * must be a string ("16.88", "20000"), never a float.
     *
     *     $payment = $client->payments->create([
     *         'amount' => '16.88',
     *         'currency' => 'USD',
     *         'reference_id' => 'INV-2048',
     *         'metadata' => ['cart_id' => 'c_1'],
     *     ], ['idempotency_key' => 'order-2048']);
     *
     * Options:
     * - idempotency_key (string): sent as the Idempotency-Key header. When you
     *   leave it out, a random UUID v4 is generated. To retry safely, pass the
     *   same key again.
     *
     * @param array<string,mixed> $params  amount, currency, reference_id, metadata.
     * @param array<string,mixed> $options idempotency_key.
     *
     * @return array<string,mixed> The payment.
     *
     * @throws KHQRPayError              When the API answers with an error or can't be reached.
     * @throws \InvalidArgumentException When the params or options are malformed.
     */
    public function create(array $params, array $options = []): array
    {
        self::rejectUnknownOptions($options, ['idempotency_key'], 'create');

        if (!array_key_exists('amount', $params)) {
            throw new \InvalidArgumentException('create() needs an "amount", as a string such as "16.88".');
        }
        if (!is_string($params['amount'])) {
            throw new \InvalidArgumentException(
                'The "amount" must be a string such as "16.88" or "20000", not '
                . gettype($params['amount']) . '. Floats lose precision.'
            );
        }

        if (array_key_exists('idempotency_key', $options) && $options['idempotency_key'] !== null) {
            $idempotencyKey = $options['idempotency_key'];
        } elseif (isset($params['idempotency_key']) && is_string($params['idempotency_key']) && $params['idempotency_key'] !== '') {
            // The API lets the body carry the key too, but the header wins.
            // Reuse the caller's key so a generated one never overrides it.
            $idempotencyKey = $params['idempotency_key'];
        } else {
            $idempotencyKey = self::uuid4();
        }

        if (!is_string($idempotencyKey) || $idempotencyKey === '') {
            throw new \InvalidArgumentException('The "idempotency_key" option must be a non-empty string.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $idempotencyKey)) {
            throw new \InvalidArgumentException('The "idempotency_key" option must not contain control characters.');
        }

        // An empty PHP array would encode as [], but metadata is a JSON object.
        if (array_key_exists('metadata', $params) && $params['metadata'] === []) {
            $params['metadata'] = new \stdClass();
        }

        return $this->http->request(
            'POST',
            '/v1/payments',
            [],
            self::encode($params),
            ['Idempotency-Key' => $idempotencyKey]
        );
    }

    /**
     * Retrieves a payment with every field: GET /v1/payments/{id}.
     *
     * @return array<string,mixed> The payment.
     *
     * @throws KHQRPayError
     * @throws \InvalidArgumentException When the id is empty.
     */
    public function retrieve(string $id): array
    {
        self::requireId($id);

        return $this->http->request('GET', '/v1/payments/' . rawurlencode($id));
    }

    /**
     * Lists payments, newest first: GET /v1/payments.
     *
     *     $page = $client->payments->list(['status' => 'paid', 'limit' => 50]);
     *     // ['object' => 'list', 'data' => [...], 'has_more' => true, 'next_cursor' => '...']
     *
     * Unset or null params are left out of the query string.
     *
     * @param array<string,mixed> $params status, limit (1 to 100), cursor.
     *
     * @return array<string,mixed> object, data, has_more, next_cursor.
     *
     * @throws KHQRPayError
     * @throws \InvalidArgumentException When a param is unknown or not a string or int.
     */
    public function list(array $params = []): array
    {
        self::rejectUnknownOptions($params, ['status', 'limit', 'cursor'], 'list');

        $query = [];
        foreach (['status', 'limit', 'cursor'] as $name) {
            if (!isset($params[$name])) {
                continue;
            }
            $value = $params[$name];
            if (!is_string($value) && !is_int($value)) {
                throw new \InvalidArgumentException('The "' . $name . '" param must be a string or an int.');
            }
            $query[$name] = (string) $value;
        }

        return $this->http->request('GET', '/v1/payments', $query);
    }

    /**
     * Moves a test payment to a status and sends its webhook:
     * POST /v1/test/payments/{id}/simulate. Test keys (kp_test_) only.
     *
     * @param string $status "scanned", "paid", "expired" or "failed".
     *
     * @return array<string,mixed> The payment after the change.
     *
     * @throws KHQRPayError
     * @throws \InvalidArgumentException When the id or status is empty.
     */
    public function simulate(string $id, string $status): array
    {
        self::requireId($id);
        if ($status === '') {
            throw new \InvalidArgumentException('simulate() needs a status: "scanned", "paid", "expired" or "failed".');
        }

        return $this->http->request(
            'POST',
            '/v1/test/payments/' . rawurlencode($id) . '/simulate',
            [],
            self::encode(['status' => $status])
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function encode(array $data): string
    {
        try {
            return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('The params can not be encoded as JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    private static function requireId(string $id): void
    {
        if ($id === '') {
            throw new \InvalidArgumentException('A payment id is required.');
        }
    }

    /**
     * @param array<string,mixed> $given
     * @param string[]            $allowed
     */
    private static function rejectUnknownOptions(array $given, array $allowed, string $method): void
    {
        foreach (array_keys($given) as $name) {
            if (!in_array($name, $allowed, true)) {
                throw new \InvalidArgumentException(
                    'Unknown ' . $method . '() option "' . $name . '". Use: ' . implode(', ', $allowed) . '.'
                );
            }
        }
    }

    /**
     * A random RFC 4122 version 4 UUID.
     */
    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
