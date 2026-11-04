<?php

namespace KHQRPay;

use KHQRPay\Internal\HttpClient;
use KHQRPay\Service\Payments;
use KHQRPay\Service\Usage;

/**
 * The KHQRPay API client.
 *
 *     $khqrpay = new \KHQRPay\Client(getenv('KHQRPAY_SECRET_KEY'));
 *     $payment = $khqrpay->payments->create(['amount' => '16.88', 'currency' => 'USD']);
 *     header('Location: ' . $payment['checkout_url']);
 *
 * Options:
 * - base_url (string): defaults to https://khqrpay.dev.
 * - timeout (int|float): seconds per request, defaults to 30.
 *
 * The SDK never retries by itself. Every call returns the decoded JSON as an
 * associative array, or throws KHQRPayError.
 */
final class Client
{
    public const VERSION = '0.1.0';

    public const USER_AGENT = 'khqrpay-php/0.1.0';

    public const DEFAULT_BASE_URL = 'https://khqrpay.dev';

    public const DEFAULT_TIMEOUT = 30;

    /** @var Payments Create, retrieve, list and simulate payments. */
    public $payments;

    /** @var Usage Your plan's quota. */
    public $usage;

    /**
     * @param string               $secretKey A kp_live_ or kp_test_ secret key. Keep it on your server.
     * @param array<string,mixed>  $options   base_url, timeout.
     *
     * @throws \InvalidArgumentException When the key or an option is invalid.
     */
    public function __construct(
        #[\SensitiveParameter]
        $secretKey,
        array $options = []
    ) {
        if (!is_string($secretKey) || $secretKey === '') {
            throw new \InvalidArgumentException(
                'A KHQRPay secret key is required. Copy a kp_live_ or kp_test_ key from your dashboard.'
            );
        }
        if (strncmp($secretKey, 'kp_live_', 8) !== 0 && strncmp($secretKey, 'kp_test_', 8) !== 0) {
            throw new \InvalidArgumentException('A KHQRPay secret key starts with kp_live_ or kp_test_.');
        }
        if (strlen($secretKey) === 8) {
            throw new \InvalidArgumentException('The KHQRPay secret key is only a prefix. Copy the whole key.');
        }
        if (preg_match('/[\s\x00-\x1F\x7F]/', $secretKey)) {
            throw new \InvalidArgumentException(
                'The KHQRPay secret key contains whitespace or control characters. Trim it.'
            );
        }

        foreach (array_keys($options) as $name) {
            if ($name !== 'base_url' && $name !== 'timeout') {
                throw new \InvalidArgumentException('Unknown option "' . $name . '". Use: base_url, timeout.');
            }
        }

        $baseUrl = self::DEFAULT_BASE_URL;
        if (isset($options['base_url'])) {
            $baseUrl = $options['base_url'];
            if (!is_string($baseUrl) || !preg_match('#^https?://[^/\s]+#i', $baseUrl)) {
                throw new \InvalidArgumentException('The "base_url" option must be an http:// or https:// URL.');
            }
            $baseUrl = rtrim($baseUrl, '/');
        }

        $timeout = self::DEFAULT_TIMEOUT;
        if (isset($options['timeout'])) {
            $timeout = $options['timeout'];
            if ((!is_int($timeout) && !is_float($timeout)) || $timeout <= 0) {
                throw new \InvalidArgumentException('The "timeout" option must be a positive number of seconds.');
            }
        }

        $http = new HttpClient($secretKey, $baseUrl, (float) $timeout);
        $this->payments = new Payments($http);
        $this->usage = new Usage($http);
    }
}
