<?php

declare(strict_types=1);

namespace Sabri\CF03\Persistence;

use RuntimeException;

final class CompleteSchema
{
    public const VERSION='3.0.0';

    /** @return array<string,string> */
    public static function tables(string $prefix): array
    {
        $base=Schema::tables($prefix);$transparency=TransparencySchema::tables($prefix);
        if(array_intersect_key($base,$transparency)!==[]){throw new RuntimeException('CF-03 complete schema contains duplicate table identifiers.');}
        return array_merge($base,$transparency);
    }
}
