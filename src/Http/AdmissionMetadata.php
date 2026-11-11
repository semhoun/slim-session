<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Http;

/** Public details of the incoming credential, populated by the middleware after authentication. */
final readonly class AdmissionMetadata
{
    public function __construct(
        public string $transport,
        public int $expiresAt,
    ) {}
}
