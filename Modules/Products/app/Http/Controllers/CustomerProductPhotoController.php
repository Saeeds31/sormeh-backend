<?php

namespace Modules\Products\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Products\Models\CustomerProductPhoto;
use Modules\Products\Services\CustomerProductPhotoService;

class CustomerProductPhotoController extends Controller
{
    public function __construct(
        protected CustomerProductPhotoService $service
    ) {}

    // ─────────────────────────────────────────────
    // 1) بررسی امکان آپلود (بدون نیاز به mid. sanctum → لاگین دستی)
    // ─────────────────────────────────────────────
    public function checkUpload(Request $request, int $productId): JsonResponse
    {
        if (! auth()->check()) {
            return response()->json([
                'status'=>false,
                'message' => 'برای ادامه باید وارد حساب کاربری شوید.',
            ], 422);
        }

        $result = $this->service->checkUploadStatus(
            userId: auth()->id(),
            productId: $productId,
        );

        // اطلاعات اضافی برای فرانت
        $activeStatus = $result['active_status'];
        $lastRejected = $result['last_rejected'];

        return response()->json([
            'allowed' => $result['allowed'],
            'code'    => $result['code'],
            'reason'  => $result['reason'],

            // وضعیت درخواست فعال (اگه وجود داره)
            'active_status' => $activeStatus, // pending | approved | null

            // اگه آخری رد شده
            'last_rejected' => $lastRejected ? [
                'id'            => $lastRejected->id,
                'reject_reason' => $lastRejected->reject_reason,
                'rejected_at'   => $lastRejected->moderated_at,
            ] : null,

            'max_photos_per_request' => $this->service->getMaxPhotosPerRequest(),
        ]);
    }

    // ─────────────────────────────────────────────
    // 2) آپلود (این روت از sanctum رد می‌شه)
    // ─────────────────────────────────────────────
    public function store(Request $request, int $productId): JsonResponse
    {
        $maxPhotos = $this->service->getMaxPhotosPerRequest();

        $request->validate([
            'images'        => ['required', 'array', "min:1", "max:{$maxPhotos}"],
            'images.*'      => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1024'],
            'caption'       => ['nullable', 'string', 'max:500'],
        ], [
            'images.required' => 'حداقل یک تصویر باید ارسال شود.',
            'images.max'      => "حداکثر {$maxPhotos} تصویر مجاز است.",
            'images.*.image'  => 'فایل ارسالی باید تصویر باشد.',
            'images.*.mimes'  => 'فرمت تصویر باید jpg، jpeg، png یا webp باشد.',
            'images.*.max'    => 'حجم هر تصویر نباید بیشتر از ۱ مگابایت باشد.',
        ]);

        try {
            $photo = $this->service->upload(
                userId: auth()->id(),
                productId: $productId,
                files: $request->file('images'),
                caption: $request->input('caption'),
            );

            return response()->json([
                'message' => 'عکس‌های شما ثبت شد و در انتظار تأیید است.',
                'data'    => [
                    'id'             => $photo->id,
                    'status'         => $photo->status,
                    'images'         => $photo->images,
                    'thumbnail_path' => $photo->thumbnail_path,
                    'caption'        => $photo->caption,
                ],
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ─────────────────────────────────────────────
    // 3) لیست ادمین با ترتیب pending → approved → rejected
    // ─────────────────────────────────────────────
    public function adminIndex(Request $request): JsonResponse
    {
        $status = $request->query('status'); // pending|approved|rejected|null

        $items = $this->service->paginateForAdmin(
            perPage: (int) $request->query('per_page', 15),
            status: in_array($status, ['pending', 'approved', 'rejected'], true) ? $status : null,
        );

        return response()->json($items);
    }

    // ─────────────────────────────────────────────
    // 4) تایید/رد
    // ─────────────────────────────────────────────
    public function moderate(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'decision'      => ['required', 'in:approved,rejected'],
            'reject_reason' => ['nullable', 'string', 'max:1000', 'required_if:decision,rejected'],
        ], [
            'decision.required'         => 'تصمیم مشخص نشده است.',
            'decision.in'               => 'تصمیم باید approved یا rejected باشد.',
            'reject_reason.required_if' => 'در صورت رد، دلیل باید وارد شود.',
        ]);

        $photo = CustomerProductPhoto::findOrFail($id);

        if ($photo->status === 'rejected' && $request->input('decision') === 'approved') {
            // اختیاری: می‌تونی جلوگیری کنی یا اجازه بدی
        }

        try {
            $updated = $this->service->moderate(
                photo: $photo,
                decision: $request->input('decision'),
                moderatorId: auth()->id(),
                rejectReason: $request->input('reject_reason'),
            );

            return response()->json([
                'message' => 'وضعیت به‌روزرسانی شد.',
                'data'    => [
                    'id'            => $updated->id,
                    'status'        => $updated->status,
                    'reject_reason' => $updated->reject_reason,
                    'moderated_at'  => $updated->moderated_at,
                ],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ─────────────────────────────────────────────
    // 5) حذف + حذف فایل‌ها
    // ─────────────────────────────────────────────
    public function destroy(int $id): JsonResponse
    {
        $photo = CustomerProductPhoto::findOrFail($id);

        $this->service->deleteWithFiles($photo);

        return response()->json(['message' => 'درخواست و تصاویر آن حذف شد.']);
    }
}
