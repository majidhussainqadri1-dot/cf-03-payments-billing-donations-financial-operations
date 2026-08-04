<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use InvalidArgumentException;
use Sabri\CF03\Contracts\PaymentProvider;
use Sabri\CF03\Infrastructure\NullPaymentProvider;
use Sabri\CF03\Support\InvariantViolation;

final class ProviderRegistry
{
    /** @var array<string,PaymentProvider> */
    private array $providers = [];

    /** @param list<PaymentProvider> $providers */
    public function __construct(array $providers = [])
    {
        foreach ($providers as $provider) {
            if (! $provider instanceof PaymentProvider) {
                throw new InvalidArgumentException('Provider registry accepts PaymentProvider implementations only.');
            }
            $id = $provider->providerId();
            if (isset($this->providers[$id])) {
                throw new InvariantViolation('Duplicate payment provider identifier.');
            }
            $this->providers[$id] = $provider;
        }
    }

    public function get(string $providerId): PaymentProvider
    {
        return $this->providers[$providerId] ?? new NullPaymentProvider();
    }

    public function selectForCurrency(string $currency): PaymentProvider
    {
        foreach ($this->providers as $provider) {
            if (in_array($currency, $provider->currencies(), true) && $provider->health() === 'healthy') {
                return $provider;
            }
        }
        return new NullPaymentProvider();
    }

    /** @return array<string,string> */
    public function health(): array
    {
        $health = [];
        foreach ($this->providers as $id => $provider) {
            $health[$id] = $provider->health();
        }
        ksort($health);
        return $health;
    }
}
