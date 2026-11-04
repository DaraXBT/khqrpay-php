<?php

namespace KHQRPay;

/**
 * The one error type the SDK throws for failed API calls and bad webhooks.
 *
 * Branch on getErrorCode(), never on getMessage(): codes are stable,
 * messages are for people and may change.
 *
 * - getErrorCode(): the API's `error` field, such as "invalid_request" or
 *   "rate_limited"; "network_error" when KHQRPay couldn't be reached;
 *   "invalid_response" when the body wasn't the JSON we expected;
 *   "invalid_signature" from Webhook::constructEvent().
 * - getStatus(): the HTTP status, or 0 when no response arrived.
 * - getRetryAfter(): seconds from the Retry-After header, or null.
 *
 * getCode() returns the same number as getStatus().
 */
class KHQRPayError extends \RuntimeException
{
    /** @var string */
    private $errorCode;

    /** @var int */
    private $status;

    /** @var int|null */
    private $retryAfter;

    public function __construct(
        string $errorCode,
        string $message,
        int $status = 0,
        ?int $retryAfter = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $status, $previous);
        $this->errorCode = $errorCode;
        $this->status = $status;
        $this->retryAfter = $retryAfter;
    }

    /**
     * The stable error code, for example "invalid_request".
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * The HTTP status, or 0 for transport failures.
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Seconds to wait before retrying, from the Retry-After header, or null.
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
