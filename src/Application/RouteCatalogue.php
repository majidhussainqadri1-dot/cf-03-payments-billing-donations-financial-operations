<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

final class RouteCatalogue
{
    /** @return list<array<string,mixed>> */
    public static function definitions(): array
    {
        return [
            [
                'route' => '/pricing',
                'owner' => 'CF-03',
                'shell_owner' => 'File 20',
                'visual_owner' => 'File 25',
                'methods' => ['GET'],
                'authentication' => 'public',
                'cache' => 'public_by_locale_currency_policy_version',
                'index' => 'indexable',
                'status' => 'free_policy_active',
            ],
            [
                'route' => '/checkout/{product}',
                'owner' => 'CF-03',
                'shell_owner' => 'File 20',
                'visual_owner' => 'File 25',
                'methods' => ['POST'],
                'authentication' => 'authenticated_recent_auth',
                'cache' => 'no_store',
                'index' => 'noindex',
                'status' => 'paid_checkout_suspended_donation_provider_preparing',
            ],
            [
                'route' => '/billing',
                'owner' => 'CF-03',
                'shell_owner' => 'File 20',
                'visual_owner' => 'File 25',
                'methods' => ['GET'],
                'authentication' => 'authenticated_owner_scope',
                'cache' => 'private_no_store',
                'index' => 'noindex',
                'status' => 'contract_ready',
            ],
            [
                'route' => '/donate',
                'owner' => 'CF-03',
                'shell_owner' => 'File 20',
                'visual_owner' => 'File 25',
                'methods' => ['GET', 'POST'],
                'authentication' => 'public_read_authenticated_or_guest_intent',
                'cache' => 'public_copy_private_intent',
                'index' => 'indexable_copy_noindex_checkout',
                'status' => 'presentation_ready_collection_preparing',
            ],
            [
                'route' => '/billing/refunds/{id}',
                'owner' => 'CF-03',
                'shell_owner' => 'File 20',
                'visual_owner' => 'File 25',
                'methods' => ['GET', 'POST'],
                'authentication' => 'authenticated_owner_or_finance_scope',
                'cache' => 'private_no_store',
                'index' => 'noindex',
                'status' => 'contract_ready',
            ],
            [
                'route' => '/admin/finance',
                'owner' => 'CF-03',
                'shell_owner' => 'File 20',
                'visual_owner' => 'File 25',
                'methods' => ['GET', 'POST'],
                'authentication' => 'finance_capability_step_up',
                'cache' => 'private_no_store',
                'index' => 'noindex',
                'status' => 'contract_ready_runtime_fail_closed',
            ],
            [
                'route' => '/admin/finance/pricing',
                'owner' => 'CF-03',
                'shell_owner' => 'File 20',
                'visual_owner' => 'File 25',
                'methods' => ['GET', 'POST'],
                'authentication' => 'pricing_capability_dual_control',
                'cache' => 'private_no_store',
                'index' => 'noindex',
                'status' => 'paid_activation_requires_new_change_control',
            ],
            [
                'route' => '/api/finance/v1/*',
                'owner' => 'CF-03',
                'shell_owner' => null,
                'visual_owner' => null,
                'methods' => ['GET', 'POST'],
                'authentication' => 'versioned_capability_object_field_purpose',
                'cache' => 'endpoint_specific',
                'index' => 'noindex',
                'status' => 'contract_ready_runtime_fail_closed',
            ],
        ];
    }
}
