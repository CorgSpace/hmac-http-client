<?php

declare(strict_types=1);

namespace CorgSpace\HmacHttpClient\Tests\Support;

final class Fixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function knownSignature(string $key): array
    {
        static $data;
        $data ??= require __DIR__.'/../Fixtures/known-signatures.php';

        return $data[$key];
    }
}
