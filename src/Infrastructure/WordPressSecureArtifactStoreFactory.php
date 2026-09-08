<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use Sabri\CF03\Contracts\SecureArtifactStore;
use Sabri\CF03\Support\InvariantViolation;

final class WordPressSecureArtifactStoreFactory
{
    public static function make(): SecureArtifactStore
    {
        $store = function_exists('apply_filters')
            ? apply_filters('sabri_cf03_secure_artifact_store', null)
            : null;
        if ($store === null) {
            return new NullSecureArtifactStore();
        }
        if (!$store instanceof SecureArtifactStore) {
            throw new InvariantViolation('Configured financial artifact store violates the secure storage contract.');
        }
        return $store;
    }
}
