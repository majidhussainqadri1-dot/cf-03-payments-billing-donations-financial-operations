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
        private readonly DateTimeImmutable $expiresAt,
        array $allowedHosts
    ) {
        foreach ([$providerCode, $providerSessionReference] as $value) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/', $value) !== 1) {
                throw new InvalidArgumentException('Hosted checkout reference contains an invalid identifier.');
            }
        }

        $parts = parse_url($hostedUrl);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('Hosted checkout URL must be an HTTPS provider URL without credentials or fragments.');
        }

        $normalizedHosts = [];
        foreach ($allowedHosts as $host) {
            if (! is_string($host) || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
                throw new InvalidArgumentException('Hosted checkout allowlist contains an invalid host.');
            }
            $normalizedHosts[] = strtolower($host);
        }

        if ($normalizedHosts === [] || ! in_array(strtolower((string) $parts['host']), $normalizedHosts, true)) {
            throw new InvalidArgumentException('Hosted checkout URL host is not allowlisted for the provider.');
        }
    }

    public function providerCode(): string { return $this->providerCode; }
    public function providerSessionReference(): string { return $this->providerSessionReference; }
    public function hostedUrl(): string { return $this->hostedUrl; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }
}
