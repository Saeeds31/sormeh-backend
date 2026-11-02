<?php

namespace Modules\Products\Console\Commands;

use App\Services\SmsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Products\Models\ProductVariant;

class SendLowStockNotifications extends Command
{
    protected $signature = 'products:send-low-stock-notifications';

    protected $description = 'Send low stock alerts to admins for variants below product threshold.';

    private int $resetCount = 0;

    private int $productsProcessed = 0;

    private int $variantsNotified = 0;

    private int $failedCount = 0;

    public function handle(SmsService $sms): int
    {
        Log::channel('daily')->info(
            'products:send-low-stock-notifications started'
        );

        try {
            // ── گام ۱: ریست فلگ واریانت‌هایی که موجودی‌شون برگشته ──
            $this->resetReturnedStocks();

            // ── گام ۲: پیدا کردن کاندیدها ──
            $candidates = ProductVariant::lowStockCandidates()
                ->with(['product:id,title,sku,low_stock_threshold', 'values.attribute'])
                ->get();

            if ($candidates->isEmpty()) {
                $this->info('No low stock candidates found.');
                Log::channel('daily')->info(
                    "products:send-low-stock-notifications completed - " .
                        "Reset: {$this->resetCount}, No candidates."
                );
                return self::SUCCESS;
            }

            // ── گام ۳: گروه‌بندی بر اساس محصول و ارسال ──
            foreach ($candidates->groupBy('product_id') as $productId => $variants) {
                $this->processProduct((int) $productId, $variants, $sms);
            }

            $this->info(
                "Reset: {$this->resetCount}, " .
                    "Products: {$this->productsProcessed}, " .
                    "Variants notified: {$this->variantsNotified}, " .
                    "Failed: {$this->failedCount}"
            );

            Log::channel('daily')->info(
                "products:send-low-stock-notifications completed - " .
                    "Reset: {$this->resetCount}, " .
                    "Products: {$this->productsProcessed}, " .
                    "Variants: {$this->variantsNotified}, " .
                    "Failed: {$this->failedCount}"
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::channel('daily')->error(
                'products:send-low-stock-notifications failed: ' . $e->getMessage()
            );

            $this->error('Command failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * گام ۱: واریانت‌هایی که قبلاً هشدار گرفتن ولی موجودی‌شون
     * بالای آستانه برگشته → فلگشون NULL بشه تا دفعه‌ی بعد دوباره هشدار بگیرن.
     */
    private function resetReturnedStocks(): void
    {
        try {
            $this->resetCount = ProductVariant::needNotificationReset()->update([
                'product_variants.low_stock_notified_at' => null,
            ]);

            if ($this->resetCount > 0) {
                Log::channel('daily')->info(
                    "Low stock: {$this->resetCount} variants reset (stock returned)."
                );
            }
        } catch (\Throwable $e) {
            Log::channel('daily')->error(
                'Low stock reset failed: ' . $e->getMessage()
            );
            // ادامه بده؛ ریست شکست خورده نباید جلوی ارسال هشدارهای جدید رو بگیره
        }
    }

    /**
     * گام ۲ و ۳: برای یک محصول، خلاصه بساز، پیامک بفرست،
     * و فلگ واریانت‌هاش رو ست کن.
     */
    private function processProduct(int $productId, $variants, SmsService $sms): void
    {
        $product = $variants->first()->product;

        if (!$product || $product->low_stock_threshold === null) {
            return;
        }

        $summary = $variants->map(function ($v) {
            $label = $v->values->isNotEmpty()
                ? $v->values->map(fn($val) => $val->value)->implode(' / ')
                : ($v->sku ?: 'تک‌تنوع');
            return "{$label}={$v->stock}";
        })->implode('، ');

        try {
            $sms->sendToAdmins(
                template: 'low-stock-alert',
                token: $productId,
                extraData: [
                    'token2' => mb_substr($summary, 0, 25),
                    'token3' => (string) $product->low_stock_threshold,
                ]
            );
        } catch (\Throwable $e) {
            $this->failedCount++;
            Log::channel('daily')->error(
                "Low stock SMS failed for product #{$productId}: " . $e->getMessage()
            );
            return; // فلگ‌ها ست نمی‌شن که دفعه‌ی بعد دوباره تلاش بشه
        }

        // فلگ‌ها رو با query builder ست می‌کنیم که observerها/کش trigger نشن
        ProductVariant::whereIn('id', $variants->pluck('id'))->update([
            'low_stock_notified_at' => now(),
        ]);

        $this->productsProcessed++;
        $this->variantsNotified += $variants->count();

        Log::channel('daily')->info(
            "Low stock notified: product #{$productId} " .
                "({$variants->count()} variants, threshold={$product->low_stock_threshold})"
        );
    }
}
