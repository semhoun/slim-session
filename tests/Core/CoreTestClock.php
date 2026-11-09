<?php
declare(strict_types=1);
namespace Semhoun\SlimSession\Tests\Core;
use Psr\Clock\ClockInterface;
final class CoreTestClock implements ClockInterface
{
    public function __construct(public float $time = 1000.0) {}
    public function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $this->time))->setTimezone(new \DateTimeZone('UTC'));
    }
}
