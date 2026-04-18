<?php

declare(strict_types=1);

namespace CorgSpace\HmacHttpClient\Tests;

use CorgSpace\HmacHttpClient\CanonicalString;
use CorgSpace\HmacHttpClient\HmacHttpClientServiceProvider;
use CorgSpace\HmacHttpClient\SecretEncoding;
use CorgSpace\HmacHttpClient\SignatureHeaders;
use CorgSpace\HmacHttpClient\Signer;
use CorgSpace\HmacHttpClient\Tests\Support\FrozenClock;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\RequestInterface;

final class HmacMacroTest extends TestCase
{
    private const SECRET_B64 = 'dGVzdHNlY3JldGJ5dGVzLXRlc3RzZWNyZXRieXRlcyE=';

    private const KEY_ID = 'test-key-id';

    private const BASE_URL = 'https://api.example.test';

    private const FIXED_TIME = 1734567890;

    protected function getPackageProviders($app): array
    {
        return [HmacHttpClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('hmac-http-client.services.test_service', [
            'base_url' => self::BASE_URL,
            'key_id' => self::KEY_ID,
            'secret' => self::SECRET_B64,
            'secret_encoding' => SecretEncoding::Base64->value,
        ]);

        $app->instance(ClockInterface::class, new FrozenClock(self::FIXED_TIME));
    }

    public function test_missing_service_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("HMAC service 'does_not_exist': not configured");

        Http::hmac('does_not_exist');
    }

    public function test_service_missing_required_key_throws(): void
    {
        config()->set('hmac-http-client.services.broken', [
            'base_url' => self::BASE_URL,
            'key_id' => self::KEY_ID,
            // missing 'secret'
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("HMAC service 'broken': missing required config key 'secret'");

        Http::hmac('broken');
    }

    public function test_unknown_encoding_throws(): void
    {
        config()->set('hmac-http-client.services.weird_encoding', [
            'base_url' => self::BASE_URL,
            'key_id' => self::KEY_ID,
            'secret' => str_repeat('x', 32),
            'secret_encoding' => 'ascii85',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("HMAC service 'weird_encoding': unknown secret_encoding 'ascii85'");

        Http::hmac('weird_encoding');
    }

    private function expectedSignature(RequestInterface $r): string
    {
        return Signer::fromEncoded(self::SECRET_B64, SecretEncoding::Base64)->sign(CanonicalString::build(
            method: $r->getMethod(),
            requestTarget: $r->getRequestTarget(),
            timestamp: $r->getHeaderLine(SignatureHeaders::TIMESTAMP),
            nonce: $r->getHeaderLine(SignatureHeaders::NONCE),
            idempotencyKey: $r->getHeaderLine(SignatureHeaders::IDEMPOTENCY_KEY),
            body: (string) $r->getBody(),
        ));
    }

    /**
     * @param  array<int, RequestInterface>  $captured
     */
    private function captureInto(array &$captured, ?callable $responder = null): callable
    {
        return function (callable $handler) use (&$captured, $responder) {
            return function (RequestInterface $request, array $options) use (&$captured, $responder) {
                $captured[] = $request;
                $response = $responder !== null
                    ? $responder(count($captured))
                    : new PsrResponse(200, [], '{}');

                return Create::promiseFor($response);
            };
        };
    }

    public function test_end_to_end_signing_and_headers(): void
    {
        $captured = [];

        $response = Http::hmac('test_service')
            ->withMiddleware($this->captureInto($captured))
            ->withHeaders([SignatureHeaders::IDEMPOTENCY_KEY => 'evt_test_xyz'])
            ->post('/v1/licenses', ['external_ref' => 'sub_1', 'source' => 'direct']);

        $this->assertTrue($response->successful());
        $this->assertCount(1, $captured);

        $request = $captured[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('api.example.test', $request->getUri()->getHost());
        $this->assertSame('/v1/licenses', $request->getUri()->getPath());
        $this->assertSame('{"external_ref":"sub_1","source":"direct"}', (string) $request->getBody());

        $this->assertSame(self::KEY_ID, $request->getHeaderLine(SignatureHeaders::KEY_ID));
        $this->assertSame((string) self::FIXED_TIME, $request->getHeaderLine(SignatureHeaders::TIMESTAMP));
        $this->assertSame('evt_test_xyz', $request->getHeaderLine(SignatureHeaders::IDEMPOTENCY_KEY));
        $this->assertNotEmpty($request->getHeaderLine(SignatureHeaders::NONCE));
        $this->assertNotEmpty($request->getHeaderLine(SignatureHeaders::SIGNATURE));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $this->assertSame($this->expectedSignature($request), $request->getHeaderLine(SignatureHeaders::SIGNATURE));
    }

    public function test_query_string_round_trips_into_signature(): void
    {
        $captured = [];

        Http::hmac('test_service')
            ->withMiddleware($this->captureInto($captured))
            ->withHeaders([SignatureHeaders::IDEMPOTENCY_KEY => 'k1'])
            ->get('/v1/search', ['q' => 'widget', 'sort' => 'asc']);

        $request = $captured[0];
        $this->assertSame('/v1/search?q=widget&sort=asc', $request->getRequestTarget());

        $this->assertSame($this->expectedSignature($request), $request->getHeaderLine(SignatureHeaders::SIGNATURE));
    }

    public function test_retry_sends_fresh_nonce_per_attempt_but_stable_idempotency_key(): void
    {
        $captured = [];
        $responder = fn (int $attempt): PsrResponse => $attempt === 1
            ? new PsrResponse(500)
            : new PsrResponse(200, [], '{"ok":true}');

        $response = Http::hmac('test_service')
            ->withMiddleware($this->captureInto($captured, $responder))
            ->withHeaders([SignatureHeaders::IDEMPOTENCY_KEY => 'stable-key'])
            ->retry(2, 0, throw: false)
            ->post('/foo', ['x' => 1]);

        $this->assertTrue($response->successful());
        $this->assertCount(2, $captured, 'expected retry to send the request twice');

        $this->assertSame('stable-key', $captured[0]->getHeaderLine(SignatureHeaders::IDEMPOTENCY_KEY));
        $this->assertSame('stable-key', $captured[1]->getHeaderLine(SignatureHeaders::IDEMPOTENCY_KEY));

        $this->assertNotEmpty($captured[0]->getHeaderLine(SignatureHeaders::NONCE));
        $this->assertNotEmpty($captured[1]->getHeaderLine(SignatureHeaders::NONCE));
        $this->assertNotSame(
            $captured[0]->getHeaderLine(SignatureHeaders::NONCE),
            $captured[1]->getHeaderLine(SignatureHeaders::NONCE),
            'retry must produce a fresh nonce',
        );

        $this->assertNotSame(
            $captured[0]->getHeaderLine(SignatureHeaders::SIGNATURE),
            $captured[1]->getHeaderLine(SignatureHeaders::SIGNATURE),
            'fresh nonce must produce a different signature',
        );
    }

    public function test_base_url_is_applied(): void
    {
        $captured = [];

        Http::hmac('test_service')
            ->withMiddleware($this->captureInto($captured))
            ->withHeaders([SignatureHeaders::IDEMPOTENCY_KEY => 'k1'])
            ->get('/ping');

        $this->assertSame('api.example.test', $captured[0]->getUri()->getHost());
        $this->assertSame('/ping', $captured[0]->getUri()->getPath());
    }
}
