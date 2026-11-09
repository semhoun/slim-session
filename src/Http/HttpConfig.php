<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Http;

final readonly class HttpConfig
{
    /** @param list<string> $allowedOrigins */
    public function __construct(
        public string $transport,
        public string $canonicalOrigin,
        public array $allowedOrigins = [],
        public string $cookieName = '__Host-slim-session',
        public string $sameSite = 'Lax',
        public string $credentialHeader = 'Authorization',
        public string $responseTokenHeader = 'X-Session-Token',
        public string $csrfHeader = 'X-CSRF-Token',
        public bool $csrfRequired = true,
        public bool $originRequired = true,
    ) {
        if (!in_array($transport, ['cookie', 'header'], true) || !in_array($sameSite, ['Lax', 'Strict', 'None'], true)
            || !preg_match('/^__Host-[A-Za-z0-9_-]+$/D', $cookieName)) {
            throw new \InvalidArgumentException('Invalid HTTP session configuration.');
        }
        foreach ([$canonicalOrigin, ...$allowedOrigins] as $origin) {
            $parts = parse_url($origin);
            if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new \InvalidArgumentException('Origins must be explicit scheme/host/port origins.');
            }
        }
        foreach ([$credentialHeader, $responseTokenHeader, $csrfHeader] as $header) {
            if (!preg_match('/^[A-Za-z0-9-]+$/D', $header)) {
                throw new \InvalidArgumentException('Invalid session header name.');
            }
        }
        if ($sameSite === 'None' && $allowedOrigins === []) {
            throw new \InvalidArgumentException('SameSite=None requires explicit authorized origins.');
        }
    }
}
