<?php

declare(strict_types=1);

namespace CorgSpace\HmacHttpClient\Tests;

use CorgSpace\HmacHttpClient\SecretEncoding;
use CorgSpace\HmacHttpClient\Signer;
use CorgSpace\HmacHttpClient\Tests\Support\Fixtures;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase
{
    public function test_signs_known_canonical_to_locked_signature_base64_secret(): void
    {
        $fx = Fixtures::knownSignature('simple_post');
        $signer = Signer::fromEncoded($fx['secret_base64'], SecretEncoding::Base64);

        $this->assertSame($fx['expected_signature'], $signer->sign($fx['expected_canonical']));
    }

    public function test_signs_known_canonical_to_locked_signature_hex_secret(): void
    {
        $fx = Fixtures::knownSignature('simple_post');
        $signer = Signer::fromEncoded($fx['secret_hex'], SecretEncoding::Hex);

        $this->assertSame($fx['expected_signature'], $signer->sign($fx['expected_canonical']));
    }

    public function test_signs_empty_body_vector(): void
    {
        $fx = Fixtures::knownSignature('empty_body_get');
        $signer = Signer::fromEncoded($fx['secret_base64'], SecretEncoding::Base64);

        $this->assertSame($fx['expected_signature'], $signer->sign($fx['expected_canonical']));
    }

    public function test_raw_encoding_uses_bytes_as_is(): void
    {
        $secret = 'testsecretbytes-testsecretbytes!';
        $signer = Signer::fromEncoded($secret, SecretEncoding::Raw);

        $viaRaw = $signer->sign('hello');
        $expected = base64_encode(hash_hmac('sha256', 'hello', $secret, true));

        $this->assertSame($expected, $viaRaw);
    }

    public function test_invalid_base64_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid base64-encoded secret.');

        Signer::fromEncoded('not valid base64!!!', SecretEncoding::Base64);
    }

    public function test_invalid_hex_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid hex-encoded secret.');

        Signer::fromEncoded('zzzz', SecretEncoding::Hex);
    }

    public function test_empty_base64_is_rejected_by_length_check(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 32 bytes');

        Signer::fromEncoded('', SecretEncoding::Base64);
    }

    public function test_secret_shorter_than_32_bytes_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 32 bytes');

        Signer::fromEncoded(str_repeat('x', 20), SecretEncoding::Raw);
    }

    public function test_secret_exactly_32_bytes_is_accepted(): void
    {
        $signer = Signer::fromEncoded(str_repeat('x', 32), SecretEncoding::Raw);

        $this->assertSame(
            base64_encode(hash_hmac('sha256', 'hi', str_repeat('x', 32), true)),
            $signer->sign('hi'),
        );
    }
}
