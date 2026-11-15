<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Domain\PlatformFinancialPolicy;
use Sabri\CF03\Support\InvariantViolation;

/**
 * CF03-FR-007/028: pre-provider, owner-scoped admission gate.
 *
 * WordPress add_option() relies on the wp_options.option_name UNIQUE key,
 * so two workers cannot both claim the same slot. This is only a bounded,
 * fixed-window local abuse control; CDN/WAF and provider anti-abuse evidence
 * remain independent production release gates.
 *
 * No raw IP, guest reference, username, donor identity or payment data
 * is persisted. Site-keyed HMAC identifiers live in non-autoloaded options.
 * This code never selects limits for the Founder: an explicitly approved
 * versioned policy is mandatory. Missing APIs/policy/salt fail closed.
 */
final class WordPressCheckoutRateGate
{
    public const OPTION_POLICY = 'sabri_cf03_checkout_rate_policy';
    public const PREFIX = 'sabri_cf03_rate_';
    public const RETAIN_AFTER_BUCKET_SECONDS = 86400;

    /**
     * @return null|array{window_seconds:int,max_new_intents:int}
     */
    public static function approvedPolicy(): ?array
    {
        if (!function_exists('get_option')) {
            return null;
        }
        $policy = get_option(self::OPTION_POLICY, null);
        if (!is_array($policy)
            || ($policy['approved'] ?? null) !== true
            || ($policy['policy_version'] ?? null) !== PlatformFinancialPolicy::DECISION_ID
            || ($policy['approved_by'] ?? null) !== 'founder:financial-change-control'
        ) {
            return null;
        }

        $approvalRef = $policy['approval_ref'] ?? null;
        $window = $policy['window_seconds'] ?? null;
        $maximum = $policy['max_new_intents'] ?? null;
        if (!is_string($approvalRef)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $approvalRef) !== 1
            || !is_int($window) || $window < 60 || $window > 3600 || $window % 60 !== 0
            || !is_int($maximum) || $maximum < 1 || $maximum > 12
        ) {
            return null;
        }
        return ['window_seconds' => $window, 'max_new_intents' => $maximum];
    }

    public static function configured(): bool
    {
        if (self::approvedPolicy() === null
            || !function_exists('add_option')
            || !function_exists('wp_salt')
        ) {
            return false;
        }
        $salt = wp_salt('auth');
        return is_string($salt) && strlen($salt) >= 32;
    }

    /**
     * Reserve at most one slot per unique actor + idempotency key per window.
     * Duplicate idempotency replays do not consume a second slot.
     * The reservation is deliberately placed before any provider side effect.
     *
     * @throws InvariantViolation for unavailable/unapproved storage/policy
     * @throws CheckoutRateLimitExceeded when the approved local window is full
     */
    public static function assertPermitted(
        string $actorReference,
        string $idempotencyKey,
        DateTimeImmutable $at
    ): void {
        if (preg_match('/^(?:user:[1-9][0-9]*|guest:[a-f0-9]{40})$/D', $actorReference) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/D', $idempotencyKey) !== 1
        ) {
            throw new InvalidArgumentException('Financial rate-limit subject or request identity is invalid.');
        }

        $policy = self::approvedPolicy();
        if ($policy === null || !function_exists('add_option') || !function_exists('wp_salt')) {
            throw new InvariantViolation('Approved atomic pre-provider rate policy is not available.');
        }
        $salt = wp_salt('auth');
        if (!is_string($salt) || strlen($salt) < 32) {
            throw new InvariantViolation('Financial rate-limit identity key is unavailable.');
        }

        $window = $policy['window_seconds'];
        $index = intdiv($at->getTimestamp(), $window);
        $expiry = ($index + 1) * $window + self::RETAIN_AFTER_BUCKET_SECONDS;
        $actorHash = substr(hash_hmac('sha256', $actorReference, $salt), 0, 40);
        $requestHash = hash_hmac('sha256', $actorReference.'|'.$idempotencyKey, $salt);
        $prefix = self::PREFIX.sprintf('%010d', $expiry).'_'.$actorHash.'_';

        // Look for an already reserved stable request first. A retry in the
        // same time bucket cannot bypass the user's approved attempt ceiling.
        for ($slot = 0; $slot < $policy['max_new_intents']; $slot++) {
            if (get_option($prefix.sprintf('%02d', $slot), false) === $requestHash) {
                return;
            }
        }

        // Unique option_name guarantees a durable atomic winner per slot on
        // normal WordPress MySQL. All storage failures are treated as denial.
        for ($slot = 0; $slot < $policy['max_new_intents']; $slot++) {
            $name = $prefix.sprintf('%02d', $slot);
            $previous = get_option($name, false);
            if ($previous === $requestHash) {
                return;
            }
            if ($previous !== false) {
                continue;
            }
            if (add_option($name, $requestHash, '', false) === true) {
                return;
            }
            // A competing request may have claimed this very slot.
            if (get_option($name, false) === $requestHash) {
                return;
            }
        }

        $retryAfter = $window - ($at->getTimestamp() % $window);
        throw new CheckoutRateLimitExceeded(max(1, $retryAfter));
    }

    /**
     * Bounded, cache-safe garbage collection of expired, pseudonymous slots.
     * Call from existing CF-03 scheduled processing; no additional DB table.
     * @return int Number of expired reservations successfully removed
     */
    public static function cleanExpired(DateTimeImmutable $now, int $limit = 100): int
    {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('Rate-limit cleanup batch is invalid.');
        }
        if (!function_exists('delete_option')) {
            return 0;
        }
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)
            || !isset($wpdb->options) || !is_string($wpdb->options)
            || preg_match('/^[A-Za-z0-9_]+$/D', $wpdb->options) !== 1
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'get_col')
            || !method_exists($wpdb, 'esc_like')
        ) {
            return 0;
        }

        $cutoff = self::PREFIX.sprintf('%010d', $now->getTimestamp()).'_';
        $like = $wpdb->esc_like(self::PREFIX).'%';
        $query = $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE %s AND option_name < %s
             ORDER BY option_name ASC LIMIT %d",
            $like, $cutoff, $limit
        );
        if (!is_string($query) || $query === '') {
            return 0;
        }
        $names = $wpdb->get_col($query);
        if (!is_array($names)) {
            return 0;
        }

        $deleted = 0;
        foreach ($names as $name) {
            if (!is_string($name)
                || preg_match('/^sabri_cf03_rate_[0-9]{10}_[a-f0-9]{40}_[0-9]{2}$/D', $name) !== 1
                || strcmp($name, $cutoff) >= 0
            ) {
                continue;
            }
            if (delete_option($name) === true) {
                $deleted++;
            }
        }
        return $deleted;
    }
}
