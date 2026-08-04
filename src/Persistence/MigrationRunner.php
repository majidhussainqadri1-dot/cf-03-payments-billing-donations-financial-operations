<?php

declare(strict_types=1);

namespace Sabri\CF03\Persistence;

use RuntimeException;

final class MigrationRunner
{
    /** @param callable(string):void $executor @param callable(string):bool $isApplied */
    public function __construct(private readonly mixed $executor, private readonly mixed $isApplied)
    {
        if (! is_callable($executor) || ! is_callable($isApplied)) { throw new RuntimeException('Migration callbacks must be callable.'); }
    }

    /** @return list<string> */
    public function migrate(string $prefix): array
    {
        $applied = [];
        foreach (Schema::tables($prefix) as $name => $sql) {
            $id = 'cf03-1.0.0-' . $name;
            if (($this->isApplied)($id)) { continue; }
            ($this->executor)($sql);
            $applied[] = $id;
        }
        return $applied;
    }
}
