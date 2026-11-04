<?php

namespace KHQRPay\Internal;

use KHQRPay\Client;
use KHQRPay\KHQRPayError;

/**
 * Sends requests to the KHQRPay API over ext-curl.
 *
 * @internal Not part of the public API. Use KHQRPay\Client instead.
 */
final class HttpClient
{
    /** @var string */
    private $secretKey;

    /** @var string */
    private $baseUrl;

    /** @var float */
    private $timeout;

    public function __construct(
        #[\SensitiveParameter]
        string $secretKey,
        string $baseUrl,
        float $timeout
    ) {
        $this->secretKey = $secretKey;
        $this->baseUrl = $baseUrl;
        $this->timeout = $timeout;
    }

    /**
     * Sends one request and returns the decoded JSON object.
     *
     * @param string               $method  "GET" or "POST".
     * @param string               $path    Already URL-encoded, starting with "/".
     * @param array<string,string> $query   Query parameters; empty for none.
     * @param string|null          $body    JSON body, or null for none.
     * @param array<string,string> $headers Extra request headers.
     *
     * @return array<string,mixed>
     *
     * @throws KHQRPayError
     */
    public function request(string $method, string $path, array $query = [], ?string $body = null, array $headers = []): array
    {
        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $pairs = [];
            foreach ($query as $name => $value) {
                $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode($value);
            }
            $url .= '?' . implode('&', $pairs);
        }

        $lines = [
            'Authorization: Bearer ' . $this->secretKey,
            'Accept: application/json',
            'User-Agent: ' . Client::USER_AGENT,
            // Stop curl from waiting for "100 Continue" on larger bodies.
            'Expect:',
        ];
        if ($body !== null) {
            $lines[] = 'Content-Type: application/json';
        }
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        $ch = curl_init();
        if ($ch === false) {
            throw new KHQRPayError('network_error', 'Could not start a curl session.', 0);
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            // The key goes to the base URL only: never follow a redirect.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT_MS => (int) ceil($this->timeout * 1000),
            // Lets sub-second timeouts work without signals.
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => static function ($handle, $line) use (&$responseHeaders) {
                $length = strlen($line);
                $trimmed = trim($line);
                if (strncmp($trimmed, 'HTTP/', 5) === 0) {
                    // A new header block (after a 100 Continue, say): start over.
                    $responseHeaders = [];
                } elseif ($trimmed !== '') {
                    $parts = explode(':', $trimmed, 2);
                    if (count($parts) === 2) {
                        $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                }

                return $length;
            },
        ];

        if ($method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
        }

        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        if ($raw === false || $errno !== 0) {
            $detail = $error !== '' ? $error : 'curl error ' . $errno;

            throw new KHQRPayError('network_error', 'Could not reach KHQRPay: ' . $detail, 0);
        }

        $retryAfter = null;
        if (isset($responseHeaders['retry-after']) && preg_match('/^[0-9]{1,10}$/D', $responseHeaders['retry-after'])) {
            $retryAfter = (int) $responseHeaders['retry-after'];
        }

        $decoded = json_decode((string) $raw, true);
        $isObject = is_array($decoded);

        if ($status >= 200 && $status < 300) {
            if (!$isObject) {
                throw new KHQRPayError(
                    'invalid_response',
                    'KHQRPay answered HTTP ' . $status . ' with a body that is not a JSON object.',
                    $status,
                    $retryAfter
                );
            }

            return $decoded;
        }

        if (!$isObject) {
            throw new KHQRPayError(
                'invalid_response',
                'KHQRPay answered HTTP ' . $status . ' with a body that is not JSON.',
                $status,
                $retryAfter
            );
        }

        if (!isset($decoded['error']) || !is_string($decoded['error']) || $decoded['error'] === '') {
            throw new KHQRPayError(
                'invalid_response',
                'KHQRPay answered HTTP ' . $status . ' without an error code.',
                $status,
                $retryAfter
            );
        }

        $message = isset($decoded['message']) && is_string($decoded['message'])
            ? $decoded['message']
            : 'KHQRPay answered HTTP ' . $status . '.';

        throw new KHQRPayError($decoded['error'], $message, $status, $retryAfter);
    }

    /**
     * Keeps the secret key out of var_dump() and print_r().
     *
     * @return array<string,mixed>
     */
    public function __debugInfo()
    {
        return [
            'secretKey' => substr($this->secretKey, 0, 8) . '[redacted]',
            'baseUrl' => $this->baseUrl,
            'timeout' => $this->timeout,
        ];
    }
}
