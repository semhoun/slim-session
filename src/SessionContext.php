<?php
declare(strict_types=1);
namespace Semhoun\SlimSession;

use Psr\Clock\ClockInterface;
use Semhoun\SlimSession\Data\JsonData;
use Semhoun\SlimSession\Exception\InvalidSessionStateException;
use Semhoun\SlimSession\Exception\WriteConflictException;
use Semhoun\SlimSession\Random\RandomSource;
use Semhoun\SlimSession\Storage\SessionStore;

final class SessionContext
{
    private SessionState $state;
    private JsonData $json;
    private ?SessionResponseMetadata $pending = null;
    private bool $issueToken = false;
    /** @var array<string, mixed> */
    private array $data = [];
    /** @var array<string, mixed> */
    private array $flash = [];
    private int $activityAt = 0;

    public function __construct(
        private readonly SessionStore $store,
        private readonly SessionConfig $config,
        private readonly ClockInterface $clock,
        private readonly RandomSource $random,
        private ?SessionRecord $record = null,
        private ?Lease $lease = null,
        bool $activity = false,
    ) {
        $this->json = new JsonData($config->maxDataBytes, $config->maxDataDepth);
        $this->state = $record === null ? SessionState::Absent : SessionState::Open;
        if ($record !== null) {
            if ($lease === null) {
                throw new InvalidSessionStateException('An open session requires a lease.');
            }
            $this->json->validate($record->data, $record->flash);
            $this->data = $this->json->copy($record->data);
            $this->flash = $this->json->copy($record->flash);
            $this->activityAt = $activity ? max($record->lastActivityAt, $clock->now()->getTimestamp()) : $record->lastActivityAt;
        }
    }

    public function state(): SessionState { return $this->state; }
    public function record(): ?SessionRecord { return $this->record; }
    public function pendingResponse(): ?SessionResponseMetadata { return $this->pending; }

