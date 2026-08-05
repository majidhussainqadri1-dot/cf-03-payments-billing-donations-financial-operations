<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use InvalidArgumentException;
use Sabri\CF03\Contracts\IncidentStateStore;
use Sabri\CF03\Support\InvariantViolation;

final class IncidentPathGuard
{
    /** @var array<string,string> */
    private const PATH_FLAGS = [
        'checkout' => 'checkout_enabled',
        'refunds' => 'refunds_enabled',
        'webhooks' => 'webhooks_enabled',
    ];

    public function __construct(private readonly IncidentStateStore $store) {}

    public function assertAvailable(string $path): void
    {
        $flag = self::PATH_FLAGS[$path] ?? null;
        if ($flag === null) {
            throw new InvalidArgumentException('Unknown financial incident path.');
        }

        $state = $this->store->get();
        $mode = (string)($state['state'] ?? 'normal');
        if ($mode === 'normal') {
            return;
        }
        if (!in_array($mode, ['contained', 'recovered'], true)) {
            throw new InvariantViolation('Unknown financial incident state blocks sensitive operations.');
        }
        if (($state[$flag] ?? false) !== true) {
            throw new InvariantViolation('Financial '.$path.' path is disabled by incident control.');
        }
    }
}
