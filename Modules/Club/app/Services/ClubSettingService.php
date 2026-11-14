<?php

namespace Modules\Club\Services;

use App\Support\CacheService;
use Modules\Settings\Models\Setting;

class ClubSettingService
{
    /**
     * تعریف پیش‌فرض‌های باشگاه.
     * همین آرایه، منبع واحد حقیقت است.
     */
    public function defaults(): array
    {
        return [
            // پایه امتیازدهی
            ['key' => 'club.point_rate_amount',     'value' => '100000', 'type' => 'number',  'label' => 'مبلغ لازم برای هر امتیاز (تومان)', 'group' => 'club'],
            ['key' => 'club.point_rate_coins',      'value' => '1',      'type' => 'number',  'label' => 'تعداد کوین به ازای هر مبلغ',       'group' => 'club'],

            // سطح برنز
            ['key' => 'club.bronze_min',            'value' => '0',      'type' => 'number',  'label' => 'حداقل امتیاز سطح برنز',           'group' => 'club'],
            ['key' => 'club.bronze_discount',       'value' => '2',      'type' => 'number',  'label' => 'درصد تخفیف برنز',                 'group' => 'club'],
            ['key' => 'club.bronze_free_shipping',  'value' => '0',      'type' => 'number',  'label' => 'ارسال رایگان ماهانه برنز',        'group' => 'club'],

            // سطح گلد
            ['key' => 'club.gold_min',              'value' => '500',    'type' => 'number',  'label' => 'حداقل امتیاز سطح گلد',            'group' => 'club'],
            ['key' => 'club.gold_discount',         'value' => '3',      'type' => 'number',  'label' => 'درصد تخفیف گلد',                  'group' => 'club'],
            ['key' => 'club.gold_free_shipping',    'value' => '1',      'type' => 'number',  'label' => 'ارسال رایگان ماهانه گلد',         'group' => 'club'],

            // سطح پلاتینیوم
            ['key' => 'club.platinum_min',          'value' => '1000',   'type' => 'number',  'label' => 'حداقل امتیاز سطح پلاتینیوم',      'group' => 'club'],
            ['key' => 'club.platinum_discount',     'value' => '4',      'type' => 'number',  'label' => 'درصد تخفیف پلاتینیوم',            'group' => 'club'],
            ['key' => 'club.platinum_free_shipping', 'value' => '2',      'type' => 'number',  'label' => 'ارسال رایگان ماهانه پلاتینیوم',   'group' => 'club'],

            // کلید کلی
            ['key' => 'club.enabled',               'value' => '1',      'type' => 'boolean', 'label' => 'فعال بودن باشگاه مشتریان',        'group' => 'club'],
        ];
    }

    /**
     * وضعیت تنظیمات: کدام کلیدها هستند و کدام نیستند.
     */
    public function status(): array
    {
        $keys = array_column($this->defaults(), 'key');

        $existing = Setting::whereIn('key', $keys)->pluck('key')->all();
        $missing  = array_values(array_diff($keys, $existing));

        return [
            'total'    => count($keys),
            'existing' => count($existing),
            'missing'  => $missing,
            'is_complete' => empty($missing),
        ];
    }

    /**
     * ساخت تنظیمات.
     *
     * @param bool $force اگر true باشد، حتی مقادیر موجود را با پیش‌فرض بازنویسی می‌کند.
     *
     * @return array {created: string[], updated: string[], skipped: string[]}
     */
    public function ensure(bool $force = false): array
    {
        $created = [];
        $updated = [];
        $skipped = [];

        foreach ($this->defaults() as $item) {
            $setting = Setting::where('key', $item['key'])->first();

            if (! $setting) {
                Setting::create($item);
                $created[] = $item['key'];
                continue;
            }

            if ($force) {
                // فقط فیلدهای متادیتا را آپدیت می‌کنیم؛ value را دست نمی‌زنیم
                // مگر بخواهی value هم بازنویسی شود. اینجا value را حفظ می‌کنیم
                // ولی اگر خواستی force واقعی داشته باشی، خط زیر را فعال کن:
                // $setting->value = $item['value'];
                $setting->type  = $item['type'];
                $setting->label = $item['label'];
                $setting->group = $item['group'];
                $setting->save();
                $updated[] = $item['key'];
                continue;
            }

            $skipped[] = $item['key'];
        }

        // پاک کردن کش تنظیمات
        CacheService::forgetSettings();

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }
}
