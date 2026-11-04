<?php

namespace KHQRPay\Service;

use KHQRPay\Internal\HttpClient;
use KHQRPay\KHQRPayError;

/**
 * Your plan's quota. Reach it as $client->usage.
 */
final class Usage
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
     * GET /v1/usage.
     *
     * @return array<string,mixed> plan, limit, used, remaining, period_start, period_end.
     *
     * @throws KHQRPayError
     */
    public function retrieve(): array
    {
        return $this->http->request('GET', '/v1/usage');
    }
}
