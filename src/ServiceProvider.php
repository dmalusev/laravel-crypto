<?php

declare(strict_types=1);

namespace CodeLieutenant\LaravelCrypto;

use CodeLieutenant\LaravelCrypto\Console\GenerateCryptoKeysCommand;
use CodeLieutenant\LaravelCrypto\Contracts\Hashing;
use CodeLieutenant\LaravelCrypto\Contracts\PublicKeySigning;
use CodeLieutenant\LaravelCrypto\Contracts\Signing;
use CodeLieutenant\LaravelCrypto\Encoder\IgbinaryEncoder;
use CodeLieutenant\LaravelCrypto\Encoder\JsonEncoder;
use CodeLieutenant\LaravelCrypto\Encoder\MessagePackEncoder;
use CodeLieutenant\LaravelCrypto\Encoder\PhpEncoder;
use CodeLieutenant\LaravelCrypto\Encryption\AesGcm256Encryptor;
use CodeLieutenant\LaravelCrypto\Encryption\Encryption;
use CodeLieutenant\LaravelCrypto\Encryption\XChaCha20Poly1305Encryptor;
use CodeLieutenant\LaravelCrypto\Hashing\Blake2b;
use CodeLieutenant\LaravelCrypto\Hashing\HashingManager;
use CodeLieutenant\LaravelCrypto\Hashing\Sha256;
use CodeLieutenant\LaravelCrypto\Hashing\Sha512;
use CodeLieutenant\LaravelCrypto\Keys\AppKey;
use CodeLieutenant\LaravelCrypto\Keys\Blake2bHashingKey;
use CodeLieutenant\LaravelCrypto\Keys\EdDSASignerKey;
use CodeLieutenant\LaravelCrypto\Keys\Generators\AppKeyGenerator;
use CodeLieutenant\LaravelCrypto\Keys\Generators\Blake2bHashingKeyGenerator;
use CodeLieutenant\LaravelCrypto\Keys\Generators\EdDSASignerKeyGenerator;
use CodeLieutenant\LaravelCrypto\Keys\Generators\HmacKeyGenerator;
use CodeLieutenant\LaravelCrypto\Keys\HmacKey;
use CodeLieutenant\LaravelCrypto\Keys\Loader;
use CodeLieutenant\LaravelCrypto\Signing\EdDSA\EdDSA;
use CodeLieutenant\LaravelCrypto\Signing\Hmac\Blake2b as HmacBlake2b;
use CodeLieutenant\LaravelCrypto\Signing\Hmac\Sha256 as HmacSha256;
use CodeLieutenant\LaravelCrypto\Signing\Hmac\Sha512 as HmacSha512;
use CodeLieutenant\LaravelCrypto\Signing\SigningManager;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Encryption\Encrypter as LaravelConcreteEncrypter;
use Illuminate\Encryption\EncryptionServiceProvider;
use Psr\Log\LoggerInterface;

class ServiceProvider extends EncryptionServiceProvider
{

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([$this->getConfigPath() => config_path('crypto.php')]);
        }
    }

    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->registerGenerators();
            $this->commands([GenerateCryptoKeysCommand::class]);
        }

        $this->mergeConfigFrom($this->getConfigPath(), 'crypto');

        $this->registerEncoder();
        $this->registerKeyLoaders();
        $this->registerSigners();
        $this->registerHashers();
        parent::register();
    }

    protected function registerEncoder(): void
    {
        $encoders = [
            PhpEncoder::class,
            JsonEncoder::class,
            MessagePackEncoder::class,
            IgbinaryEncoder::class,
        ];

        foreach ($encoders as $encoder) {
            $this->app->singleton($encoder, function (Application $app) use ($encoder) {
                $config = $app->make(Repository::class)->get('crypto.encoder.config.' . $encoder);
                return new $encoder(...$config);
            });
        }

        $this->app->singleton(
            Encoder\Encoder::class,
            $this->app->make(Repository::class)->get('crypto.encoder.driver')
        );
    }

    protected function registerKeyLoaders(): void
    {
        $this->app->singleton(
            AppKey::class,
            fn(Application $app) => AppKey::make($app->make(Repository::class))
        );
        $this->app->singleton(
            Blake2bHashingKey::class,
            fn(Application $app) => Blake2bHashingKey::make($app->make(Repository::class))
        );

        $this->app->singleton(
            HmacKey::class,
            fn(Application $app) => HmacKey::make($app->make(Repository::class))
        );

        $this->app->singleton(
            EdDSASignerKey::class,
            fn(Application $app) => EdDSASignerKey::make(
                $app->make(Repository::class),
                $app->make(LoggerInterface::class)
            )
        );
    }

    protected function registerSigners(): void
    {
        $this->app->singleton(SigningManager::class);

        $this->app->when(EdDSA::class)
            ->needs(Loader::class)
            ->give(EdDSASignerKey::class);

        $hmacSigners = [
            HmacBlake2b::class,
            HmacSha256::class,
            HmacSha512::class,
        ];

        foreach ($hmacSigners as $signer) {
            $this->app->singleton($signer, function (Application $app) use ($signer) {
                $config = $app->make(Repository::class)->get('crypto.signing.config.' . $signer);
                $keyLoader = $app->make(HmacKey::class);

                return $config !== null ? new $signer($keyLoader, $config) : new $signer($keyLoader);
            });
        }

        $this->app->singleton(Signing::class, static function (Application $app) {
            return $app->make($app->make(Repository::class)->get('crypto.signing.driver'));
        });

        $this->app->singleton(PublicKeySigning::class, EdDSA::class);
    }

    protected function registerHashers(): void
    {
        $hashers = [
            Blake2b::class,
            Sha256::class,
            Sha512::class,
        ];

        foreach ($hashers as $hasher) {
            $this->app->singleton($hasher, static function (Application $app) use ($hasher) {
                $params = $app->make(Repository::class)->get('crypto.hashing.config.' . $hasher);

                return $params === null ? new $hasher() : new $hasher(...$params);
            });
        }

        $this->app->singleton(Hashing::class, static function (Application $app) {
            return $app->make($app->make(Repository::class)->get('crypto.hashing.driver'));
        });

        $this->app->singleton(HashingManager::class);
    }

    protected function getConfigPath(): string
    {
        return __DIR__ . '/../config/crypto.php';
    }

    protected function registerEncrypter(): void
    {
        foreach ([AesGcm256Encryptor::class, XChaCha20Poly1305Encryptor::class] as $encryptor) {
            $this->app->singleton($encryptor);
            $this->app->when($encryptor)
                ->needs(Loader::class)
                ->give(AppKey::class);
        }

        $func = static function (Application $app) {
            $cipher = $app->make('config')->get('app.cipher');

            $enc = Encryption::tryFrom($cipher);

            if ($enc === null) {
                return new LaravelConcreteEncrypter($app->make(AppKey::class)->getKey(), $cipher);
            }

            return match ($enc) {
                Encryption::SodiumAES256GCM => $app->make(AesGcm256Encryptor::class),
                Encryption::SodiumXChaCha20Poly1305 => $app->make(XChaCha20Poly1305Encryptor::class),
            };
        };

        $this->app->singleton(Encrypter::class, $func);
        $this->app->singleton('encrypter', $func);
    }

    protected function registerGenerators(): void
    {
        $this->app->singleton(AppKeyGenerator::class);
        $this->app->singleton(Blake2bHashingKeyGenerator::class);
        $this->app->singleton(HmacKeyGenerator::class);
        $this->app->singleton(EdDSASignerKeyGenerator::class);
    }
}
