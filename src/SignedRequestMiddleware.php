<?php

declare(strict_types=1);

namespace CorgSpace\HmacHttpClient;

use Closure;
use CorgSpace\HmacHttpClient\Exceptions\MissingIdempotencyKeyException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Str;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\RequestInterface;

final readonly class SignedRequestMiddleware
{
    private Closure $nonceFactory;

    /**
     * @param  (Closure(): string)|null  $nonceFactory
     */
    public function __construct(
        private string $keyId,
        private Signer $signer,
        private ClockInterface $clock,
        ?Closure $nonceFactory = null,
    ) {
        $this->nonceFactory = $nonceFactory ?? static fn (): string => (string) Str::ulid();
    }

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            return $handler($this->sign($request), $options);
        };
    }

    private function sign(RequestInterface $request): RequestInterface
    {
        $idempotencyKey = $request->getHeaderLine(SignatureHeaders::IDEMPOTENCY_KEY);
        if ($idempotencyKey === '') {
            throw MissingIdempotencyKeyException::create();
        }

        $timestamp = (string) $this->clock->now()->getTimestamp();
        $nonce = ($this->nonceFactory)();

        [$request, $body] = $this->readBody($request);

        $canonical = CanonicalString::build(
            method: $request->getMethod(),
            requestTarget: $request->getRequestTarget(),
            timestamp: $timestamp,
            nonce: $nonce,
            idempotencyKey: $idempotencyKey,
            body: $body,
        );

        $signature = $this->signer->sign($canonical);

        return $request
            ->withHeader(SignatureHeaders::KEY_ID, $this->keyId)
            ->withHeader(SignatureHeaders::TIMESTAMP, $timestamp)
            ->withHeader(SignatureHeaders::NONCE, $nonce)
            ->withHeader(SignatureHeaders::SIGNATURE, $signature);
    }

    /**
     * Read the request body as a string. If the body is seekable, rewind it
     * in place for downstream consumers; if it isn't, buffer into a fresh
     * in-memory stream and swap it onto the request.
     *
     * @return array{0: RequestInterface, 1: string}
     */
    private function readBody(RequestInterface $request): array
    {
        $stream = $request->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
            $body = Utils::copyToString($stream);
            $stream->rewind();

            return [$request, $body];
        }

        $body = Utils::copyToString($stream);

        return [$request->withBody(Utils::streamFor($body)), $body];
    }
}
