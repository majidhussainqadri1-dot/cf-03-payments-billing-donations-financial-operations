<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class BackupManifest
{
    /** @var array<string,array{count:int,hash:string}> */
    private readonly array $components;

    /** @param array<string,array{count:int,hash:string}> $components */
    public function __construct(array $components)
    {
        if ($components === [] || count($components) > 128) {
            throw new InvalidArgumentException('Backup manifest must contain a bounded non-empty component set.');
        }
        $normalized = [];
        foreach ($components as $component => $data) {
            if (! is_string($component)
                || preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $component) !== 1
                || ! is_array($data)
                || array_keys($data) !== ['count', 'hash']
                || ! is_int($data['count'])
                || $data['count'] < 0
                || ! is_string($data['hash'])
                || preg_match('/^[a-f0-9]{64}$/', $data['hash']) !== 1
            ) {
                throw new InvalidArgumentException('Backup manifest component is invalid: ' . (string) $component);
            }
            $normalized[$component] = ['count' => $data['count'], 'hash' => $data['hash']];
        }
        ksort($normalized, SORT_STRING);
        $this->components = $normalized;
    }

    public function assertMatches(self $restored): void
    {
        if ($this->components !== $restored->components) {
            throw new InvariantViolation('Restored financial manifest does not match backup.');
        }
    }

    /** @return array<string,array{count:int,hash:string}> */
    public function components(): array { return $this->components; }
}
