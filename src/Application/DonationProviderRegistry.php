<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use InvalidArgumentException;
use Sabri\CF03\Contracts\DonationPaymentProvider;
use Sabri\CF03\Infrastructure\NullDonationPaymentProvider;
use Sabri\CF03\Support\InvariantViolation;

final class DonationProviderRegistry
{
    /** @var array<string,DonationPaymentProvider> */
    private array $providers = [];

    /** @param list<DonationPaymentProvider> $providers */
    public function __construct(array $providers = [])
    {
        foreach ($providers as $provider) {
            if (!$provider instanceof DonationPaymentProvider) {
                throw new InvalidArgumentException('Donation provider registry accepts DonationPaymentProvider implementations only.');
            }
            $code = $provider->providerCode();
            if (isset($this->providers[$code])) { throw new InvariantViolation('Duplicate donation provider identifier.'); }
            $this->providers[$code] = $provider;
        }
    }

    public function get(string $providerCode): DonationPaymentProvider
    {
        return $this->providers[$providerCode] ?? new NullDonationPaymentProvider();
    }

    /** @return list<string> */
    public function registeredProviderCodes(): array
    {
        $codes = array_keys($this->providers); sort($codes); return $codes;
    }
}
