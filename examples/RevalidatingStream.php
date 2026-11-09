<?php
declare(strict_types=1);

use Psr\Http\Message\StreamInterface;
use Semhoun\SlimSession\SessionManager;
use Semhoun\SlimSession\Exception\SessionNotFoundException;

/** Deferred response body: no output occurs until middleware finalization has completed. */
final class RevalidatingStream implements StreamInterface
{
    private int $tick = 0;
    private int $position = 0;
    private string $buffer = '';
    private bool $closed = false;

    public function __construct(private SessionManager $manager, private string $id) {}
    public function __toString(): string
    {
        $result = '';
        try {
            while (!$this->eof()) {
                $result .= $this->read(8192);
            }
        } catch (Throwable) {
            return '';
        }
        return $result;
    }
    public function close(): void { $this->closed = true; $this->buffer = ''; }
    public function detach(): mixed { $this->close(); return null; }
    public function getSize(): ?int { return null; }
    public function tell(): int { return $this->position; }
    public function eof(): bool { return $this->closed || ($this->tick >= 5 && $this->buffer === ''); }
    public function isSeekable(): bool { return false; }
    public function seek(int $offset, int $whence = SEEK_SET): void { throw new RuntimeException('Stream is not seekable.'); }
    public function rewind(): void { throw new RuntimeException('Stream is not seekable.'); }
    public function isWritable(): bool { return false; }
    public function write(string $string): int { throw new RuntimeException('Stream is read-only.'); }
    public function isReadable(): bool { return !$this->closed; }
    public function read(int $length): string
    {
        if ($length < 0) { throw new RuntimeException('Invalid read length.'); }
        if ($length === 0 || $this->eof()) { return ''; }
        if ($this->buffer === '') {
            if ($this->tick > 0) {
                // The emitter wrote the preceding chunk; flush it before the next wait.
                if (PHP_SAPI !== 'cli') {
                    if (ob_get_level() > 0) { ob_flush(); }
                    flush();
                }
                usleep(1000000);
            }
            try {
                $check = $this->manager->load($this->id, false);
                try {
                    $check->saveAndClose();
                } finally {
                    $check->discard();
                }
                $this->buffer = 'data: ' . json_encode(['tick' => ++$this->tick, 'valid' => true], JSON_THROW_ON_ERROR) . "\n\n";
            } catch (SessionNotFoundException) {
                $this->tick = 5;
                $this->buffer = "event: ended\ndata: revoked-or-expired\n\n";
            } catch (Throwable) {
                $this->tick = 5;
                $this->buffer = "event: ended\ndata: validation-unavailable\n\n";
            }
        }
        $part = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, strlen($part));
        $this->position += strlen($part);
        return $part;
    }
    public function getContents(): string { return (string) $this; }
    public function getMetadata(?string $key = null): mixed { return $key === null ? [] : null; }
}
