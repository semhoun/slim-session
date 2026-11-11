<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Http;

use Psr\Http\Message\ServerRequestInterface;
use Semhoun\SlimSession\SessionContext;

final class SessionGuard
{
    public const ATTRIBUTE = SessionContext::class;
    public const ADMISSION_ATTRIBUTE = AdmissionMetadata::class;

    public static function admission(ServerRequestInterface $request): ?AdmissionMetadata
    {
        self::context($request);
        $metadata = $request->getAttribute(self::ADMISSION_ATTRIBUTE);
        return $metadata instanceof AdmissionMetadata ? $metadata : null;
    }

    public static function context(ServerRequestInterface $request): SessionContext
    {
        $context = $request->getAttribute(self::ATTRIBUTE);
        if (!$context instanceof SessionContext) {
            throw new \LogicException('Session middleware is missing or incorrectly ordered.');
        }
        return $context;
    }

    public static function requireSession(ServerRequestInterface $request): SessionContext
    {
        $context = self::context($request);
        if ($context->record() === null || $context->state() === \Semhoun\SlimSession\SessionState::Destroyed) {
            throw new HttpSessionException(401, 'Authentication required.');
        }
        return $context;
    }
}
