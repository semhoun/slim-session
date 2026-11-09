<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Http;

final class HttpSessionException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
