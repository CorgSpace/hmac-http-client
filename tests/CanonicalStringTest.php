<?php

declare(strict_types=1);

namespace CorgSpace\HmacHttpClient\Tests;

use CorgSpace\HmacHttpClient\CanonicalString;
use CorgSpace\HmacHttpClient\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class CanonicalStringTest extends TestCase
{
    public function test_matches_fixture_for_simple_post(): void
    {
        $fx = Fixtures::knownSignature('simple_post');

        $actual = CanonicalString::build(
            method: $fx['method'],
            requestTarget: $fx['request_target'],
            timestamp: $fx['timestamp'],
            nonce: $fx['nonce'],
            idempotencyKey: $fx['idempotency_key'],
            body: $fx['body'],
        );

        $this->assertSame($fx['expected_canonical'], $actual);
    }

    public function test_empty_body_uses_known_sha256(): void
    {
        $result = CanonicalString::build(
            method: 'GET',
            requestTarget: '/foo',
            timestamp: '1',
            nonce: 'n',
            idempotencyKey: 'k',
            body: '',
        );

        $this->assertStringEndsWith(
            "\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
            $result
        );
    }

    public function test_method_is_uppercased(): void
    {
        $result = CanonicalString::build(
            method: 'post',
            requestTarget: '/x',
            timestamp: '1',
            nonce: 'n',
            idempotencyKey: 'k',
            body: '',
        );

        $this->assertStringStartsWith("POST\n", $result);
    }

    public function test_separators_are_exactly_five_lf_no_trailing_newline_no_crlf(): void
    {
        $result = CanonicalString::build(
            method: 'GET',
            requestTarget: '/x',
            timestamp: '1',
            nonce: 'n',
            idempotencyKey: 'k',
            body: '',
        );

        $this->assertStringEndsNotWith("\n", $result);
        $this->assertStringNotContainsString("\r", $result);
        $this->assertSame(5, substr_count($result, "\n"), 'canonical must have exactly 5 LF separators');
    }

    public function test_field_order_is_method_target_ts_nonce_idempotency_bodyhash(): void
    {
        $result = CanonicalString::build(
            method: 'PATCH',
            requestTarget: '/a',
            timestamp: '123',
            nonce: 'NONCE',
            idempotencyKey: 'IDK',
            body: 'hi',
        );

        $expected = "PATCH\n/a\n123\nNONCE\nIDK\n".hash('sha256', 'hi');
        $this->assertSame($expected, $result);
    }

    public function test_request_target_including_query_is_signed_verbatim(): void
    {
        $result = CanonicalString::build(
            method: 'GET',
            requestTarget: '/v1/search?q=widget&sort=asc',
            timestamp: '1',
            nonce: 'n',
            idempotencyKey: 'k',
            body: '',
        );

        $this->assertStringContainsString("\n/v1/search?q=widget&sort=asc\n", $result);
    }
}
