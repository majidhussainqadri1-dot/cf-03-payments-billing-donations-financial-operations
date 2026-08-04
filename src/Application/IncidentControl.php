<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

final class IncidentControl
{
    public function __construct(
        private bool $checkoutEnabled = false,
        private bool $refundsEnabled = false,
        private bool $webhooksEnabled = false
    ) {}

    public function enableAfterApproval(): void { $this->checkoutEnabled = $this->refundsEnabled = $this->webhooksEnabled = true; }
    public function killCheckout(): void { $this->checkoutEnabled = false; }
    public function killRefunds(): void { $this->refundsEnabled = false; }
    public function killWebhooks(): void { $this->webhooksEnabled = false; }
    /** @return array<string,bool> */ public function status(): array { return ['checkout'=>$this->checkoutEnabled,'refunds'=>$this->refundsEnabled,'webhooks'=>$this->webhooksEnabled]; }
}
