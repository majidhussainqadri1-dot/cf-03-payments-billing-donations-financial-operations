<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

final class EvidenceBoundActivationGate
{
    /**
     * @param callable():array<string,mixed> $recordLoader
     * @param null|callable():DateTimeImmutable $clock
     */
    public function __construct(
        private readonly bool $runtimeFlag,
        private readonly mixed $recordLoader,
        private readonly ?string $expectedEvidenceHash = null,
        private readonly string $moduleVersion = '0.2.0',
        private readonly mixed $clock = null
    ) {
        if (! is_callable($recordLoader)) {
            throw new InvalidArgumentException('Activation record loader must be callable.');
        }

        if ($clock !== null && ! is_callable($clock)) {
            throw new InvalidArgumentException('Activation clock must be callable when supplied.');
        }
    }

    public static function forWordPress(): self
    {
        $runtimeFlag = defined('SABRI_CF03_RUNTIME_ACTIVATION')
            && SABRI_CF03_RUNTIME_ACTIVATION === true;
        $expectedHash = defined('SABRI_CF03_ACTIVATION_EVIDENCE_HASH')
            ? (string) SABRI_CF03_ACTIVATION_EVIDENCE_HASH
            : null;
        $moduleVersion = defined('SABRI_CF03_VERSION')
            ? (string) SABRI_CF03_VERSION
            : '0.2.0';

        return new self(
            $runtimeFlag,
            static function (): array {
                if (! function_exists('get_option')) {
                    return [];
                }

                $record = get_option('sabri_cf03_activation_record', []);
                return is_array($record) ? $record : [];
            },
            $expectedHash,
            $moduleVersion
        );
    }

    public function evaluate(): ActivationStatus
    {
        $missing = [];

        if (! $this->runtimeFlag) {
            $missing[] = 'runtime_constant';
        }

        if (! is_string($this->expectedEvidenceHash)
            || preg_match('/^[a-f0-9]{64}$/', $this->expectedEvidenceHash) !== 1
        ) {
            $missing[] = 'activation_evidence_hash_constant';
        }

        try {
            $loaded = ($this->recordLoader)();
        } catch (Throwable) {
            $loaded = null;
        }

        if (! is_array($loaded)) {
            $missing[] = 'activation_record_invalid';
            return new ActivationStatus(false, array_values(array_unique($missing)));
        }

        try {
            $now = $this->clock === null
                ? new DateTimeImmutable('now')
                : ($this->clock)();
        } catch (Throwable) {
            $missing[] = 'activation_clock_invalid';
            return new ActivationStatus(false, array_values(array_unique($missing)));
        }

        if (! $now instanceof DateTimeImmutable) {
            $missing[] = 'activation_clock_invalid';
            return new ActivationStatus(false, array_values(array_unique($missing)));
        }

        $missing = array_merge(
            $missing,
            ActivationEvidenceRecord::missingGates($loaded, $this->moduleVersion, $now)
        );

        $actualHash = ActivationEvidenceRecord::canonicalHash($loaded);
        if ($actualHash === ''
            || ! is_string($this->expectedEvidenceHash)
            || ! hash_equals($this->expectedEvidenceHash, $actualHash)
        ) {
            $missing[] = 'activation_record_hash_mismatch';
        }

        $missing = array_values(array_unique($missing));
        return new ActivationStatus($missing === [], $missing);
    }
}
