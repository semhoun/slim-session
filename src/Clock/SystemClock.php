<?php
declare(strict_types=1);
namespace Semhoun\SlimSession\Clock;
use Psr\Clock\ClockInterface;
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable { return new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
}
