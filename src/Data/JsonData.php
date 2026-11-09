<?php
declare(strict_types=1);
namespace Semhoun\SlimSession\Data;

use Semhoun\SlimSession\Exception\InvalidSessionDataException;

final readonly class JsonData
{
    public function __construct(private int $maxBytes, private int $maxDepth) {}

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $flash
     */
    public function validate(array $data, array $flash): void
    {
        $this->check($data, 0);
        $this->check($flash, 0);
        try {
            $json = json_encode([$data, $flash], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, $this->maxDepth + 2);
        } catch (\JsonException $e) {
            throw new InvalidSessionDataException('Session values must be finite, valid UTF-8 JSON.', 0, $e);
        }
        if (strlen($json) > $this->maxBytes) {
            throw new InvalidSessionDataException('Session data exceeds the byte limit.');
        }
    }

    private function check(mixed $value, int $depth): void
    {
        if (is_array($value)) {
            if ($depth >= $this->maxDepth) {
                throw new InvalidSessionDataException('Session data exceeds the depth limit.');
            }
            foreach ($value as $item) {
                $this->check($item, $depth + 1);
            }
        } elseif (!is_null($value) && !is_scalar($value)) {
            throw new InvalidSessionDataException('Session values cannot contain objects or resources.');
        }
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function copy(array $values): array
    {
        return json_decode(json_encode($values, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, $this->maxDepth + 2), true, $this->maxDepth + 2, JSON_THROW_ON_ERROR);
    }
}