    /** @param array<string, mixed> $data */
    public function create(array $data = []): void
    {
        if ($this->state !== SessionState::Absent) {
            throw new InvalidSessionStateException('Only an absent context can create a session.');
        }
        $this->json->validate($data, []);
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $id = $this->secret();
            $lease = $this->store->acquire($this->config->namespace, $id, $this->secret(), $this->config->leaseMilliseconds, $this->config->acquisitionTimeoutMilliseconds);
            try {
                $now = $this->clock->now()->getTimestamp();
                $record = new SessionRecord($id, 0, $this->json->copy($data), [], $now, $now, $now + min($this->config->idleLifetime, $this->config->absoluteLifetime), $now + $this->config->absoluteLifetime, $this->secret());
                if ($this->store->create($this->config->namespace, $record, $lease)) {
                    $this->record = $record;
                    $this->lease = $lease;
                    $this->data = $record->data;
                    $this->activityAt = $now;
                    $this->state = SessionState::Open;
                    $this->issueToken = true;
                    $this->pending = new SessionResponseMetadata($record, true);
                    return;
                }
            } catch (\Throwable $e) {
                $this->store->release($lease);
                throw $e;
            }
            $this->store->release($lease);
        }
        throw new WriteConflictException('Unable to allocate a session.');
    }

    public function has(string $key): bool { $this->open(); return array_key_exists($key, $this->data); }
    public function get(string $key, mixed $default = null): mixed { $this->open(); return array_key_exists($key, $this->data) ? $this->data[$key] : $default; }
    public function set(string $key, mixed $value): void { $this->assign([$key => $value]); }

    /** @param array<string, mixed> $values */
    public function assign(array $values): void
    {
        $this->open();
        $data = array_replace($this->data, $values);
        $this->json->validate($data, $this->flash);
        $this->data = $this->json->copy($data);
    }

    public function delete(string $key): void { $this->open(); unset($this->data[$key]); }
    public function clear(): void { $this->open(); $this->data = []; $this->flash = []; }

    public function flash(string $key, mixed $value): void
    {
        $this->open();
        $flash = array_replace($this->flash, [$key => $value]);
        $this->json->validate($this->data, $flash);
        $this->flash = $this->json->copy($flash);
    }

    public function consumeFlash(string $key, mixed $default = null): mixed
    {
        $this->open();
        $value = array_key_exists($key, $this->flash) ? $this->flash[$key] : $default;
        unset($this->flash[$key]);
        return $value;
    }

    public function renew(): void { $this->open(); $this->issueToken = true; }

    public function excludeActivity(): void
    {
        $this->open();
        $this->activityAt = $this->record->lastActivityAt;
    }

    public function renewLease(): void
    {
        $this->open();
        try {
            $this->lease = $this->store->renewLease($this->lease(), $this->config->leaseMilliseconds);
        } catch (\Throwable $e) {
            $this->discard();
            throw $e;
        }
    }

    public function save(): void
    {
        $this->open();
        $record = $this->snapshot();
        try {
            $this->store->save($this->config->namespace, $record, $this->record->revision, $this->lease());
        } catch (\Throwable $e) {
            $this->discard();
            throw $e;
        }
        $this->record = $record;
        $this->pending = new SessionResponseMetadata($record, $this->issueToken);
    }

    public function saveAndClose(): void
    {
        if ($this->state === SessionState::Absent || $this->state === SessionState::Closed || $this->state === SessionState::Destroyed) {
            return;
        }
        try { $this->save(); } finally { $this->discard(); }
    }

    public function rotate(bool $preserveData = true): void
    {
        $this->open();
        $current = $this->snapshot();
        $record = new SessionRecord($this->secret(), 0, $preserveData ? $current->data : [], $preserveData ? $current->flash : [], $current->createdAt, $current->lastActivityAt, $current->idleExpiresAt, $current->absoluteExpiresAt, $this->secret());
        try {
            $lease = $this->store->rotate($this->config->namespace, $this->record->id, $this->record->revision, $record, $this->lease());
        } catch (\Throwable $e) {
            $this->discard();
            throw $e;
        }
        $this->record = $record;
        $this->lease = $lease;
        $this->data = $record->data;
        $this->flash = $record->flash;
        $this->issueToken = true;
        $this->pending = new SessionResponseMetadata($record, true);
    }

    public function destroy(): void
    {
        $this->open();
        try {
            $this->store->revoke($this->config->namespace, $this->record->id, $this->record->revision, $this->lease());
            $this->state = SessionState::Destroyed;
            $this->pending = new SessionResponseMetadata(null, false, true);
            $this->data = $this->flash = [];
        } finally {
            $this->discard();
        }
    }

    public function discard(): void
    {
        $lease = $this->lease;
        $this->lease = null;
        if ($this->state === SessionState::Open) { $this->state = SessionState::Closed; }
        if ($lease !== null) { $this->store->release($lease); }
    }

    private function open(): void
    {
        if ($this->state !== SessionState::Open) {
            throw new InvalidSessionStateException('Session is not open.');
        }
    }

    private function lease(): Lease
    {
        return $this->lease ?? throw new InvalidSessionStateException('Session has no lease.');
    }

    private function snapshot(): SessionRecord
    {
        $r = $this->record ?? throw new InvalidSessionStateException('Session is absent.');
        $this->json->validate($this->data, $this->flash);
        return new SessionRecord($r->id, $r->revision + 1, $this->data, $this->flash, $r->createdAt, $this->activityAt, min($r->absoluteExpiresAt, $this->activityAt + $this->config->idleLifetime), $r->absoluteExpiresAt, $r->csrfToken);
    }

    private function secret(): string
    {
        $bytes = $this->random->bytes(32);
        if (strlen($bytes) !== 32) { throw new \RuntimeException('Random source returned an invalid length.'); }
        return bin2hex($bytes);
    }
}
