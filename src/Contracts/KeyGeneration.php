<?php

declare(strict_types=1);

namespace BrosSquad\LaravelCrypto\Contracts;

interface KeyGeneration
{
    /**
     * Create a new encryption key.
     * This key is used to encrypt data.
     * It is recommended to use a key with 32 bytes (256 bits)
     *
     * @return string
     */
    public static function generateKey(): string;
}
