<?php

declare(strict_types=1);

namespace CorgSpace\HmacHttpClient;

use InvalidArgumentException;

final readonly class Signer
{
    private const MIN_SECRET_BYTES = 32;

    private function __construct(
        private string $secretBytes,
    ) {
        if (strlen($this->secretBytes) < self::MIN_SECRET_BYTES) {
            throw new InvalidArgumentException(
                'HMAC secret must be at least '.self::MIN_SECRET_BYTES.' bytes after decoding; got '.strlen($this->secretBytes).'.'
            );
        }
    }

    public static function fromEncoded(string $secret, SecretEncoding $encoding): self
    {
        $bytes = match ($encoding) {
            SecretEncoding::Raw => $secret,
            SecretEncoding::Hex => self::decodeHex($secret),
            SecretEncoding::Base64 => self::decodeBase64($secret),
        };

        return new self($bytes);
    }

    public function sign(string $canonicalString): string
    {
        return base64_encode(hash_hmac('sha256', $canonicalString, $this->secretBytes, true));
    }

    private static function decodeHex(string $secret): string
    {
        $decoded = @hex2bin($secret);
        if ($decoded === false) {
            throw new InvalidArgumentException('Invalid hex-encoded secret.');
        }

        return $decoded;
    }

    private static function decodeBase64(string $secret): string
    {
        $decoded = base64_decode($secret, true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Invalid base64-encoded secret.');
        }

        return $decoded;
    }
}
