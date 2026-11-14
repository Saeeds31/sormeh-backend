<?php

namespace Modules\Club\Services;

use Illuminate\Support\Facades\DB;
use Modules\Club\Models\UserPoint;
use Modules\Club\Models\PointTransaction;
use Modules\Orders\Models\Order;
use Modules\Pos\Models\PosOrder;
use Modules\Settings\Models\Setting;
use Modules\Users\Models\User;

class ClubService
{
    public function __construct(protected ClubLevelService $levels) {}

    /**
     * افزودن امتیاز به کاربر
     */
    public function addPoints(
        User $user,
        int $points,
        string $title,
        ?string $description = null,
        string $source = 'manual',
        $reference = null,
        ?int $adminId = null
    ): PointTransaction {
        return DB::transaction(function () use ($user, $points, $title, $description, $source, $reference, $adminId) {
            $tx = PointTransaction::create([
                'user_id'     => $user->id,
                'points'      => abs($points),
                'type'        => 'increase',
                'title'       => $title,
                'description' => $description,
                'source'      => $source,
                'reference_type' => $reference ? get_class($reference) : null,
                'reference_id'   => $reference?->id,
                'admin_id'    => $adminId,
            ]);

            $row = $this->rowFor($user);
            $row->increment('total_points', abs($points));
            $row->refresh();

            // بروزرسانی سطح
            $this->levels->syncLevel($row);

            return $tx;
        });
    }

    /**
     * کسر امتیاز از کاربر (مثلاً برای رست کردن یا جریمه)
     */
    public function deductPoints(
        User $user,
        int $points,
        string $title,
        ?string $description = null,
        string $source = 'manual',
        $reference = null,
        ?int $adminId = null
    ): PointTransaction {
        return DB::transaction(function () use ($user, $points, $title, $description, $source, $reference, $adminId) {
            $row = $this->rowFor($user);
            $deduct = min(abs($points), $row->total_points); // جلوگیری از منفی

            $tx = PointTransaction::create([
                'user_id'     => $user->id,
                'points'      => -$deduct,
                'type'        => 'decrease',
                'title'       => $title,
                'description' => $description,
                'source'      => $source,
                'reference_type' => $reference ? get_class($reference) : null,
                'reference_id'   => $reference?->id,
                'admin_id'    => $adminId,
            ]);

            $row->decrement('total_points', $deduct);
            $row->refresh();
            $this->levels->syncLevel($row);

            return $tx;
        });
    }

    /**
     * امتیازدهی بر اساس مبلغ سفارش (پس از پرداخت موفق)
     */
    public function awardForOrder(Order $order): ?PointTransaction
    {
        if (!Setting::where('key', 'club.enabled')->value('value')) return null;

        // جلوگیری از دوباره امتیاز گرفتن همان سفارش
        $exists = PointTransaction::where('reference_type', get_class($order))
            ->where('reference_id', $order->id)
            ->exists();
        if ($exists) return null;

        $rateAmount = (int) Setting::where('key', 'club.point_rate_amount')->value('value');
        $rateCoins  = (int) Setting::where('key', 'club.point_rate_coins')->value('value');
        if ($rateAmount <= 0) return null;

        $coins = intdiv((int) $order->total, $rateAmount) * $rateCoins;
        if ($coins <= 0) return null;

        return $this->addPoints(
            $order->user,
            $coins,
            "سفارش موفق",
            "تکمیل سفارش به شماره {$order->id}",
            'order',
            $order
        );
    }

    /**
     * امتیازدهی بر اساس مبلغ سفارش حضوری (پس از پرداخت موفق)
     */
    public function awardForPosOrder(PosOrder $order): ?PointTransaction
    {
        if (!Setting::where('key', 'club.enabled')->value('value')) return null;

        // جلوگیری از دوباره امتیاز گرفتن همان سفارش
        $exists = PointTransaction::where('reference_type', get_class($order))
            ->where('reference_id', $order->id)
            ->exists();
        if ($exists) return null;

        $rateAmount = (int) Setting::where('key', 'club.point_rate_amount')->value('value');
        $rateCoins  = (int) Setting::where('key', 'club.point_rate_coins')->value('value');
        if ($rateAmount <= 0) return null;

        $coins = intdiv((int) $order->total_amount, $rateAmount) * $rateCoins;
        if ($coins <= 0) return null;

        return $this->addPoints(
            $order->user,
            $coins,
            "سفارش حضوری موفق",
            " سفارش حضوری به شماره {$order->id}",
            'order',
            $order
        );
    }

