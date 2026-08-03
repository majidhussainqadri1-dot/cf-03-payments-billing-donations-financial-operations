<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use InvalidArgumentException;

/**
 * Legacy foundation gate retained for backward-compatible 0.1.x domain tests.
 * Runtime code uses EvidenceBoundActivationGate, which requires versioned,
 * hash-bound and expiring approval evidence.
 */
final class ActivationGate
{
    /** @param callable():array<string,mixed> $recordLoader */
    public function __construct(
        private readonly bool $runtimeFlag,
        private readonly mixed $recordLoader
    ) {
        if (! is_callable($recordLoader)) {
            throw new InvalidArgumentException('Activation record loader must be callable.');
        }
    }

    public function evaluate(): ActivationStatus
    {
        $loaded = ($this->recordLoader)();
        $record = is_array($loaded) ? $loaded : [];
        $missing = [];

        if (! $this->runtimeFlag) {
            $missing[] = 'runtime_constant';
        }

        foreach ([
            'founder_change_control_approved',
            'legal_tax_accounting_review_approved',
            'pci_scope_validated',
            'independent_security_acceptance',
            'staging_acceptance',
            'rollback_rehearsal_passed',
        ] as $gate) {
            if (($record[$gate] ?? false) !== true) {
                $missing[] = $gate;
            }
        }

        if (! in_array($record['provider_mode'] ?? null, ['hosted', 'tokenized'], true)) {
            $missing[] = 'provider_mode';
        }

        return new ActivationStatus($missing === [], $missing);
    }
}
