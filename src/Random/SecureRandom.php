<?php
declare(strict_types=1);
namespace Semhoun\SlimSession\Random;
final class SecureRandom implements RandomSource
{
    public function bytes(int $length): string { return random_bytes($length); }
}
