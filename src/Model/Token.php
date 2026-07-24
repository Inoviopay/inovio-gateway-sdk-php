<?php

declare(strict_types=1);

namespace Inovio\Gateway\Model;

use InvalidArgumentException;

/** Single-use ephemeral token from the token service -> TOKEN_GUID. */
final class Token implements PaymentMethod
{
    private string $guid;

    /** @internal use PaymentMethods::token() */
    public function __construct(string $guid)
    {
        if ($guid === '') {
            throw new InvalidArgumentException('token guid is required');
        }
        $this->guid = $guid;
    }

    public function kind(): string
    {
        return 'token';
    }

    public function guid(): string
    {
        return $this->guid;
    }
}
