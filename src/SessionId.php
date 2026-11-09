<?php
declare(strict_types=1);
namespace Semhoun\SlimSession;

use Semhoun\SlimSession\Random\RandomSource;

final class SessionId
{
    public static function valid(string $id): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $id) === 1;
    }

    public static function generate(RandomSource $random): string
    {
        $bytes = $random->bytes(16);
        if (strlen($bytes) !== 16) { throw new \RuntimeException('Random source returned an invalid length.'); }
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
