<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Application\FinancialDownloadContract;
use Sabri\CF03\Support\InvariantViolation;

final class FinancialDownloadGrant
{
    private const MEDIA_TYPES = [
        'application/pdf',
        'application/json',
        'text/csv',
        'application/zip',
    ];

    private const DENIAL_REASONS = [
        'not_owner',
        'not_authorized',
        'expired',
        'revoked',
        'not_ready',
        'rights_restricted',
        'privacy_restricted',
        'legal_hold',
        'live_delivery_disabled',
    ];

    public function __construct(
        private readonly string $grantId,
        private readonly string $assetType,
        private readonly string $assetReference,
        private readonly string $audienceReference,
        private readonly string $fileName,
        private readonly string $mediaType,
        private readonly string $sha256,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $expiresAt,
        private readonly bool $downloadAllowed,
        private readonly ?string $deliveryReference = null,
        private readonly ?string $denialReason = null,
        private readonly string $policyVersion = FinancialDownloadContract::CONTRACT_VERSION
    ) {
        foreach ([$grantId, $assetReference, $audienceReference] as $reference) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $reference) !== 1) {
                throw new InvalidArgumentException('Financial download identity reference is invalid.');
            }
        }
        if (! in_array($assetType, FinancialDownloadContract::eligibleAssetTypes(), true)) {
            throw new InvalidArgumentException('Financial download asset type is not approved.');
        }
        if (! in_array($mediaType, self::MEDIA_TYPES, true)) {
            throw new InvalidArgumentException('Financial download media type is not approved.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new InvalidArgumentException('Financial download checksum must be SHA-256.');
        }
        self::assertSafeFileName($fileName);
        if ($expiresAt <= $createdAt) {
            throw new InvalidArgumentException('Financial download expiry must be after creation.');
        }
        if (preg_match('/^[0-9]+\.[0-9]+$/', $policyVersion) !== 1) {
            throw new InvalidArgumentException('Financial download policy version is invalid.');
        }

        if ($downloadAllowed) {
            if ($denialReason !== null || $deliveryReference === null) {
                throw new InvariantViolation('Allowed financial download requires delivery evidence and no denial reason.');
            }
            self::assertDeliveryReference($deliveryReference);
        } else {
            if ($deliveryReference !== null
                || $denialReason === null
                || ! in_array($denialReason, self::DENIAL_REASONS, true)
            ) {
                throw new InvariantViolation('Denied financial download requires an approved reason and no delivery reference.');
            }
        }
    }

    public function assertUsableBy(string $audienceReference, DateTimeImmutable $now): void
    {
        if (! $this->downloadAllowed || $this->deliveryReference === null) {
            throw new InvariantViolation('Financial download is not permitted.');
        }
        if ($now >= $this->expiresAt) {
            throw new InvariantViolation('Financial download grant has expired.');
        }
        if ($this->audienceReference !== 'public'
            && ! hash_equals($this->audienceReference, $audienceReference)
        ) {
            throw new InvariantViolation('Financial download audience does not match.');
        }
    }

    /** @return array<string,mixed> */
    public function toPresentationContract(): array
    {
        return [
            'grant_id' => $this->grantId,
            'asset_type' => $this->assetType,
            'asset_reference' => $this->assetReference,
            'file_name' => $this->fileName,
            'media_type' => $this->mediaType,
            'sha256' => $this->sha256,
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
            'download_allowed' => $this->downloadAllowed,
            'denial_reason' => $this->denialReason,
            'policy_version' => $this->policyVersion,
            'delivery_reference' => $this->downloadAllowed ? $this->deliveryReference : null,
        ];
    }

    private static function assertSafeFileName(string $fileName): void
    {
        if (strlen($fileName) < 3
            || strlen($fileName) > 180
            || trim($fileName) !== $fileName
            || str_contains($fileName, '..')
            || str_contains($fileName, '/')
            || str_contains($fileName, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $fileName) === 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._ ()-]{1,178}[A-Za-z0-9]$/', $fileName) !== 1
        ) {
            throw new InvalidArgumentException('Financial download filename is unsafe.');
        }
    }

    private static function assertDeliveryReference(string $reference): void
    {
        if (strlen($reference) < 3
            || strlen($reference) > 255
            || preg_match('/[\x00-\x20\x7F\\?#%]/', $reference) === 1
            || str_contains($reference, '..')
        ) {
            throw new InvalidArgumentException('Financial download delivery reference is unsafe.');
        }
        $opaque = preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $reference) === 1;
        $vault = preg_match('#^vault://[A-Za-z0-9][A-Za-z0-9._-]{1,62}/[A-Za-z0-9][A-Za-z0-9._/-]{1,180}$#', $reference) === 1
            && ! str_contains(substr($reference, 8), '//');
        if (! $opaque && ! $vault) {
            throw new InvalidArgumentException('Financial download delivery reference must be opaque or vault-scoped.');
        }
    }
}
