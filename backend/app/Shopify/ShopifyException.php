<?php

namespace App\Shopify;

use RuntimeException;

final class ShopifyException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly bool $retryable = false)
    {
        parent::__construct($errorCode); // Never expose provider responses or credentials.
    }
}
