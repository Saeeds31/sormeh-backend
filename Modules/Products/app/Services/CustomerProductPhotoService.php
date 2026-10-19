<?php

namespace Modules\Products\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Orders\Models\OrderItem;
use Modules\Products\Models\CustomerProductPhoto;
use Modules\Products\Models\Product;

class CustomerProductPhotoService
{
    protected array $validOrderStatuses = ['paid', 'shipped', 'completed', 'delivered'];

    /** حداکثر عکس در هر درخواست آپلود */
    protected int $maxPhotosPerRequest = 3;

    /** حداکثر عکس فعال (pending/approved) برای هر آیتم سفارش */
    protected int $maxActivePhotosPerOrderItem = 3;

    protected string $disk = 'public';
    protected string $directory = 'products/customer-uploads';

    public function getMaxPhotosPerRequest(): int
    {
        return $this->maxPhotosPerRequest;
    }

    // ─────────────────────────────────────────────
    // بررسی خرید
    // ─────────────────────────────────────────────

    public function findPurchasedOrderItem(int $userId, int $productId): ?OrderItem
    {
        return OrderItem::query()
            ->where('product_id', $productId)
            ->whereHas('order', function ($q) use ($userId) {
                $q->where('user_id', $userId)
                    ->where(function ($qq) {
                        $qq->whereIn('status', $this->validOrderStatuses)
                            ->orWhere('payment_status', 'paid');
                    });
            })
            ->latest('id')
            ->first();
    }

    // ─────────────────────────────────────────────
    // بررسی امکان آپلود (برای API چک وضعیت)
    // ─────────────────────────────────────────────

    /**
     * @return array{
     *     allowed: bool,
     *     reason: ?string,
     *     code: string,
     *     order_item: ?OrderItem,
     *     active_photo: ?CustomerProductPhoto,
     *     active_status: ?string,   // pending | approved
     *     last_rejected: ?CustomerProductPhoto,
     * }
     */
    public function checkUploadStatus(int $userId, int $productId): array
    {
        if (! Product::whereKey($productId)->exists()) {
            return $this->statusResult(false, 'not_found', 'محصول یافت نشد.');
        }

        $orderItem = $this->findPurchasedOrderItem($userId, $productId);

        if (! $orderItem) {
            return $this->statusResult(
                false, 'not_purchased',
                'شما این محصول را خریداری نکرده‌اید.',
                null
            );
        }

        $activePhoto = CustomerProductPhoto::query()
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->where('order_item_id', $orderItem->id)
            ->whereIn('status', ['pending', 'approved'])
            ->latest('id')
            ->first();

        if ($activePhoto) {
            $isPending = $activePhoto->status === 'pending';

            return $this->statusResult(
                false,
                $isPending ? 'pending_review' : 'already_approved',
                $isPending
                    ? 'عکس شما در حال بررسی است.'
                    : 'برای این محصول عکس تأییدشده دارید.',
                $orderItem,
                $activePhoto,
                $activePhoto->status
            );
        }

        $lastRejected = CustomerProductPhoto::query()
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->where('order_item_id', $orderItem->id)
            ->where('status', 'rejected')
            ->latest('id')
            ->first();

        return $this->statusResult(
            true,
            $lastRejected ? 'rejected_can_retry' : 'can_upload',
            null,
            $orderItem,
            null,
            null,
            $lastRejected
        );
    }

    protected function statusResult(
        bool $allowed,
        string $code,
        ?string $reason = null,
        ?OrderItem $orderItem = null,
        ?CustomerProductPhoto $activePhoto = null,
        ?string $activeStatus = null,
        ?CustomerProductPhoto $lastRejected = null
    ): array {
        return [
            'allowed'       => $allowed,
            'code'          => $code,
            'reason'        => $reason,
            'order_item'    => $orderItem,
            'active_photo'  => $activePhoto,
            'active_status' => $activeStatus,
            'last_rejected' => $lastRejected,
        ];
    }

    // ─────────────────────────────────────────────
    // آپلود
    // ─────────────────────────────────────────────

    /**
     * @param UploadedFile[] $files
     * @throws \RuntimeException
     */
    public function upload(
        int $userId,
        int $productId,
        array $files,
        ?string $caption = null
    ): CustomerProductPhoto {
        if (count($files) < 1) {
            throw new \RuntimeException('حداقل یک تصویر باید ارسال شود.');
        }

        if (count($files) > $this->maxPhotosPerRequest) {
            throw new \RuntimeException(
                "حداکثر {$this->maxPhotosPerRequest} تصویر مجاز است."
            );
        }

        $check = $this->checkUploadStatus($userId, $productId);

        if (! $check['allowed']) {
            throw new \RuntimeException($check['reason'] ?? 'امکان آپلود وجود ندارد.');
        }

        /** @var OrderItem $orderItem */
        $orderItem = $check['order_item'];

        $storedPaths = [];
        try {
            foreach ($files as $file) {
                $storedPaths[] = $this->storeFile($file);
            }

            // اولین عکس = thumbnail
            $thumbnail = $storedPaths[0] ?? null;

            return CustomerProductPhoto::create([
                'user_id'        => $userId,
                'product_id'     => $productId,
                'order_item_id'  => $orderItem->id,
                'images'         => $storedPaths,
                'thumbnail_path' => $thumbnail,
                'caption'        => $caption,
                'status'         => 'pending',
            ]);
        } catch (\Throwable $e) {
            // پاکسازی فایل‌های ذخیره‌شده در صورت خطا
            foreach ($storedPaths as $p) {
                $this->deleteFileIfExists($p);
            }
            throw $e;
        }
    }

    // ─────────────────────────────────────────────
    // ادمین: لیست، تایید/رد، حذف
    // ─────────────────────────────────────────────

    public function paginateForAdmin(int $perPage = 15, ?string $status = null)
    {
        return CustomerProductPhoto::query()
            ->with(['user:id,full_name,mobile', 'product:id,name,slug', 'moderator:id,full_name'])
            ->when($status, fn($q) => $q->where('status', $status))
            ->orderByRaw("FIELD(status, 'pending', 'approved', 'rejected')")
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * @param string $decision approved|rejected
     * @throws \RuntimeException
     */
    public function moderate(
        CustomerProductPhoto $photo,
        string $decision,
        int $moderatorId,
        ?string $rejectReason = null
    ): CustomerProductPhoto {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new \RuntimeException('وضعیت نامعتبر است.');
        }

        if ($decision === 'rejected' && blank($rejectReason)) {
            throw new \RuntimeException('دلیل رد باید وارد شود.');
        }

        $photo->update([
            'status'        => $decision,
            'reject_reason' => $decision === 'rejected' ? $rejectReason : null,
            'moderated_by'  => $moderatorId,
            'moderated_at'  => now(),
        ]);

        return $photo->refresh();
    }

    public function deleteWithFiles(CustomerProductPhoto $photo): void
    {
        DB::transaction(function () use ($photo) {
            foreach (($photo->images ?? []) as $path) {
                $this->deleteFileIfExists($path);
            }
            $this->deleteFileIfExists($photo->thumbnail_path);

            $photo->forceDelete(); // یا delete() اگه soft delete کافیه
        });
    }

    // ─────────────────────────────────────────────
    // هلپرها
    // ─────────────────────────────────────────────

    protected function storeFile(UploadedFile $file): string
    {
        $filename = Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();
        return $file->storeAs($this->directory, $filename, $this->disk);
    }

    protected function deleteFileIfExists(?string $path): void
    {
        if ($path && Storage::disk($this->disk)->exists($path)) {
            Storage::disk($this->disk)->delete($path);
        }
    }
}