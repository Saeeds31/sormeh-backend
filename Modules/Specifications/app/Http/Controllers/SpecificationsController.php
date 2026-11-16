<?php

namespace Modules\Specifications\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Club\Models\PointTransaction;
use Modules\Club\Services\ClubService;
use Modules\Notifications\Services\NotificationService;
use Modules\Products\Models\Product;
use Modules\Settings\Models\Setting;
use Modules\Specifications\Http\Requests\StoreSpecificationRequest;
use Modules\Specifications\Http\Requests\StoreUserSelectionsRequest;
use Modules\Specifications\Http\Requests\UpdateSpecificationRequest;
use Modules\Specifications\Models\Specification;
use Modules\Specifications\Models\SpecificationValue;
use Modules\Specifications\Models\UserSpecificationSelection;
use Modules\Users\Models\User;

class SpecificationsController extends Controller
{
    /**
     * Store a new specification with its values
     */
    public function store(StoreSpecificationRequest $request, NotificationService $notifications)
    {
        // ایجاد مشخصه
        $spec = Specification::create([
            'title' => $request->title,
        ]);

        // ذخیره مقادیر
        if ($request->has('values')) {
            foreach ($request->values as $value) {
                if ($value && trim($value) !== '') {
                    SpecificationValue::create([
                        'specification_id' => $spec->id,
                        'value' => $value,
                    ]);
                }
            }
        }
        $notifications->create(
            "ثبت جدول مشخصات",
            "جدول مشخصات {$spec->title} در سیستم ثبت شد",
            "notification_product",
            ['specification' => $spec->id]
        );
        return response()->json([
            'message' => 'جدول مشخصات با موفقیت ثبت شد',
            'data' => $spec->load('values'),
        ], 201);
    }

    /**
     * Update an existing specification and its values
     */
    public function update(UpdateSpecificationRequest $request, $id, NotificationService $notifications)
    {
        $spec = Specification::findOrFail($id);

        // آپدیت عنوان
        $spec->update([
            'title' => $request->title,
        ]);

        // پاک کردن مقادیر قبلی و ثبت جدید
        $spec->values()->delete();

        if ($request->has('values')) {
            foreach ($request->values as $value) {
                if ($value && trim($value) !== '') {
                    SpecificationValue::create([
                        'specification_id' => $spec->id,
                        'value' => $value,
                    ]);
                }
            }
        }
        $notifications->create(
            "ویرایش جدول مشخصات",
            "جدول مشخصات {$spec->title} در سیستم ویرایش شد",
            "notification_product",
            ['specification' => $spec->id]
        );
        return response()->json([
            'message' => 'جدول مشخصات با موفقیت ویرایش شد',
            'data' => $spec->load('values'),
        ]);
    }

    /**
     * Show a specification with its values
     */
    public function show($id)
    {
        $spec = Specification::findOrFail($id);
        return response()->json($spec->load('values'));
    }

    /**
     * Delete a specification and its values
     */
    public function destroy($id, NotificationService $notifications)
    {
        $spec = Specification::findOrFail($id);
        if ($spec->is_system) {
            return response()->json([
                'message' => 'این مشخصه سیستمی است و قابل حذف نیست.',
                'success' => false,
            ], 403);
        }
        $notifications->create(
            "حذف جدول مشخصات",
            "جدول مشخصات {$spec->title} از سیستم حذف شد",
            "notification_product",
            ['specification' => $spec->id]
        );
        $spec->values()->delete();
        $spec->delete();
        return response()->json([
            'message' => 'جدول مشخصات با موفقیت حذف شد',
        ]);
    }

