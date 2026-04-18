<?php

declare(strict_types=1);

namespace CorgSpace\HmacHttpClient\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class FrozenClock implements ClockInterface
{
    public function __construct(private int $time) {}

    public function now(): DateTimeImmutable
    {
        return (new DateTimeImmutable)->setTimestamp($this->time);
    }

    public function advance(int $seconds): void
    {
        $this->time += $seconds;
    }
}
