<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use Sabri\CF03\Application\BillingQueryService;
use Throwable;

final class WordPressPrivacy
{
    /** @param array<string,string> $exporters @return array<string,array<string,mixed>> */
    public static function exporters(array $exporters): array
    {
        $exporters['sabri-cf03'] = [
            'exporter_friendly_name' => 'Sabri Financial Records',
            'callback' => [self::class, 'export'],
        ];
        return $exporters;
    }

    /** @param array<string,string> $erasers @return array<string,array<string,mixed>> */
    public static function erasers(array $erasers): array
    {
        $erasers['sabri-cf03'] = [
            'eraser_friendly_name' => 'Sabri Financial Preferences',
            'callback' => [self::class, 'erase'],
        ];
        return $erasers;
    }

    /** @return array<string,mixed> */
    public static function export(string $emailAddress, int $page = 1): array
    {
        $user = self::userByEmail($emailAddress);
        if ($user === null) {
            return ['data' => [], 'done' => true];
        }
        if ($page < 1) {
            $page = 1;
        }

        try {
            $actor = 'user:'.(int)$user->ID;
            $records = (new BillingQueryService(WordPressFinancialRepository::fromWordPress()))
                ->forActorPage($actor, $page, 20);
        } catch (Throwable) {
            return [
                'data' => [[
                    'group_id' => 'sabri-cf03-status',
                    'group_label' => 'Sabri Financial Export Status',
                    'item_id' => 'sabri-cf03-export-unavailable-'.$page,
                    'data' => [[
                        'name' => 'status',
                        'value' => 'Financial records could not be read safely during this export request.',
                    ]],
                ]],
                'done' => true,
            ];
        }

        $data = [];
        foreach (['invoices','donations','subscriptions','refunds','exports'] as $group) {
            foreach ($records[$group] ?? [] as $index => $record) {
                $items = [];
                foreach ($record as $name => $value) {
                    $items[] = [
                        'name' => (string)$name,
                        'value' => self::displayValue($value),
                    ];
                }
                $data[] = [
                    'group_id' => 'sabri-cf03-'.$group,
                    'group_label' => 'Sabri Financial '.ucfirst($group),
                    'item_id' => 'sabri-cf03-'.hash(
                        'sha256',
                        $group.'|'.$page.'|'.$index.'|'.self::displayValue($record)
                    ),
                    'data' => $items,
                ];
            }
        }
        return ['data' => $data, 'done' => (bool)($records['done'] ?? true)];
    }

    /** @return array<string,mixed> */
    public static function erase(string $emailAddress, int $page = 1): array
    {
        $user = self::userByEmail($emailAddress);
        if ($user === null) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }

        $removed = false;
        $messages = [];
        foreach ([
            'last_donation_prompt_at',
            'next_donation_prompt_at',
            'donation_prompt_status',
            'donation_prompt_snoozed_until',
            'last_donation_completed_at',
            'recurring_donation_status',
            'donation_frequency_preference',
        ] as $key) {
            if (function_exists('delete_user_meta')) {
                $removed = (bool)delete_user_meta((int)$user->ID, $key) || $removed;
            }
        }

        try {
            $repo = WordPressFinancialRepository::fromWordPress();
            $actor = 'user:'.(int)$user->ID;
            $acknowledgments = $repo->find('donor_acknowledgments', ['donor_ref' => $actor], 500);
            foreach ($acknowledgments as $record) {
                if (($record['state'] ?? null) !== 'active') {
                    continue;
                }
                $repo->updateWhere(
                    'donor_acknowledgments',
                    ['acknowledgment_id' => $record['acknowledgment_id'], 'state' => 'active'],
                    [
                        'display_name' => 'Anonymous donor',
                        'state' => 'revoked',
                        'revoked_at' => new \DateTimeImmutable('now'),
                    ]
                );
                $removed = true;
            }
        } catch (Throwable) {
            $messages[] = 'Optional public donor acknowledgment could not be revalidated during this erasure request; contact financial support for manual completion.';
        }

        $messages[] = 'Financial ledgers, receipts, settlements, audit evidence and legally required accounting records are retained under the applicable retention policy; optional prompt state and public acknowledgment are removed or revoked.';
        return [
            'items_removed' => $removed,
            'items_retained' => true,
            'messages' => $messages,
            'done' => true,
        ];
    }

    private static function userByEmail(string $email): ?object
    {
        if (!function_exists('get_user_by')) {
            return null;
        }
        $user = get_user_by('email', $email);
        return is_object($user) && isset($user->ID) ? $user : null;
    }

    private static function displayValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : '[unavailable]';
    }
}
