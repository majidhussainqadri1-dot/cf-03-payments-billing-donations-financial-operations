<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use JsonException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Contracts\SecureArtifactStore;
use Sabri\CF03\Domain\AuditEnvelope;
use Sabri\CF03\Domain\AuditOutcome;
use Sabri\CF03\Domain\FinancialDownloadGrant;
use Sabri\CF03\Support\InvariantViolation;
use Throwable;

final class FinancialDocumentService
{
    /** @var list<string> */
    private const SNAPSHOT_FIELDS = [
        'kind','intent_id','product_id','amount_minor','currency','settled_at','policy',
        'seller_legal_name','seller_country','customer_reference','line_items',
        'subtotal_minor','discount_minor','tax_minor','total_minor','payment_reference',
        'refund_references','invoice_number','issued_at','status',
    ];

    /** @var list<string> */
    private const PROHIBITED_KEY_FRAGMENTS = [
        'provider_ref','provider_reference','card','pan','cvv','cvc','pin','otp','password',
        'secret','token','bank_account','raw_body','customer_email','customer_phone',
    ];

    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly SecureArtifactStore $store,
        private readonly RuntimeConfiguration $configuration,
        private readonly FinancialAuditService $audit
    ) {}

    public function grantInvoice(
        string $invoiceId,
        string $actorReference,
        bool $financeOverride,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt
    ): FinancialDownloadGrant {
        return $this->privateInvoiceGrant('invoice', $invoiceId, $actorReference, $financeOverride, $now, $expiresAt);
    }

    public function grantReceipt(
        string $invoiceId,
        string $actorReference,
        bool $financeOverride,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt
    ): FinancialDownloadGrant {
        return $this->privateInvoiceGrant('receipt', $invoiceId, $actorReference, $financeOverride, $now, $expiresAt);
    }

    public function grantTransparencySnapshot(
        string $snapshotId,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt
    ): FinancialDownloadGrant {
        $this->configuration->assertDownloadDeliveryReady();
        $this->assertGrantWindow($now, $expiresAt);
        $record = $this->repository->get('transparency_snapshots', $snapshotId);
        if ($record === null || ($record['publication_state'] ?? null) !== 'published') {
            throw new InvariantViolation('Published financial transparency snapshot was not found.');
        }
        $snapshot = $record['snapshot_json'] ?? null;
        if (!is_array($snapshot)) {
            throw new InvariantViolation('Financial transparency snapshot payload is unavailable.');
        }
        $this->assertNoSensitiveKeys($snapshot, 'snapshot');
        $contents = $this->canonicalJson($snapshot)."\n";
        $filename = 'financial-transparency-'.self::fileToken($snapshotId).'.json';
        return $this->storeAndGrant(
            'transparency_snapshot',
            $snapshotId,
            'public',
            $filename,
            'application/json',
            $contents,
            $now,
            $expiresAt,
            'public_transparency_downloaded'
        );
    }

    private function privateInvoiceGrant(
        string $assetType,
        string $invoiceId,
        string $actorReference,
        bool $financeOverride,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt
    ): FinancialDownloadGrant {
        $this->configuration->assertDownloadDeliveryReady();
        $this->assertGrantWindow($now, $expiresAt);
        $invoice = $this->repository->get('invoices', $invoiceId);
        if ($invoice === null) {
            throw new InvariantViolation('Financial invoice snapshot was not found.');
        }
        if (!$financeOverride && !hash_equals((string)$invoice['actor_ref'], $actorReference)) {
            throw new InvariantViolation('Financial document is outside the authenticated owner scope.');
        }

        $snapshot = $invoice['snapshot_json'] ?? null;
        if (!is_array($snapshot)) {
            throw new InvariantViolation('Financial invoice snapshot payload is unavailable.');
        }
        $encoded = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)
            || !hash_equals((string)$invoice['snapshot_hash'], hash('sha256', $encoded))
        ) {
            throw new InvariantViolation('Financial invoice snapshot integrity verification failed.');
        }
        if ($assetType === 'receipt' && ($snapshot['kind'] ?? null) !== 'donation_receipt') {
            throw new InvariantViolation('Requested financial document is not a receipt snapshot.');
        }

        $safe = [];
        foreach (self::SNAPSHOT_FIELDS as $field) {
            if (array_key_exists($field, $snapshot)) {
                $safe[$field] = $snapshot[$field];
            }
        }
        $safe['invoice_id'] = $invoiceId;
        $safe['invoice_number'] = (string)$invoice['invoice_number'];
        $safe['status'] = (string)$invoice['status'];
        $safe['amount_minor'] = (int)$invoice['amount_minor'];
        $safe['currency'] = (string)$invoice['currency'];
        $safe['issued_at'] = self::dateString($invoice['issued_at'] ?? null);
        $this->assertNoSensitiveKeys($safe, 'document');

        $title = $assetType === 'receipt' ? 'Donation Receipt' : 'Financial Invoice';
        $contents = $this->htmlDocument($title, $safe);
        $filename = ($assetType === 'receipt' ? 'receipt-' : 'invoice-')
            .self::fileToken((string)$invoice['invoice_number']).'.html';

        return $this->storeAndGrant(
            $assetType,
            $invoiceId,
            $financeOverride ? 'finance:authorized' : $actorReference,
            $filename,
            'text/html',
            $contents,
            $now,
            $expiresAt,
            $assetType.'_downloaded'
        );
    }

    private function storeAndGrant(
        string $assetType,
        string $assetReference,
        string $audienceReference,
        string $filename,
        string $mediaType,
        string $contents,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
        string $auditAction
    ): FinancialDownloadGrant {
        $stored = $this->store->put($filename, $mediaType, $contents, $expiresAt);
        if (!isset($stored['object_ref'], $stored['sha256'], $stored['size_bytes'])
            || preg_match('/^[a-f0-9]{64}$/', (string)$stored['sha256']) !== 1
            || !hash_equals(hash('sha256', $contents), (string)$stored['sha256'])
            || (int)$stored['size_bytes'] !== strlen($contents)
        ) {
            throw new InvariantViolation('Secure financial document store returned inconsistent evidence.');
        }

        try {
            $this->audit->append(new AuditEnvelope(
                'audit:download:'.substr(hash('sha256', $assetType.'|'.$assetReference.'|'.$audienceReference.'|'.$now->format(DATE_ATOM)), 0, 32),
                $audienceReference,
                $auditAction,
                'financial_document',
                $assetReference,
                'authorized_financial_download',
                AuditOutcome::SUCCEEDED,
                $now,
                'trace:download:'.substr(hash('sha256', $assetType.'|'.$assetReference), 0, 24),
                [
                    'asset_type' => $assetType,
                    'sha256' => $stored['sha256'],
                    'expires_at' => $expiresAt->format(DATE_ATOM),
                ]
            ));
        } catch (Throwable $error) {
            $this->store->delete((string)$stored['object_ref']);
            throw $error;
        }

        return new FinancialDownloadGrant(
            'grant.document.'.substr(hash('sha256', $assetType.'|'.$assetReference.'|'.$audienceReference.'|'.$now->format(DATE_ATOM)), 0, 32),
            $assetType,
            $assetReference,
            $audienceReference,
            $filename,
            $mediaType,
            (string)$stored['sha256'],
            $now,
            $expiresAt,
            true,
            (string)$stored['object_ref']
        );
    }

    /** @param array<string,mixed> $data */
    private function assertNoSensitiveKeys(array $data, string $path): void
    {
        foreach ($data as $key => $value) {
            $normalized = strtolower((string)$key);
            foreach (self::PROHIBITED_KEY_FRAGMENTS as $fragment) {
                if (str_contains($normalized, $fragment)) {
                    throw new InvariantViolation('Sensitive financial document field was rejected at '.$path.'.'.$normalized.'.');
                }
            }
            if (is_array($value)) {
                $this->assertNoSensitiveKeys($value, $path.'.'.$normalized);
            } elseif (!is_scalar($value) && $value !== null) {
                throw new InvariantViolation('Financial document contains an unsupported value type.');
            }
        }
    }

    /** @param array<string,mixed> $data */
    private function htmlDocument(string $title, array $data): string
    {
        $rows = '';
        foreach ($data as $key => $value) {
            $display = is_array($value) ? $this->canonicalJson($value) : (string)($value ?? '');
            $rows .= '<tr><th scope="row">'.self::escape((string)$key).'</th><td><pre>'
                .self::escape($display).'</pre></td></tr>';
        }
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.self::escape($title).'</title><style>'
            .'body{font-family:system-ui,sans-serif;max-width:60rem;margin:2rem auto;padding:0 1rem;color:#17211a}'
            .'h1{color:#187a3d}table{border-collapse:collapse;width:100%}th,td{border:1px solid #c9d3cc;padding:.65rem;text-align:left;vertical-align:top}'
            .'th{width:14rem;background:#f3faf5}pre{white-space:pre-wrap;word-break:break-word;margin:0}'
            .'</style></head><body><h1>'.self::escape($title).'</h1><table><tbody>'.$rows.'</tbody></table>'
            .'<p>Integrity protected by SHA-256. This document contains no card credentials or provider secrets.</p>'
            .'</body></html>';
    }

    private function canonicalJson(mixed $value): string
    {
        $sort = static function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $sort($child);
            }
            return $item;
        };
        try {
            return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $error) {
            throw new InvariantViolation('Financial document could not be encoded.', 0, $error);
        }
    }

    private function assertGrantWindow(DateTimeImmutable $now, DateTimeImmutable $expiresAt): void
    {
        if ($expiresAt <= $now || $expiresAt > $now->modify('+30 minutes')) {
            throw new InvariantViolation('Financial document grant must expire within thirty minutes.');
        }
    }

    private static function fileToken(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value);
        return trim(is_string($safe) ? $safe : '', '-.') ?: substr(hash('sha256', $value), 0, 24);
    }

    private static function dateString(mixed $value): string
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->format(DATE_ATOM);
        }
        return is_string($value) ? $value : '';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
