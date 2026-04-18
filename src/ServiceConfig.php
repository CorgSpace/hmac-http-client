<?php

declare(strict_types=1);

namespace CorgSpace\HmacHttpClient;

use InvalidArgumentException;

final readonly class ServiceConfig
{
    private function __construct(
        public string $baseUrl,
        public string $keyId,
        public Signer $signer,
    ) {}

    public static function forService(string $service): self
    {
        $config = config("hmac-http-client.services.$service");

        if (! is_array($config)) {
            throw new InvalidArgumentException(
                "HMAC service '$service': not configured. Check config/hmac-http-client.php."
            );
        }

        return new self(
            baseUrl: self::requireString($service, $config, 'base_url'),
            keyId: self::requireString($service, $config, 'key_id'),
            signer: Signer::fromEncoded(
                self::requireString($service, $config, 'secret'),
                self::encoding($service, $config),
            ),
        );
    }

    /**
     * @param  array<mixed>  $config
     */
    private static function encoding(string $service, array $config): SecretEncoding
    {
        $encodingString = $config['secret_encoding'] ?? SecretEncoding::Base64->value;

        if (! is_string($encodingString) || $encodingString === '') {
            throw new InvalidArgumentException(
                "HMAC service '$service': invalid secret_encoding."
            );
        }

        return SecretEncoding::tryFrom($encodingString)
            ?? throw new InvalidArgumentException(
                "HMAC service '$service': unknown secret_encoding '$encodingString'. Expected one of: "
                .implode(', ', array_map(fn (SecretEncoding $c): string => "'$c->value'", SecretEncoding::cases()))
                .'.'
            );
    }

    /**
     * @param  array<mixed>  $config
     */
    private static function requireString(string $service, array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException(
                "HMAC service '$service': missing required config key '$key'."
            );
        }

        return $value;
    }
}
