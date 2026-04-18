<?php

declare(strict_types=1);

namespace CorgSpace\HmacHttpClient\Exceptions;

use CorgSpace\HmacHttpClient\SignatureHeaders;
use RuntimeException;

final class MissingIdempotencyKeyException extends RuntimeException
{
    public static function create(): self
    {
        $header = SignatureHeaders::IDEMPOTENCY_KEY;

        return new self(
            "$header header must be set by the caller before signing. "
            ."Use ->withHeaders(['$header' => \$id]) on the HTTP client."
        );
    }
}
