<?php

namespace UpfluentSf;

defined('ABSPATH') || exit;

/**
 * A ShipFusion call that did not succeed. The message is plain language for
 * the store owner; code is the HTTP status (0 when the request never got an
 * answer).
 *
 * kinds:
 *   auth       the token was refused (401/403): every call will fail until it is fixed
 *   data       ShipFusion refused this request's content (4xx): retrying won't help
 *   temporary  network, rate limit or server error: try again later
 *   unsure     a create may or may not have gone through (timeout or 5xx on POST)
 */
class ApiException extends \RuntimeException
{
    /** @var string */
    private $kind;

    public function __construct(string $message, int $code = 0, string $kind = 'temporary')
    {
        parent::__construct($message, $code);
        $this->kind = $kind;
    }

    /**
     * The message as plain text. Messages are HTML-escaped when thrown (WordPress
     * coding standard), so they are decoded here and escaped again where shown.
     */
    public function text(): string
    {
        return html_entity_decode($this->getMessage(), ENT_QUOTES, 'UTF-8');
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function isAuth(): bool
    {
        return $this->kind === 'auth';
    }

    public function isTemporary(): bool
    {
        return in_array($this->kind, ['temporary', 'unsure'], true);
    }
}
