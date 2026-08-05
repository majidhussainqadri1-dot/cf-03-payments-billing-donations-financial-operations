<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final class FinancialOutcomeProjection
{
    /** @var list<string> */
    private const PUBLIC_TYPES=['transparency_snapshot_published'];
    /** @var list<string> */
    private const PRIVATE_TYPES=['donation_settled','donation_refunded','refund_requested','refund_decided','subscription_cancelled','payment_failed'];

    public function __construct(
        private readonly string $type,
        private readonly string $status,
        private readonly string $canonicalReference,
        private readonly DateTimeImmutable $occurredAt,
        private readonly bool $public,
        private readonly ?Money $amount=null,
        private readonly ?string $safeReasonCode=null
    ) {
        if (!in_array($type,array_merge(self::PUBLIC_TYPES,self::PRIVATE_TYPES),true)) { throw new InvalidArgumentException('Financial outcome type is not allowlisted.'); }
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/',$status)!==1) { throw new InvalidArgumentException('Financial outcome status is invalid.'); }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/',$canonicalReference)!==1) { throw new InvalidArgumentException('Financial outcome reference is invalid.'); }
        if ($public!==in_array($type,self::PUBLIC_TYPES,true)) { throw new InvalidArgumentException('Financial outcome public classification does not match its type.'); }
        if ($safeReasonCode!==null && preg_match('/^[a-z][a-z0-9_]{2,63}$/',$safeReasonCode)!==1) { throw new InvalidArgumentException('Financial outcome reason code is invalid.'); }
    }

    /** @return array<string,mixed> */
    public function toFile26Projection(): array
    {
        if (!$this->public) {
            return ['indexable'=>false,'recommendable'=>false,'reason'=>'private_financial_fact','canonical_reference'=>null];
        }
        return [
            'indexable'=>true,
            'recommendable'=>false,
            'type'=>$this->type,
            'status'=>$this->status,
            'canonical_reference'=>$this->canonicalReference,
            'occurred_at'=>$this->occurredAt->format(DATE_ATOM),
            'amount'=>$this->amount===null?null:['minor_units'=>$this->amount->minorUnits(),'currency'=>$this->amount->currency()],
            'safe_reason_code'=>$this->safeReasonCode,
            'contains_donor_identity'=>false,
            'contains_provider_reference'=>false,
            'ranking_signal'=>false,
        ];
    }
}