    private function rowFor(User $user): UserPoint
    {
        return UserPoint::firstOrCreate(['user_id' => $user->id], [
            'total_points' => 0,
            'level'        => 'bronze',
        ]);
    }
    /**
     * برگرداندن امتیازی که قبلاً بابت یک سفارش  داده شده (برای لغو/مرجوع/ناموفق)
     * اگر امتیازی برای این سفارش  ثبت نشده باشد، کاری نمی‌کند.
     */
    public function reversePointsForOrder(Order $order): ?PointTransaction
    {
        if (!Setting::where('key', 'club.enabled')->value('value')) {
            return null;
        }

        // پیدا کردن تراکنش افزایشی که قبلاً بابت این سفارش ثبت شده
        $original = PointTransaction::where('reference_type', get_class($order))
            ->where('reference_id', $order->id)
            ->where('type', 'increase')
            ->first();

        if (!$original) {
            return null; // امتیازی داده نشده بود
        }

        // بررسی اینکه قبلاً برگشت داده نشده باشد (idempotent)
        $alreadyReversed = PointTransaction::where('reference_type', get_class($order))
            ->where('reference_id', $order->id)
            ->where('type', 'decrease')
            ->where('source', 'reversal')
            ->exists();

        if ($alreadyReversed) {
            return null;
        }

        return $this->deductPoints(
            $order->user,
            (int) $original->points,
            'لغو سفارش ',
            "برگشت امتیاز بابت لغو/مرجوع سفارش  #{$order->id}",
            'reversal',
            $order
        );
    }
    /**
     * برگرداندن امتیازی که قبلاً بابت یک سفارش حضوری داده شده (برای لغو/مرجوع/ناموفق)
     * اگر امتیازی برای این سفارش حضوری ثبت نشده باشد، کاری نمی‌کند.
     */
    public function reversePointsForPosOrder(PosOrder $order): ?PointTransaction
    {
        if (!Setting::where('key', 'club.enabled')->value('value')) {
            return null;
        }

        // پیدا کردن تراکنش افزایشی که قبلاً بابت این سفارش ثبت شده
        $original = PointTransaction::where('reference_type', get_class($order))
            ->where('reference_id', $order->id)
            ->where('type', 'increase')
            ->first();

        if (!$original) {
            return null; // امتیازی داده نشده بود
        }

        // بررسی اینکه قبلاً برگشت داده نشده باشد (idempotent)
        $alreadyReversed = PointTransaction::where('reference_type', get_class($order))
            ->where('reference_id', $order->id)
            ->where('type', 'decrease')
            ->where('source', 'reversal')
            ->exists();

        if ($alreadyReversed) {
            return null;
        }

        return $this->deductPoints(
            $order->user,
            (int) $original->points,
            'لغو سفارش حضوری',
            "برگشت امتیاز بابت لغو/مرجوع سفارش حضوری #{$order->id}",
            'reversal',
            $order
        );
    }

    /**
     * همگام‌سازی امتیاز یک سفارش با مبلغ فعلی آن.
     * اگر قبلاً امتیازی بابت این سفارش داده شده، اختلاف مبلغ جدید با مبلغی که
     * در آن زمان امتیاز گرفته، محاسبه و اعمال می‌شود.
     *
     * برای سفارش‌های paid استفاده می‌شود (تغییر آدرس، تغییر هزینه حمل، ...).
     */
    public function syncPointsForOrder(Order $order): void
    {
        if (!Setting::where('key', 'club.enabled')->value('value')) {
            return;
        }

        $rateAmount = (int) Setting::where('key', 'club.point_rate_amount')->value('value');
        $rateCoins  = (int) Setting::where('key', 'club.point_rate_coins')->value('value');

        if ($rateAmount <= 0) {
            return;
        }

        // امتیاز فعلی که کاربر بابت این سفارش گرفته
        $currentPoints = (int) PointTransaction::where('reference_type', get_class($order))
            ->where('reference_id', $order->id)
            ->where('type', 'increase')
            ->sum('points');

        // امتیاز برگشتی هم کم می‌شود
        $reversedPoints = abs((int) PointTransaction::where('reference_type', get_class($order))
            ->where('reference_id', $order->id)
            ->where('type', 'decrease')
            ->sum('points'));

        $netPoints = $currentPoints - $reversedPoints;

        // امتیاز مورد انتظار بر اساس مبلغ فعلی سفارش
        $expectedPoints = intdiv((int) $order->total, $rateAmount) * $rateCoins;

        $delta = $expectedPoints - $netPoints;

        if ($delta === 0) {
            return;
        }

        if ($delta > 0) {
            $this->addPoints(
                $order->user,
                $delta,
                'به‌روزرسانی امتیاز سفارش',
                "افزایش امتیاز بابت تغییر مبلغ سفارش #{$order->id} (مبلغ جدید: " . number_format($order->total) . ')',
                'order',
                $order
            );
        } else {
            $this->deductPoints(
                $order->user,
                abs($delta),
                'به‌روزرسانی امتیاز سفارش',
                "کاهش امتیاز بابت تغییر مبلغ سفارش #{$order->id} (مبلغ جدید: " . number_format($order->total) . ')',
                'order',
                $order
            );
        }
    }
}
