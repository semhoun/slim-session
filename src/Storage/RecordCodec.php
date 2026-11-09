<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Storage;

use Semhoun\SlimSession\SessionRecord;
use Semhoun\SlimSession\Data\JsonData;
use Semhoun\SlimSession\Exception\StorageUnavailableException;

/** Session records never use PHP object serialization. */
final class RecordCodec
{
    public static function encode(SessionRecord $record): string
    {
        (new JsonData(16_000_000, 60))->validate($record->data, $record->flash);
        return json_encode(get_object_vars($record), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function decode(string $payload, ?string $expectedId = null): SessionRecord
    {
        try {
            $data = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data) || ($data['schemaVersion'] ?? null) !== 2
                || !is_int($data['revision'] ?? null) || $data['revision'] < 0
                || !is_string($data['id'] ?? null) || !preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D', $data['id'])
                || ($expectedId !== null && $data['id'] !== $expectedId)
                || !is_string($data['csrfToken'] ?? null) || $data['csrfToken'] === ''
                || !is_string($data['verificationSecret'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $data['verificationSecret'])
                || !is_array($data['data'] ?? null) || !is_array($data['flash'] ?? null)) {
                throw new \UnexpectedValueException('Invalid session record fields.');
            }
            foreach (['createdAt', 'lastActivityAt', 'idleExpiresAt', 'absoluteExpiresAt'] as $field) {
                if (!is_int($data[$field] ?? null) || $data[$field] < 0) {
                    throw new \UnexpectedValueException('Invalid session timestamps.');
                }
            }
            if ($data['createdAt'] > $data['lastActivityAt'] || $data['lastActivityAt'] >= $data['idleExpiresAt'] || $data['idleExpiresAt'] > $data['absoluteExpiresAt']) {
                throw new \UnexpectedValueException('Invalid session timestamp order.');
            }
            return new SessionRecord(
                id: $data['id'], revision: $data['revision'], data: $data['data'], flash: $data['flash'],
                createdAt: $data['createdAt'], lastActivityAt: $data['lastActivityAt'],
                idleExpiresAt: $data['idleExpiresAt'], absoluteExpiresAt: $data['absoluteExpiresAt'],
                csrfToken: $data['csrfToken'], verificationSecret: $data['verificationSecret'], schemaVersion: $data['schemaVersion'],
            );
        } catch (\Throwable $error) {
            throw new StorageUnavailableException('Invalid stored session record.', 0, $error);
        }
    }
}
