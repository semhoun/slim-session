<?php
declare(strict_types=1);
namespace Semhoun\SlimSession\Random;
interface RandomSource
{
    public function bytes(int $length): string;
}
