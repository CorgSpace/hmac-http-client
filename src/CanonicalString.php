<?php

declare(strict_types=1);

namespace CorgSpace\HmacHttpClient;

final class CanonicalString
{
    /**
     * Format is pinned by the locked test vectors in tests/Fixtures/known-signatures.php;
     * changing it is a breaking wire-format change.
     */
    public static function build(
        string $method,
        string $requestTarget,
        string $timestamp,
        string $nonce,
        string $idempotencyKey,
        string $body,
    ): string {
        return implode("\n", [
            strtoupper($method),
            $requestTarget,
            $timestamp,
            $nonce,
            $idempotencyKey,
            hash('sha256', $body),
        ]);
    }
}
