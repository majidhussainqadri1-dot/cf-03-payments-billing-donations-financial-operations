<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use InvalidArgumentException;

final class ActivationGate
{
    /**
     * @param callable():array<string,mixed> $recordLoader
     */
    public function __construct(
        private readonly bool $runtimeFlag,
        private readonly mixed $recordLoader
    ) {
        if (! is_callable($recordLoader)) {
            throw new InvalidArgumentException('Activation record loader must be callable.');
        }
    }

    public static function forWordPress(): self
    {
        $runtimeFlag = defined('SABRI_CF03_RUNTIME_ACTIVATION')
            && SABRI_CF03_RUNTIME_ACTIVATION === true;

        return new self(
            $runtimeFlag,
            static function (): array {
                if (! function_exists('get_option')) {
                    return [];
                }

                $record = get_option('sabri_cf03_activation_record', []);
                return is_array($record) ? $record : [];
            }
        );
    }

    public function evaluate(): ActivationStatus
    {
        $loaded = ($this->recordLoader)();
        $record = is_array($loaded) ? $loaded : [];
        $missing = [];

        if (! $this->runtimeFlag) {
            $missing[] = 'runtime_constant';
        }

        $booleanGates = [
            'founder_change_control_approved',
            'legal_tax_accounting_review_approved',
            'pci_scope_validated',
            'independent_security_acceptance',
            'staging_acceptance',
            'rollback_rehearsal_passed',
        ];

        foreach ($booleanGates as $gate) {
            if (($record[$gate] ?? false) !== true) {
                $missing[] = $gate;
            }
        }

        $providerMode = $record['provider_mode'] ?? null;
        if (! in_array($providerMode, ['hosted', 'tokenized'], true)) {
            $missing[] = 'provider_mode';
        }

        return new ActivationStatus($missing === [], $missing);
    }
}
