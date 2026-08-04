<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;

final class HostedCheckoutReference
{
    /** @param list<string> $allowedHosts */
    public function __construct(
        private readonly string $providerCode,
        private readonly string $providerSessionReference,
        private readonly string $hostedUrl,
        private readonly DateTimeImmutable $issuedAt,
        private readonly DateTimeImmutable $expiresAt,
        array $allowedHosts
    ) {
        foreach ([$providerCode, $providerSessionReference] as $value) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/', $value) !== 1) {
                throw new InvalidArgumentException('Hosted checkout reference contains an invalid identifier.');
            }
        }
        if ($expiresAt <= $issuedAt || $expiresAt > $issuedAt->modify('+24 hours')) {
            throw new InvalidArgumentException('Hosted checkout expiry must follow issue time and remain within 24 hours.');
        }

        $parts = parse_url($hostedUrl);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || filter_var($parts['host'], FILTER_VALIDATE_IP) !== false
        ) {
            throw new InvalidArgumentException('Hosted checkout URL must be an HTTPS provider hostname without credentials, fragments or nonstandard ports.');
        }

        $normalizedHosts = [];
        foreach ($allowedHosts as $host) {
            if (! is_string($host)
                || preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) !== 1
            ) {
                throw new InvalidArgumentException('Hosted checkout allowlist contains an invalid hostname.');
            }
            $normalizedHosts[] = strtolower($host);
        }
        $normalizedHosts = array_values(array_unique($normalizedHosts));

        if ($normalizedHosts === [] || ! in_array(strtolower((string) $parts['host']), $normalizedHosts, true)) {
            throw new InvalidArgumentException('Hosted checkout URL host is not allowlisted for the provider.');
        }
    }

    public function providerCode(): string { return $this->providerCode; }
    public function providerSessionReference(): string { return $this->providerSessionReference; }
    public function hostedUrl(): string { return $this->hostedUrl; }
    public function issuedAt(): DateTimeImmutable { return $this->issuedAt; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }
}
