<?php

declare(strict_types=1);

namespace Sabri\CF03\Persistence;

use RuntimeException;

final class MigrationRunner
{
    /**
     * @param callable(string):void $executor
     * @param callable(string):bool $isApplied
     * @param null|callable(string,string):void $recordApplied migration ID, checksum
     * @param null|callable(string):?string $appliedChecksum migration ID -> recorded checksum
     */
    public function __construct(
        private readonly mixed $executor,
        private readonly mixed $isApplied,
        private readonly mixed $recordApplied = null,
        private readonly mixed $appliedChecksum = null
    ) {
        if (! is_callable($executor) || ! is_callable($isApplied)) {
            throw new RuntimeException('Migration callbacks must be callable.');
        }
        if ($recordApplied !== null && ! is_callable($recordApplied)) {
            throw new RuntimeException('Migration recorder must be callable when provided.');
        }
        if ($appliedChecksum !== null && ! is_callable($appliedChecksum)) {
            throw new RuntimeException('Migration checksum resolver must be callable when provided.');
        }
    }

    /** @return list<string> */
    public function migrate(string $prefix): array
    {
        if (preg_match('/^[A-Za-z0-9_]{0,64}$/', $prefix) !== 1) {
            throw new RuntimeException('Migration table prefix is invalid.');
        }

        $applied = [];
        foreach (Schema::tables($prefix) as $name => $sql) {
            $id = 'cf03-' . Schema::VERSION . '-' . $name;
            $checksum = hash('sha256', $sql);
            if (($this->isApplied)($id)) {
                if ($this->appliedChecksum === null) {
                    throw new RuntimeException('Applied migration checksum evidence is required: ' . $id . '.');
                }
                $recorded = ($this->appliedChecksum)($id);
                if (! is_string($recorded)
                    || preg_match('/^[a-f0-9]{64}$/', $recorded) !== 1
                    || ! hash_equals($checksum, $recorded)
                ) {
                    throw new RuntimeException('Applied migration checksum drift detected: ' . $id . '.');
                }
                continue;
            }
            ($this->executor)($sql);
            if ($this->recordApplied === null) {
                throw new RuntimeException('Migration cannot be considered complete without durable checksum recording.');
            }
            ($this->recordApplied)($id, $checksum);
            $applied[] = $id;
        }
        return $applied;
    }
}
