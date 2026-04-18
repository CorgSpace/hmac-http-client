<?php

declare(strict_types=1);

namespace CorgSpace\HmacHttpClient\Tests;

use Closure;
use CorgSpace\HmacHttpClient\CanonicalString;
use CorgSpace\HmacHttpClient\Exceptions\MissingIdempotencyKeyException;
use CorgSpace\HmacHttpClient\SecretEncoding;
use CorgSpace\HmacHttpClient\SignatureHeaders;
use CorgSpace\HmacHttpClient\SignedRequestMiddleware;
use CorgSpace\HmacHttpClient\Signer;
use CorgSpace\HmacHttpClient\Tests\Support\Fixtures;
use CorgSpace\HmacHttpClient\Tests\Support\FrozenClock;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class SignedRequestMiddlewareTest extends TestCase
{
    private const SECRET = 'testsecretbytes-testsecretbytes!';

    private const KEY_ID = 'test-key-id';

    private const NONCE = '01HQZZZ0000000000000000001';

    private const TIMESTAMP = 1734567890;

    private function buildMiddleware(?Signer $signer = null, ?int $time = null, ?Closure $nonceFactory = null): SignedRequestMiddleware
    {
        return new SignedRequestMiddleware(
            keyId: self::KEY_ID,
            signer: $signer ?? Signer::fromEncoded(self::SECRET, SecretEncoding::Raw),
            clock: new FrozenClock($time ?? self::TIMESTAMP),
            nonceFactory: $nonceFactory ?? static fn (): string => self::NONCE,
        );
    }

    private function expectedSignature(RequestInterface $r): string
    {
        return Signer::fromEncoded(self::SECRET, SecretEncoding::Raw)->sign(CanonicalString::build(
            method: $r->getMethod(),
            requestTarget: $r->getRequestTarget(),
            timestamp: $r->getHeaderLine(SignatureHeaders::TIMESTAMP),
            nonce: $r->getHeaderLine(SignatureHeaders::NONCE),
            idempotencyKey: $r->getHeaderLine(SignatureHeaders::IDEMPOTENCY_KEY),
            body: (string) $r->getBody(),
        ));
    }

    /**
     * Invokes the middleware with a capturing handler and returns the request
     * that reached the handler after signing.
     */
    private function runAndCapture(SignedRequestMiddleware $middleware, RequestInterface $request): RequestInterface
    {
        $captured = null;
        $handler = function (RequestInterface $request, array $options) use (&$captured) {
            $captured = $request;

            return Create::promiseFor(new Response(200));
        };

        ($middleware($handler))($request, [])->wait();

        return $captured;
    }

    public function test_all_four_signing_headers_are_added(): void
    {
        $request = new Request(
            'POST',
            'https://example.test/v1/licenses',
            [SignatureHeaders::IDEMPOTENCY_KEY => 'evt_test_abc'],
            '{"external_ref":"sub_1","source":"direct"}'
        );

        $out = $this->runAndCapture($this->buildMiddleware(), $request);

        $this->assertSame(self::KEY_ID, $out->getHeaderLine(SignatureHeaders::KEY_ID));
        $this->assertSame((string) self::TIMESTAMP, $out->getHeaderLine(SignatureHeaders::TIMESTAMP));
        $this->assertSame(self::NONCE, $out->getHeaderLine(SignatureHeaders::NONCE));
        $this->assertNotEmpty($out->getHeaderLine(SignatureHeaders::SIGNATURE));
        $this->assertSame('evt_test_abc', $out->getHeaderLine(SignatureHeaders::IDEMPOTENCY_KEY));
    }

    public function test_signature_matches_locked_fixture(): void
    {
        $fx = Fixtures::knownSignature('simple_post');

        $middleware = $this->buildMiddleware(
            signer: Signer::fromEncoded($fx['secret_base64'], SecretEncoding::Base64),
            time: (int) $fx['timestamp'],
            nonceFactory: static fn (): string => $fx['nonce'],
        );

        $request = new Request(
            $fx['method'],
            'https://example.test'.$fx['request_target'],
            [SignatureHeaders::IDEMPOTENCY_KEY => $fx['idempotency_key']],
            $fx['body']
        );

        $out = $this->runAndCapture($middleware, $request);

        $this->assertSame($fx['expected_signature'], $out->getHeaderLine(SignatureHeaders::SIGNATURE));
    }

    public function test_body_stream_is_readable_after_signing(): void
    {
        $request = new Request(
            'POST',
            'https://example.test/foo',
            [SignatureHeaders::IDEMPOTENCY_KEY => 'k1'],
            '{"a":1}'
        );

        $out = $this->runAndCapture($this->buildMiddleware(), $request);

        $this->assertSame('{"a":1}', (string) $out->getBody());
        $out->getBody()->rewind();
        $this->assertSame('{"a":1}', $out->getBody()->getContents());
    }

    public function test_missing_idempotency_key_throws(): void
    {
        $this->expectException(MissingIdempotencyKeyException::class);
        $this->expectExceptionMessage('X-Idempotency-Key header must be set by the caller');

        $request = new Request('GET', 'https://example.test/foo');

        $this->runAndCapture($this->buildMiddleware(), $request);
    }

    public function test_empty_idempotency_key_header_throws(): void
    {
        $this->expectException(MissingIdempotencyKeyException::class);

        $request = new Request('GET', 'https://example.test/foo', [SignatureHeaders::IDEMPOTENCY_KEY => '']);

        $this->runAndCapture($this->buildMiddleware(), $request);
    }

    public function test_empty_path_is_normalized_to_slash(): void
    {
        $request = new Request('GET', 'https://example.test', [SignatureHeaders::IDEMPOTENCY_KEY => 'k1']);
        $this->assertSame('', $request->getUri()->getPath(), 'precondition: empty path');
        $this->assertSame('/', $request->getRequestTarget(), 'precondition: PSR-7 normalizes empty target to /');

        $out = $this->runAndCapture($this->buildMiddleware(), $request);

        $this->assertSame($this->expectedSignature($out), $out->getHeaderLine(SignatureHeaders::SIGNATURE));
    }

    public function test_query_string_is_included_in_signature(): void
    {
        $request = new Request(
            'GET',
            'https://example.test/v1/search?q=widget&sort=asc',
            [SignatureHeaders::IDEMPOTENCY_KEY => 'k1']
        );
        $this->assertSame('/v1/search?q=widget&sort=asc', $request->getRequestTarget(), 'precondition');

        $out = $this->runAndCapture($this->buildMiddleware(), $request);

        $this->assertSame($this->expectedSignature($out), $out->getHeaderLine(SignatureHeaders::SIGNATURE));
    }

    public function test_tampering_with_query_string_breaks_signature(): void
    {
        $signed = $this->runAndCapture($this->buildMiddleware(), new Request(
            'GET',
            'https://example.test/v1/search?q=widget',
            [SignatureHeaders::IDEMPOTENCY_KEY => 'k1'],
        ));

        $tampered = $this->runAndCapture($this->buildMiddleware(), new Request(
            'GET',
            'https://example.test/v1/search?q=widgets',
            [SignatureHeaders::IDEMPOTENCY_KEY => 'k1'],
        ));

        $this->assertNotSame(
            $signed->getHeaderLine(SignatureHeaders::SIGNATURE),
            $tampered->getHeaderLine(SignatureHeaders::SIGNATURE),
        );
    }

    public function test_fresh_nonce_and_timestamp_per_invocation(): void
    {
        $nonceValues = ['n1', 'n2'];
        $clock = new FrozenClock(1000);
        $middleware = new SignedRequestMiddleware(
            keyId: self::KEY_ID,
            signer: Signer::fromEncoded(self::SECRET, SecretEncoding::Raw),
            clock: $clock,
            nonceFactory: static function () use (&$nonceValues): string {
                return array_shift($nonceValues);
            },
        );

        $request = new Request('POST', 'https://example.test/foo', [SignatureHeaders::IDEMPOTENCY_KEY => 'same-key'], '{}');

        $first = $this->runAndCapture($middleware, $request);
        $clock->advance(5);
        $second = $this->runAndCapture($middleware, $request);

        $this->assertSame('n1', $first->getHeaderLine(SignatureHeaders::NONCE));
        $this->assertSame('n2', $second->getHeaderLine(SignatureHeaders::NONCE));
        $this->assertSame('1000', $first->getHeaderLine(SignatureHeaders::TIMESTAMP));
        $this->assertSame('1005', $second->getHeaderLine(SignatureHeaders::TIMESTAMP));
        $this->assertSame('same-key', $first->getHeaderLine(SignatureHeaders::IDEMPOTENCY_KEY));
        $this->assertSame('same-key', $second->getHeaderLine(SignatureHeaders::IDEMPOTENCY_KEY));
        $this->assertNotSame($first->getHeaderLine(SignatureHeaders::SIGNATURE), $second->getHeaderLine(SignatureHeaders::SIGNATURE));
    }

    public function test_non_seekable_body_is_buffered_and_replayable(): void
    {
        $payload = '{"non":"seekable"}';

        $resource = fopen('php://temp', 'w+');
        fwrite($resource, $payload);
        rewind($resource);
        $nonSeekable = new NoSeekStream(new Stream($resource));

        $request = (new Request('POST', 'https://example.test/foo', [SignatureHeaders::IDEMPOTENCY_KEY => 'k1']))
            ->withBody($nonSeekable);

        $out = $this->runAndCapture($this->buildMiddleware(), $request);

        $this->assertSame($payload, (string) $out->getBody());
        $this->assertTrue($out->getBody()->isSeekable(), 'body should be replaced with a seekable buffer');
        $out->getBody()->rewind();
        $this->assertSame($payload, $out->getBody()->getContents());
    }
}
