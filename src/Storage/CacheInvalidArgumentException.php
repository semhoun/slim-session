<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Storage;

final class CacheInvalidArgumentException extends \InvalidArgumentException implements \Psr\SimpleCache\InvalidArgumentException
{
}
