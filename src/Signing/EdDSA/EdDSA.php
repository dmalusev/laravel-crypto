<?php

declare(strict_types=1);

namespace BrosSquad\LaravelCrypto\Signing\EdDSA;

use BrosSquad\LaravelCrypto\Contracts\PublicKeySigning;
use BrosSquad\LaravelCrypto\Keys\Loader;
use BrosSquad\LaravelCrypto\Signing\Traits\Signing;
use BrosSquad\LaravelCrypto\Support\Base64;
final class EdDSA implements PublicKeySigning
{
    use Signing;

    public function __construct(
        private readonly Loader $loader,
    ) {
    }

    public function signRaw(string $data): string
    {
        [, $private] = $this->loader->getKey();
        return sodium_crypto_sign_detached($data, $private);
    }

    public function verify(string $message, string $hmac, bool $decodeSignature = true): bool
    {
        [$public] = $this->loader->getKey();
        return sodium_crypto_sign_verify_detached(
            !$decodeSignature ? $hmac : Base64::urlDecode($hmac),
            $message,
            $public
        );
    }
}
