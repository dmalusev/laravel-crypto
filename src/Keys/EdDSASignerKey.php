<?php

declare(strict_types=1);

namespace CodeLieutenant\LaravelCrypto\Keys;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Encryption\MissingAppKeyException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SplFileObject;

class EdDSASignerKey implements Loader, Generator
{
    public const KEY_LENGTH = SODIUM_CRYPTO_SIGN_KEYPAIRBYTES;
    public const PUBLIC_KEY_LENGTH = SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
    public const PRIVATE_KEY_LENGTH = SODIUM_CRYPTO_SIGN_SECRETKEYBYTES;

    private const CONFIG_KEY_PATH = 'crypto.signing.keys.eddsa';

    private static string $privateKey;
    private static string $publicKey;

    public function __construct(
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function init(Repository $config, LoggerInterface $logger): static
    {
        if (!isset(static::$publicKey, static::$privateKey)) {
            $path = $config->get(static::CONFIG_KEY_PATH);

            if ($path === null) {
                throw new MissingAppKeyException('File for EdDSA signer is not set');
            }

            [static::$publicKey, static::$privateKey] = static::parseKeys($path, $logger);
        }

        return new static($config, $logger);
    }

    protected static function parseKeys(string $keyPath, LoggerInterface $logger): array
    {
        $file = new SplFileObject($keyPath, 'rb');
        if ($file->flock(LOCK_SH) === false) {
            throw new RuntimeException('Error while locking file (shared/reading)');
        }

        try {
            $keys = $file->fread(self::KEY_LENGTH * 2 + 1);

            if ($keys === false) {
                throw new RuntimeException('Error while reading key');
            }
        } finally {
            if ($file->flock(LOCK_UN) === false) {
                $logger->warning('Error while unlocking file');
            }
        }

        [$publicKey, $privateKey] = explode(PHP_EOL, $keys, 2);

        return [hex2bin($publicKey), hex2bin($privateKey)];
    }

    public function getKey(): string|array
    {
        return [self::$publicKey, self::$privateKey];
    }

    public function generate(?string $write): ?string
    {
        $keyPair = sodium_crypto_sign_keypair();
        $privateKey = bin2hex(sodium_crypto_sign_secretkey($keyPair));
        $publicKey = bin2hex(sodium_crypto_sign_publickey($keyPair));

        $key = implode(PHP_EOL, [$publicKey, $privateKey]);

        if ($write === null) {
            return $key;
        }

        $path = $this->config->get(self::CONFIG_KEY_PATH);

        if ($path === null) {
            throw new RuntimeException('File for EdDSA signer is not set');
        }

        if (!@file_exists($concurrentDirectory = dirname($path)) && !@mkdir(
                $concurrentDirectory,
                0740,
                true
            ) && !is_dir(
                $concurrentDirectory
            )) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
        }

        $file = new SplFileObject($path, 'wb');

        if ($file->flock(LOCK_EX) === false) {
            throw new RuntimeException('Error while locking file (exclusive/writing)');
        }

        try {
            if ($file->fwrite($key) === false) {
                throw new RuntimeException('Error while writing public key to file');
            }
        } finally {
            if ($file->flock(LOCK_UN) === false) {
                $this->logger->warning('Error while unlocking file');
            }

            sodium_memzero($privateKey);
            sodium_memzero($publicKey);
            sodium_memzero($keyPair);
            sodium_memzero($key);
        }

        return null;
    }
}