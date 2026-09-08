<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use DateTimeImmutable;
use Sabri\CF03\Contracts\SecureArtifactStore;
use Sabri\CF03\Support\InvariantViolation;

final class NullSecureArtifactStore implements SecureArtifactStore
{
    public function put(string $filename, string $mediaType, string $contents, DateTimeImmutable $expiresAt): array
    {
        throw new InvariantViolation('No approved encrypted financial artifact store is configured.');
    }

    public function delete(string $objectReference): void
    {
        throw new InvariantViolation('No approved encrypted financial artifact store is configured.');
    }
}
