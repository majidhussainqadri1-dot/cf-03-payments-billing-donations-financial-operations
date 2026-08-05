<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use InvalidArgumentException;
use Sabri\CF03\Domain\DonationServiceState;
use Sabri\CF03\Support\InvariantViolation;

final class RuntimeConfiguration
{
    /** @var list<string> */
    private const FINANCIAL_GATES = [
        'founder_change_control',
        'legal_tax_accounting',
        'pci_scope',
        'provider_selected',
        'independent_security',
        'staging_acceptance',
        'rollback_evidence',
        'file00_contract',
        'file20_file25_contract',
        'file24_assurance',
        'operations_ready',
    ];

    /** @param array<string,bool> $gates */
    public function __construct(
        private readonly DonationServiceState $state,
        private readonly string $providerCode,
        private readonly array $gates,
        private readonly bool $webhookEnabled = false,
        private readonly bool $downloadDeliveryEnabled = false
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/', $providerCode) !== 1) {
            throw new InvalidArgumentException('Runtime provider code is invalid.');
        }
        foreach ($gates as $name => $value) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{2,63}$/', $name) !== 1 || !is_bool($value)) {
                throw new InvalidArgumentException('Runtime gates must use stable lowercase identifiers and boolean values.');
            }
        }
    }

    public static function preparing(): self
    {
        return new self(DonationServiceState::PREPARING, 'provider.unconfigured', []);
    }

    public function state(): DonationServiceState { return $this->state; }
    public function providerCode(): string { return $this->providerCode; }
    public function webhookEnabled(): bool { return $this->webhookEnabled; }
    public function downloadDeliveryEnabled(): bool { return $this->downloadDeliveryEnabled; }

    /** @return list<string> */
    public function missingFinancialGates(): array
    {
        $missing = [];
        foreach (self::FINANCIAL_GATES as $gate) {
            if (($this->gates[$gate] ?? false) !== true) { $missing[] = $gate; }
        }
        if ($this->state === DonationServiceState::LIVE && ($this->gates['founder_live_approval'] ?? false) !== true) {
            $missing[] = 'founder_live_approval';
        }
        if ($this->providerCode === 'provider.unconfigured') { $missing[] = 'provider_selected'; }
        return array_values(array_unique($missing));
    }

    public function assertFinancialMutationReady(): void
    {
        if ($this->state === DonationServiceState::PREPARING) {
            throw new InvariantViolation('CF-03 financial mutations remain disabled while the service is preparing.');
        }
        $missing = $this->missingFinancialGates();
        if ($missing !== []) {
            throw new InvariantViolation('CF-03 activation evidence is incomplete: '.implode(', ', $missing).'.');
        }
    }

    public function assertDonationCheckoutReady(): void
    {
        $this->assertFinancialMutationReady();
    }

    public function assertWebhookReady(): void
    {
        $this->assertFinancialMutationReady();
        if (!$this->webhookEnabled || ($this->gates['webhook_endpoint'] ?? false) !== true) {
            throw new InvariantViolation('CF-03 webhook ingestion is disabled or lacks endpoint acceptance evidence.');
        }
    }

    public function assertDownloadDeliveryReady(): void
    {
        if (!$this->downloadDeliveryEnabled
            || ($this->gates['secure_delivery'] ?? false) !== true
            || ($this->gates['file24_assurance'] ?? false) !== true
        ) {
            throw new InvariantViolation('CF-03 financial download delivery remains fail closed.');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'provider' => $this->providerCode,
            'gates' => $this->gates,
            'missing_financial_gates' => $this->missingFinancialGates(),
            'webhook_enabled' => $this->webhookEnabled,
            'download_delivery_enabled' => $this->downloadDeliveryEnabled,
        ];
    }
}
