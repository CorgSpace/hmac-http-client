<?php

declare(strict_types=1);

namespace CorgSpace\HmacHttpClient;

final class SignatureHeaders
{
    public const KEY_ID = 'X-Key-Id';

    public const TIMESTAMP = 'X-Timestamp';

    public const NONCE = 'X-Nonce';

    public const SIGNATURE = 'X-Signature';

    public const IDEMPOTENCY_KEY = 'X-Idempotency-Key';
}
