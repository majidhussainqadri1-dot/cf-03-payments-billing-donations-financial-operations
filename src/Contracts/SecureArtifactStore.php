<?php

declare(strict_types=1);

namespace Sabri\CF03\Contracts;

use DateTimeImmutable;

interface SecureArtifactStore
{
    /** @return array{object_ref:string,sha256:string,size_bytes:int} */
    public function put(string $filename, string $mediaType, string $contents, DateTimeImmutable $expiresAt): array;

    public function delete(string $objectReference): void;
}
