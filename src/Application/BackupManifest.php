<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class BackupManifest
{
    /** @param array<string,array{count:int,hash:string}> $components */
    public function __construct(private readonly array $components)
    {
        foreach ($components as $component => $data) {
            if ($data['count'] < 0 || ! preg_match('/^[a-f0-9]{64}$/', $data['hash'])) {
                throw new InvalidArgumentException('Backup manifest component is invalid: ' . $component);
            }
        }
    }

    public function assertMatches(self $restored): void
    {
        if ($this->components !== $restored->components) { throw new InvariantViolation('Restored financial manifest does not match backup.'); }
    }
}
