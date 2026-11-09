<?php
declare(strict_types=1);
namespace Semhoun\SlimSession;
final readonly class SessionResponseMetadata
{
    public function __construct(public ?SessionRecord $record, public bool $issueToken, public bool $destroyed = false) {}
}
