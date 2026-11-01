<?php

namespace App\Brain\Exceptions;

use RuntimeException;

class BrainException extends RuntimeException
{
    public static function provider(string $provider, string $message): self
    {
        return new self("[{$provider}] {$message}");
    }
}
