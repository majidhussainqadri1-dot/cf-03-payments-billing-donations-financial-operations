<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use InvalidArgumentException;

final class FinancialReceiptIdentity
{
    public function __construct(
        private readonly string $sellerLegalName,
        private readonly string $sellerCountry
    ) {
        $name = trim($sellerLegalName);
        if ($name === '' || strlen($name) > 191) {
            throw new InvalidArgumentException('Financial receipt seller legal name is invalid.');
        }
        if (preg_match('/^[A-Z]{2}$/', $sellerCountry) !== 1) {
            throw new InvalidArgumentException('Financial receipt seller country must be an ISO 3166-1 alpha-2 code.');
        }
    }

    public function sellerLegalName(): string { return trim($this->sellerLegalName); }
    public function sellerCountry(): string { return $this->sellerCountry; }

    /** @return array{seller_legal_name:string,seller_country:string} */
    public function toSnapshot(): array
    {
        return [
            'seller_legal_name' => $this->sellerLegalName(),
            'seller_country' => $this->sellerCountry,
        ];
    }
}