    /**
     * List all specifications with their values
     */
    public function index()
    {
        $data = Specification::with('values')->paginate(25);
        return response()->json(
            [
                'message' => "جدول مشخصات",
                'success' => true,
                'data' => $data
            ]
        );
    }
    public function allSpecifications()
    {
        $data = Specification::with('values')->get();
        return response()->json(
            [
                'message' => "جدول مشخصات",
                'success' => true,
                'data' => $data
            ]
        );
    }
    public function syncSpecifications(Request $request, $productId)
    {
        $product = Product::findOrFail($productId);
        // پاک کردن تمام روابط قبلی برای این محصول در جدول واسط
        $product->specifications()->detach();
        // اضافه کردن مقادیر جدید
        foreach ($request->specifications as $spec) {
            $product->specifications()->attach($spec['specification_id'], [
                'specification_value_id' => $spec['specification_value_id'] ?? null,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
        // همزمان مقادیر جدید اضافه می‌کنه و مقادیر قبلی رو پاک می‌کنه
        return response()->json(
            [
                'message' => "جدول مشخصات",
                'success' => true,
                'data' => $product,
            ]
        );
    }
    /**
     *   برگرداندن مشخصات سیستمی همراه با مقادیرشان
     * GET /specifications/system
     */
    public function systemSpecifications()
    {
        $data = Specification::system()
            ->with(['values' => function ($q) {
                $q->orderBy('id');
            }])
            ->get();

        return response()->json([
            'message' => 'مشخصات سیستمی',
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     *  ذخیره انتخاب‌های کاربر
     * POST /specifications/user-selections
     */
    public function storeUserSelections(StoreUserSelectionsRequest $request)
    {
        $userId = $request->user()->id;
        $user   = $request->user();

        // ================================================================
        // باشگاه مشتریان: بررسی اینکه آیا کاربر قبلاً انتخابی داشته
        // ================================================================
        $hadSelectionsBefore = UserSpecificationSelection::where('user_id', $userId)->exists();

        DB::transaction(function () use ($request, $userId) {
            // پاک کردن انتخاب‌های قبلی (sync کامل)
            UserSpecificationSelection::where('user_id', $userId)->delete();

            $rows = [];
            foreach ($request->selections as $sel) {
                $rows[] = [
                    'user_id' => $userId,
                    'specification_id' => $sel['specification_id'],
                    'specification_value_id' => $sel['specification_value_id'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            UserSpecificationSelection::insert($rows);
        });

        $selections = UserSpecificationSelection::where('user_id', $userId)
            ->with(['specification:id,title', 'specificationValue:id,value'])
            ->get();

        // ================================================================
        // باشگاه مشتریان: اگر قبلاً انتخاب نداشت و حالا دارد → امتیاز بده
        // ================================================================
        if (!$hadSelectionsBefore && $selections->isNotEmpty()) {
            $this->awardPreferencesCompletionPoints($user);
        }

        return response()->json([
            'message' => 'انتخاب‌های شما با موفقیت ذخیره شد.',
            'success' => true,
            'data' => $selections,
        ]);
    }

    /**
     * اعطای امتیاز تکمیل انتخاب سلیقه/مشخصات (یک‌بار برای هر کاربر).
     */
    protected function awardPreferencesCompletionPoints(User $user): void
    {
        $clubEnabled = (bool) Setting::where('key', 'club.enabled')->value('value');
        if (!$clubEnabled) {
            return;
        }

        $points = (int) Setting::where('key', 'club.preferences_completion_points')->value('value');
        if ($points <= 0) {
            return;
        }

        // جلوگیری از امتیاز تکراری
        $alreadyAwarded = PointTransaction::where('user_id', $user->id)
            ->where('source', 'preferences')
            ->exists();

        if ($alreadyAwarded) {
            return;
        }

        app(ClubService::class)->addPoints(
            $user,
            $points,
            'تکمیل انتخاب سلیقه',
            'تکمیل انتخاب سلیقه/مشخصات کاربری',
            'preferences',
            null
        );
    }

    /**
     *  پیشنهاد محصول بر اساس انتخاب‌های کاربر
     * GET /specifications/recommendations
     */
    public function recommendations(Request $request)
    {
        $userId = $request->user()->id;

        $selections = UserSpecificationSelection::where('user_id', $userId)->get();

        if ($selections->isEmpty()) {
            return response()->json([
                'message' => 'ابتدا مشخصات خود را انتخاب کنید.',
                'success' => false,
                'data' => [],
            ], 422);
        }

        $specIds = $selections->pluck('specification_id')->unique()->values();
        $valueIds = $selections->pluck('specification_value_id')->unique()->values();

        // محصولاتی که با این مقدارها در جدول واسط تطابق دارند
        // pivot: product_id, specification_id, specification_value_id
        $query = Product::query()
            ->whereHas('specifications', function ($q) use ($valueIds) {
                $q->whereIn('product_specifications.specification_value_id', $valueIds);
            });

        // امتیازدهی: هرچه تعداد تطابق بیشتر باشد محصول بالاتر می‌آید
        $products = $query
            ->withCount(['specifications as matched_count' => function ($q) use ($valueIds) {
                $q->whereIn('product_specifications.specification_value_id', $valueIds);
            }])
            ->orderByDesc('matched_count')
            ->limit(20)
            ->get();

        // فیلتر نهایی: محصول باید همه مشخصات انتخاب‌شده را پوشش دهد (اختیاری)
        $fullMatch = $products->filter(function ($product) use ($specIds, $valueIds) {
            $productValues = $product->specifications
                ->pluck('pivot.specification_value_id')
                ->unique();

            return $valueIds->diff($productValues)->isEmpty();
        })->values();

        return response()->json([
            'message' => 'محصولات پیشنهادی',
            'success' => true,
            'data' => $fullMatch->isNotEmpty() ? $fullMatch : $products,
        ]);
    }
    /**
     * نمایش پروفایل زیبایی یک کاربر (برای پنل ادمین)
     * GET /v1/admin/users/{userId}/beauty-profile
     */
    public function userBeautyProfile($userId)
    {
        $selections = UserSpecificationSelection::where('user_id', $userId)
            ->whereHas('specification', function ($q) {
                $q->where('is_system', true);
            })
            ->with([
                'specification:id,title,is_system',
                'specificationValue:id,specification_id,value',
            ])
            ->get();

        // اگر کاربر انتخاب نکرده باشد
        if ($selections->isEmpty()) {
            return response()->json([
                'message' => 'کاربر هنوز پروفایل زیبایی خود را تکمیل نکرده است.',
                'success' => true,
                'has_profile' => false,
                'data' => [],
            ]);
        }

        $data = $selections->map(function ($sel) {
            return [
                'specification_id' => $sel->specification_id,
                'title' => $sel->specification?->title,
                'specification_value_id' => $sel->specification_value_id,
                'value' => $sel->specificationValue?->value,
                'selected_at' => $sel->created_at?->toDateTimeString(),
            ];
        });

        return response()->json([
            'message' => 'پروفایل زیبایی کاربر',
            'success' => true,
            'has_profile' => true,
            'data' => $data,
        ]);
    }
    /**
     * نمایش انتخاب‌های خود کاربر (پنل کاربری)
     * GET /v1/specifications/my-selections
     */
    public function mySelections(Request $request)
    {
        $userId = $request->user()->id;

        $selections = UserSpecificationSelection::where('user_id', $userId)
            ->with([
                'specification:id,title,is_system',
                'specificationValue:id,specification_id,value',
            ])
            ->get();

        if ($selections->isEmpty()) {
            return response()->json([
                'message' => 'شما هنوز مشخصاتی انتخاب نکرده‌اید.',
                'success' => true,
                'has_selections' => false,
                'data' => [],
            ]);
        }

        $data = $selections->map(function ($sel) {
            return [
                'specification_id' => $sel->specification_id,
                'title' => $sel->specification?->title,
                'is_system' => (bool) $sel->specification?->is_system,
                'specification_value_id' => $sel->specification_value_id,
                'value' => $sel->specificationValue?->value,
                'selected_at' => $sel->created_at?->toDateTimeString(),
            ];
        });

        return response()->json([
            'message' => 'انتخاب‌های شما',
            'success' => true,
            'has_selections' => true,
            'data' => $data,
        ]);
    }
}
