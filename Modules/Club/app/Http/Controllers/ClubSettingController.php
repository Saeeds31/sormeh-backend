<?php

namespace Modules\Club\Http\Controllers;

use App\Support\CacheService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Club\Services\ClubSettingService;
use Modules\Settings\Models\Setting;

class ClubSettingController extends Controller
{
    public function __construct(protected ClubSettingService $settings) {}

    /**
     * GET /admin/club/settings/status
     * پنل با این چک می‌کند آیا تنظیمات کامل است یا دکمه ساخت نمایش دهد.
     */
    public function status()
    {
        return response()->json($this->settings->status());
    }

    /**
     * POST /admin/club/settings/ensure
     * ساخت تنظیمات. دکمه پنل این را صدا می‌زند.
     * body: { force?: bool }
     */
    public function ensure(Request $request)
    {
        $force = (bool) $request->boolean('force', false);

        $result = $this->settings->ensure($force);

        return response()->json([
            'message' => $force
                ? 'تنظیمات باشگاه همگام‌سازی شد.'
                : 'تنظیمات باشگاه بررسی و موارد جدید ساخته شد.',
            'result'  => $result,
            'status'  => $this->settings->status(),
        ]);
    }
    // GET /club/settings
    public function index()
    {
        $keys = array_column($this->settings->defaults(), 'key');
        $rows = Setting::whereIn('key', $keys)->get();

        // خروجی به شکل { key: value, ... }
        $out = [];
        foreach ($rows as $row) {
            $out[$row->key] = $row->value;
        }

        // کلیدهایی که در DB نیستند را با پیش‌فرض پر کن
        foreach ($this->settings->defaults() as $item) {
            if (!array_key_exists($item['key'], $out)) {
                $out[$item['key']] = $item['value'];
            }
        }

        return response()->json($out);
    }

    // PUT /club/settings
    public function update(Request $request)
    {
        $data = $request->all();

        // فقط کلیدهای مجاز
        $allowed = array_column($this->settings->defaults(), 'key');
        $allowed = array_flip($allowed);

        foreach ($data as $key => $value) {
            if (!isset($allowed[$key])) {
                continue;
            }
            $setting = Setting::where('key', $key)->first();
            if (!$setting) {
                continue;
            }
            $setting->value = $value;
            $setting->save();
        }

        CacheService::forgetSettings();

        return response()->json([
            'message' => 'تنظیمات با موفقیت ذخیره شد.',
        ]);
    }
}
