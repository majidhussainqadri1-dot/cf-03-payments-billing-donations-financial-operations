<?php

declare(strict_types=1);

namespace Sabri\CF03\Contracts;

interface RetentionActionExecutor
{
    public function archive(string $recordType,string $recordReference):string;
    public function anonymize(string $recordType,string $recordReference):string;
    public function delete(string $recordType,string $recordReference):string;
}
