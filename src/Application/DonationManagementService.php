<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Support\InvariantViolation;

final class DonationManagementService
{
    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly DonationProviderRegistry $providers,
        private readonly RuntimeConfiguration $configuration
    ) {}

    /** @return array<string,mixed> */
    public function view(string $actorReference): array
    {
        self::reference($actorReference, 'Donation-history actor');
        $donations = [];
        foreach ($this->repository->find('donations', ['donor_ref' => $actorReference], 100) as $record) {
            if ((bool)($record['recurring'] ?? false) === true) {
                // Historical recurring records are retained for audit/reconciliation only,
                // never presented as an active mandate or renewable capability.
                continue;
            }
            $donations[] = array_intersect_key($record, array_flip([
                'donation_id','amount_minor','currency','purpose_code','receipt_ref','state',
                'created_at','updated_at','version','record_version',
            ]));
        }
        return [
            'donation_model' => 'voluntary_one_time_only',
            'recurring_available' => false,
            'automatic_repeat_charge' => false,
            'donations' => $donations,
        ];
    }

    /** @return never */
    public function cancel(
        string $consentId,
        string $actorReference,
        string $idempotencyKey,
        int $expectedVersion,
        DateTimeImmutable $now
    ): array {
        throw new InvariantViolation(
            'Recurring donation management is retired. Historical mandates require provider-side reconciliation and may not be renewed through CF-03.'
        );
    }

    /** @return never */
    public function changeAmount(
        string $consentId,
        string $actorReference,
        Money $amount,
        string $idempotencyKey,
        int $expectedVersion,
        DateTimeImmutable $now
    ): array {
        throw new InvariantViolation(
            'Recurring donation amount changes are prohibited. A user may deliberately start a new independent one-time donation.'
        );
    }

    private static function reference(string $value, string $label): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $value) !== 1) {
            throw new InvalidArgumentException($label.' is invalid.');
        }
    }
}
