<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use Sabri\CF03\Contracts\RetentionActionExecutor;
use Sabri\CF03\Support\InvariantViolation;

final class NullRetentionActionExecutor implements RetentionActionExecutor
{
    public function archive(string $recordType,string $recordReference):string{throw new InvariantViolation('No approved retention archive executor is configured.');}
    public function anonymize(string $recordType,string $recordReference):string{throw new InvariantViolation('No approved retention anonymization executor is configured.');}
    public function delete(string $recordType,string $recordReference):string{throw new InvariantViolation('No approved retention deletion executor is configured.');}
}
