<?php

declare(strict_types=1);

namespace CorgSpace\HmacHttpClient;

enum SecretEncoding: string
{
    case Raw = 'raw';
    case Hex = 'hex';
    case Base64 = 'base64';
}
