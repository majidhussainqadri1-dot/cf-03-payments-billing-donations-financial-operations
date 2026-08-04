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
     */
    public function __construct(
        private readonly mixed $executor,
        private readonly mixed $isApplied,
        private readonly mixed $recordApplied = null
    ) {
        if (! is_callable($executor) || ! is_callable($isApplied)) {
            throw new RuntimeException('Migration callbacks must be callable.');
        }
        if ($recordApplied !== null && ! is_callable($recordApplied)) {
            throw new RuntimeException('Migration recorder must be callable when provided.');
        }
    }

    /** @return list<string> */
    public function migrate(string $prefix): array
    {
        $applied = [];
        foreach (Schema::tables($prefix) as $name => $sql) {
            $id = 'cf03-' . Schema::VERSION . '-' . $name;
            if (($this->isApplied)($id)) {
                continue;
            }
            ($this->executor)($sql);
            $checksum = hash('sha256', $sql);
            if ($this->recordApplied !== null) {
                ($this->recordApplied)($id, $checksum);
            }
            $applied[] = $id;
        }
        return $applied;
    }
}
