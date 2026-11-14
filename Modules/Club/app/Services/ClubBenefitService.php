<?php
// Modules/Club/Services/ClubBenefitService.php
namespace Modules\Club\Services;

use Modules\Club\Models\ClubBenefitUsage;
use Modules\Club\Models\UserPoint;
use Modules\Orders\Models\Order;
use Modules\Settings\Models\Setting;
use Modules\Users\Models\User;

class ClubBenefitService
{
    public function __construct(protected ClubLevelService $levels) {}

    /** درصد تخفیف فعال کاربر */
    public function userDiscountPercent(User $user): float
    {
        $row = UserPoint::where('user_id', $user->id)->first();
        return $row ? $this->levels->discountFor($row->level) : 0;
    }

    /**
     * محاسبه تخفیف قابل اعمال روی یک سفارش
     */
    public function applyDiscount(User $user, int $amount): array
    {
        $percent = $this->userDiscountPercent($user);
        if ($percent <= 0) return ['discount' => 0, 'percent' => 0];

        $discount = (int) round($amount * $percent / 100);
        return ['discount' => $discount, 'percent' => $percent];
    }
    /**
     * آیا کاربر در این ماه ارسال رایگان باقی‌مانده دارد؟
     */
    public function canUseFreeShipping(User $user): bool
    {
        $row = UserPoint::where('user_id', $user->id)->first();
        if (!$row) return false;

        $allowed = $this->levels->freeShippingFor($row->level);
        if ($allowed <= 0) return false;

        $used = ClubBenefitUsage::where('user_id', $user->id)
            ->where('benefit_type', 'free_shipping')
            ->where('period', now()->format('Y-m'))
            ->count();

        return $used < $allowed;
    }

    public function remainingFreeShipping(User $user): int
    {
        $row = UserPoint::where('user_id', $user->id)->first();
        if (!$row) return 0;

        $allowed = $this->levels->freeShippingFor($row->level);
        $used = ClubBenefitUsage::where('user_id', $user->id)
            ->where('benefit_type', 'free_shipping')
            ->where('period', now()->format('Y-m'))
            ->count();

        return max(0, $allowed - $used);
    }

    /**
     * ثبت استفاده از ارسال رایگان (در مرحله ۲ سفارش)
     */
    public function markFreeShippingUsed(User $user, Order $order): void
    {
        $row = UserPoint::where('user_id', $user->id)->first();
        ClubBenefitUsage::create([
            'user_id'      => $user->id,
            'order_id'     => $order->id,
            'benefit_type' => 'free_shipping',
            'level'        => $row?->level ?? 'bronze',
            'amount'       => 0,
            'period'       => now()->format('Y-m'),
        ]);
    }

    /**
     * ثبت استفاده از تخفیف (هنگام نهایی شدن سفارش)
     */
    public function markDiscountUsed(User $user, Order $order, float $discountAmount): void
    {
        if ($discountAmount <= 0) return;
        $row = UserPoint::where('user_id', $user->id)->first();
        ClubBenefitUsage::create([
            'user_id'      => $user->id,
            'order_id'     => $order->id,
            'benefit_type' => 'discount',
            'level'        => $row?->level ?? 'bronze',
            'amount'       => $discountAmount,
            'period'       => now()->format('Y-m'),
        ]);
    }
}
