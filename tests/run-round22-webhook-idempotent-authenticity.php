<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\ProviderWebhookVerifier;
use Sabri\CF03\Domain\PaymentIntentState;
use Sabri\CF03\Domain\ProviderEventStateMapper;

$now = new DateTimeImmutable('2026-09-18T09:40:00+00:00');
$raw = json_encode([
    'event_id' => 'event.retry.0001',
    'type' => 'payment.settled',
    'intent_id' => 'intent.retry.0001',
    'amount_minor' => 1400,
    'currency' => 'USD',
    'occurred_at' => $now->modify('-5 seconds')->format(DATE_ATOM),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
$key = str_repeat('k', 32);
$signatureAt = $now->modify('-2 seconds');
$signature = hash_hmac('sha256', $signatureAt->getTimestamp().'.'.$raw, $key);

$verifier = new ProviderWebhookVerifier(
    static fn(string $provider, string $version): string => $key,
    static fn(string $provider, string $eventId): bool => false
);
$evidence = $verifier->verify(
    'provider.test',
    'key.v1',
    $raw,
    $signature,
    $signatureAt,
    $now
);
if ($evidence->eventIdUnique() !== false) {
    throw new RuntimeException('Regression fixture must represent an exact previously-seen provider event.');
}
$mapped = (new ProviderEventStateMapper())->mapAuthenticForIdempotentIngestion($evidence);
if ($mapped !== PaymentIntentState::SETTLED) {
    throw new RuntimeException('Authentic duplicate provider event must reach canonical inbox dedupe.');
}

$legacyRejected = false;
try {
    (new ProviderEventStateMapper())->mapTrusted($evidence);
} catch (Throwable) {
    $legacyRejected = true;
}
if (!$legacyRejected) {
    throw new RuntimeException('Legacy trust consumers must still require unique event evidence.');
}

$source = (string)file_get_contents(dirname(__DIR__).'/src/Application/WebhookIngestionService.php');
if (!str_contains($source, 'mapAuthenticForIdempotentIngestion')) {
    throw new RuntimeException('Webhook ingestion must use authentic-but-retryable mapping.');
}

fwrite(STDOUT, "PASS: signed webhook retries reach durable inbox dedupe without weakening legacy uniqueness trust\n");
