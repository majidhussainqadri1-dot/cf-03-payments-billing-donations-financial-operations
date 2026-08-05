<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class FinancialDueProcessPolicy
{
    /** @return array<string,mixed> */
    public function contract(): array
    {
        return [
            'policy_id'=>'CF03-DUE-PROCESS-1.0',
            'principles'=>['notice','specific_reason','evidence_reference','opportunity_to_respond','conflict_disclosure','independent_review','appeal','implementation_tracking','non_retaliation','privacy_minimization'],
            'native_financial_truth_owner'=>'CF-03',
            'case_orchestration_owner'=>'CF-02-after-activation',
            'support_may_not_mutate_ledger'=>true,
            'clinical_or_membership_authority_not_inferred'=>true,
        ];
    }

    /** @param list<string> $evidenceReferences */
    public function assertDecisionRecord(
        string $decisionId,
        string $subjectReference,
        string $reasonCode,
        array $evidenceReferences,
        string $reviewerReference,
        string $requesterReference,
        DateTimeImmutable $decidedAt,
        bool $appealAvailable,
        ?DateTimeImmutable $appealDeadline
    ): void {
        foreach ([$decisionId,$subjectReference,$reviewerReference,$requesterReference] as $reference) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,190}$/',$reference)!==1) { throw new InvalidArgumentException('Financial due-process reference is invalid.'); }
        }
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/',$reasonCode)!==1) { throw new InvalidArgumentException('Financial due-process reason code is invalid.'); }
        if ($reviewerReference===$requesterReference) { throw new InvariantViolation('Financial decision reviewer must be independent from the requester.'); }
        if ($evidenceReferences===[]) { throw new InvariantViolation('Financial decision requires evidence references.'); }
        foreach ($evidenceReferences as $reference) {
            if (!is_string($reference)||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,190}$/',$reference)!==1) { throw new InvalidArgumentException('Financial decision evidence reference is invalid.'); }
        }
        if ($appealAvailable && ($appealDeadline===null||$appealDeadline<=$decidedAt)) { throw new InvariantViolation('Appealable financial decision requires a future appeal deadline.'); }
        if (!$appealAvailable && $appealDeadline!==null) { throw new InvariantViolation('Non-appealable decision cannot expose a misleading appeal deadline.'); }
    }
}
