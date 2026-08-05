<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use Sabri\CF03\Contracts\RetentionActionExecutor;
use Sabri\CF03\Support\InvariantViolation;

final class WordPressRetentionActionExecutorFactory
{
    public static function make(): RetentionActionExecutor
    {
        $executor = function_exists('apply_filters')
            ? apply_filters('sabri_cf03_retention_action_executor', null)
            : null;
        if ($executor === null) {
            return new NullRetentionActionExecutor();
        }
        if (!$executor instanceof RetentionActionExecutor) {
            throw new InvariantViolation('Configured retention executor violates the CF-03 contract.');
        }
        return $executor;
    }
}
