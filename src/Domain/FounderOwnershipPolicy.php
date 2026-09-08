<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use InvalidArgumentException;

final class FounderOwnershipPolicy
{
    public const OWNER = 'Dr. Allamah Majid Hussain Sabri Muhaddith Murshid';
    public const OWNER_UR = 'ڈاکٹر علامہ ماجد حسین صابری محدث مرشد';

    /** @return array<string,bool> */
    public function donorRights(): array
    {
        return ['ownership'=>false,'partnership'=>false,'shareholding'=>false,'governance_authority'=>false,'voting_right'=>false,'intellectual_property_right'=>false,'management_control'=>false,'mandatory_profit_share'=>false,'platform_privilege'=>false];
    }

    public function assertNoDonorRight(string $right): void
    {
        $rights=$this->donorRights();
        if (! array_key_exists($right,$rights)) { throw new InvalidArgumentException('Unknown donor-right classification.'); }
        if ($rights[$right] !== false) { throw new InvalidArgumentException('Donation must not create ownership or platform rights.'); }
    }

    /** @return array<string,mixed> */
    public function toPublicDisclosure(): array
    {
        return ['founder_owned'=>true,'owner'=>self::OWNER,'owner_ur'=>self::OWNER_UR,'is_trust'=>false,'is_charitable_trust'=>false,'correct_description'=>'Founder-Owned Platform supported through voluntary donations.','correct_description_ur'=>'بانی کی ملکیت میں قائم پلیٹ فارم، جو رضاکارانہ عطیات اور تعاون سے چلایا جاتا ہے۔','donor_rights'=>$this->donorRights()];
    }
}
