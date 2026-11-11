<?php
declare(strict_types=1);
namespace Semhoun\SlimSession;

use Psr\Clock\ClockInterface;
use Semhoun\SlimSession\Data\JsonData;
use Semhoun\SlimSession\Exception\InvalidSessionDataException;
use Semhoun\SlimSession\Exception\InvalidSessionStateException;
use Semhoun\SlimSession\Exception\StorageUnavailableException;
use Semhoun\SlimSession\Exception\WriteConflictException;
use Semhoun\SlimSession\Random\RandomSource;
use Semhoun\SlimSession\Storage\SessionStore;
use Semhoun\SlimSession\Storage\SessionStoreMaintenance;

final class SessionContext
{
    private SessionState $state;
    private JsonData $json;
    private ?SessionResponseMetadata $pending = null;
    private bool $issueToken = false;
    private bool $cleanupPending = false;
    private bool $creationResponsePending = false;
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
    public function create(array $data = [], ?int $absoluteExpiresAt = null): void
    {
        if ($this->state !== SessionState::Absent) {
            throw new InvalidSessionStateException('Only an absent context can create a session.');
        }
        $data = $this->assignData([], $data, []);
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $id = SessionId::generate($this->random);
            $lease = $this->store->acquire($this->config->namespace, $id, $this->secret(), $this->config->leaseMilliseconds, $this->config->acquisitionTimeoutMilliseconds);
            try {
                $now = $this->clock->now()->getTimestamp();
                $absolute = min($now + $this->config->absoluteLifetime, $absoluteExpiresAt ?? PHP_INT_MAX);
                if ($absolute <= $now) {
                    throw new InvalidSessionStateException('The requested absolute deadline has expired.');
                }
                $record = new SessionRecord($id, 0, $data, [], $now, $now, min($now + $this->config->idleLifetime, $absolute), $absolute, $this->secret(), $this->secret());
                if ($this->store->create($this->config->namespace, $record, $lease)) {
                    $this->record = $record;
                    $this->lease = $lease;
                    $this->data = $record->data;
                    $this->flash = [];
                    $this->activityAt = $now;
                    $this->state = SessionState::Open;
                    $this->issueToken = true;
                    $this->pending = new SessionResponseMetadata($record, true);
                    $this->cleanupPending = true;
                    $this->creationResponsePending = true;
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

    /** @param array<string, mixed> $data */
    public function startNew(array $data = [], ?int $absoluteExpiresAt = null): void
    {
        if ($this->state === SessionState::Absent) {
            $this->create($data, $absoluteExpiresAt);
            return;
        }
        $this->open();
        // Revocation and creation are separate commits; never restore the old identity.
        $this->pending = new SessionResponseMetadata(null, false, true);
        $this->issueToken = false;
        $this->creationResponsePending = false;
        try {
            $this->destroy();
            $this->record = null;
            $this->activityAt = 0;
            $this->state = SessionState::Absent;
            $this->create($data, $absoluteExpiresAt);
        } catch (\Throwable $e) {
            $this->record = null;
            $this->data = $this->flash = [];
            $this->activityAt = 0;
            $this->state = SessionState::Destroyed;
            $this->pending = new SessionResponseMetadata(null, false, true);
            throw $e;
        }
    }

    public function has(string $key): bool
    {
        $this->open();
        $value = $this->data;
        foreach ($this->path($key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) { return false; }
            $value = $value[$segment];
        }
        return true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->open();
        $value = $this->data;
        foreach ($this->path($key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) { return $default; }
            $value = $value[$segment];
        }
        return $value;
    }
    public function set(string $key, mixed $value): void { $this->assign([$key => $value]); }

    /** @param array<string, mixed> $values */
    public function assign(array $values): void
    {
        $this->open();
        $this->data = $this->assignData($this->data, $values, $this->flash);
    }

    public function delete(string $key): void
    {
        $this->open();
        $segments = $this->path($key);
        $leaf = array_pop($segments);
        $parent = &$this->data;
        foreach ($segments as $segment) {
            if (!array_key_exists($segment, $parent) || !is_array($parent[$segment])) { return; }
            $parent = &$parent[$segment];
        }
        unset($parent[$leaf]);
    }
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
            $this->suppressCreationResponse();
            $this->discard();
            throw $e;
        }
    }

    public function save(): void
    {
        $this->open();
        try {
            $record = $this->snapshot();
            $this->store->save($this->config->namespace, $record, $this->record->revision, $this->lease());
        } catch (\Throwable $e) {
            $this->suppressCreationResponse();
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
        try {
            $current = $this->snapshot();
            $record = new SessionRecord(SessionId::generate($this->random), 0, $preserveData ? $current->data : [], $preserveData ? $current->flash : [], $current->createdAt, $current->lastActivityAt, $current->idleExpiresAt, $current->absoluteExpiresAt, $this->secret(), $this->secret());
            $lease = $this->store->rotate($this->config->namespace, $this->record->id, $this->record->revision, $record, $this->lease());
        } catch (\Throwable $e) {
            $this->suppressCreationResponse();
            $this->discard();
            throw $e;
        }
        $this->record = $record;
        $this->lease = $lease;
        $this->creationResponsePending = false;
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
        $cleanup = $this->cleanupPending;
        $this->cleanupPending = false;
        $this->lease = null;
        if ($this->state === SessionState::Open) { $this->state = SessionState::Closed; }
        if ($lease !== null) { $this->store->release($lease); }
        if ($cleanup) { $this->cleanupExpiredSessions(); }
    }

    private function suppressCreationResponse(): void
    {
        if (!$this->creationResponsePending) { return; }
        $this->record = null;
        $this->data = $this->flash = [];
        $this->issueToken = false;
        $this->pending = new SessionResponseMetadata(null, false, true);
        $this->creationResponsePending = false;
    }

    private function cleanupExpiredSessions(): void
    {
        $chance = $this->config->cleanupProbabilityBasisPoints;
        if ($chance === 0 || !$this->store instanceof SessionStoreMaintenance) {
            return;
        }
        if ($chance < 10_000) {
            // Reject the incomplete modulo bucket; cap work for faulty injected sources.
            try {
                for ($attempt = 0; $attempt < 8; ++$attempt) {
                    $bytes = $this->random->bytes(2);
                    if (strlen($bytes) !== 2) { return; }
                    $sample = ord($bytes[0]) * 256 + ord($bytes[1]);
                    if ($sample < 60_000) { break; }
                }
            } catch (\Random\RandomException|\RuntimeException) {
                return;
            }
            if ($sample >= 60_000 || $sample % 10_000 >= $chance) { return; }
        }
        try {
            $this->store->cleanup($this->config->namespace, $this->config->cleanupBatchSize);
        } catch (StorageUnavailableException) {
            // Ancillary maintenance must not invalidate a committed session operation.
        }
    }

    private function open(): void
    {
        if ($this->state !== SessionState::Open) {
            throw new InvalidSessionStateException('Session is not open.');
        }
    }

    /** @return non-empty-list<string> */
    private function path(string $key): array
    {
        $segments = explode('.', $key);
        if (in_array('', $segments, true)) {
            throw new InvalidSessionDataException('Session paths must contain non-empty segments.');
        }
        return $segments;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $values
     * @param array<string, mixed> $flash
     * @return array<string, mixed>
     */
    private function assignData(array $data, array $values, array $flash): array
    {
        $inputJson = new JsonData(PHP_INT_MAX, $this->config->maxDataDepth);
        foreach ($values as $key => $value) {
            $segments = $this->path((string) $key);
            if (count($segments) > $this->config->maxDataDepth) {
                throw new InvalidSessionDataException('Session data exceeds the depth limit.');
            }
            // Detach references now; enforce byte limits on the normalized result only.
            $inputJson->validate([$key => $value], []);
            $value = $inputJson->copy([$key => $value])[$key];
            $leaf = array_pop($segments);
            $parent = &$data;
            foreach ($segments as $segment) {
                if (!array_key_exists($segment, $parent)) {
                    $parent[$segment] = [];
                } elseif (!is_array($parent[$segment])) {
                    throw new InvalidSessionDataException('Cannot write through a non-array session parent.');
                }
                $parent = &$parent[$segment];
            }
            $parent[$leaf] = $value;
            unset($parent);
        }
        $this->json->validate($data, $flash);
        return $this->json->copy($data);
    }

    private function lease(): Lease
    {
        return $this->lease ?? throw new InvalidSessionStateException('Session has no lease.');
    }

    private function snapshot(): SessionRecord
    {
        $r = $this->record ?? throw new InvalidSessionStateException('Session is absent.');
        $this->json->validate($this->data, $this->flash);
        return new SessionRecord($r->id, $r->revision + 1, $this->data, $this->flash, $r->createdAt, $this->activityAt, min($r->absoluteExpiresAt, $this->activityAt + $this->config->idleLifetime), $r->absoluteExpiresAt, $r->csrfToken, $r->verificationSecret);
    }

    private function secret(): string
    {
        $bytes = $this->random->bytes(32);
        if (strlen($bytes) !== 32) { throw new \RuntimeException('Random source returned an invalid length.'); }
        return bin2hex($bytes);
    }
}
