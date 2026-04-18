# Changelog

All notable changes to `corgspace/hmac-http-client` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-04-17
### Added
- `Http::hmac('service')` macro that produces a signed `PendingRequest` with `acceptJson()->asJson()` applied.
- HMAC-SHA256 canonical-string signing over `{METHOD}\n{REQUEST_TARGET}\n{TIMESTAMP}\n{NONCE}\n{IDEMPOTENCY_KEY}\n{hex(sha256(BODY))}`, where `REQUEST_TARGET` is the full PSR-7 request target (path + query string).
- Headers added to every outgoing request: `X-Key-Id`, `X-Timestamp`, `X-Nonce`, `X-Signature`.
- Required caller-supplied header: `X-Idempotency-Key`. Missing or empty raises `MissingIdempotencyKeyException`.
- Secret encodings: `base64` (default), `hex`, `raw`. 32-byte minimum enforced after decoding.
- PSR-20 `Psr\Clock\ClockInterface` binding with a default `SystemClock`; tests can override via the container for frozen-time control.
- Locked test vectors in `tests/Fixtures/known-signatures.php` that protect the on-the-wire signing spec.

[Unreleased]: https://github.com/CorgSpace/hmac-http-client/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/CorgSpace/hmac-http-client/releases/tag/v0.1.0
