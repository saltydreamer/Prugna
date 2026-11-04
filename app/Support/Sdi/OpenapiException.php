<?php

namespace App\Support\Sdi;

use RuntimeException;

/**
 * A failed call to Openapi, with a message the user can read.
 */
class OpenapiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $body
     */
    public function __construct(string $message, public ?int $status = null, public ?array $body = null)
    {
        parent::__construct($message);
    }
}
