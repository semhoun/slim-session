<?php
declare(strict_types=1);
namespace Semhoun\SlimSession\Storage;

use Semhoun\SlimSession\Exception\StorageUnavailableException;

/** Optional maintenance capability, separate from atomic session operations. */
interface SessionStoreMaintenance
{
    /**
     * Delete at most $limit expired rows per backing table in this namespace.
     * Live rows must not be deleted. Return the total number of deleted rows.
     *
     * @throws StorageUnavailableException When maintenance storage is unavailable.
     */
    public function cleanup(string $namespace, int $limit = 100): int;
}
