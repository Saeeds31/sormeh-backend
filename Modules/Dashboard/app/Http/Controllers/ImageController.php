<?php

namespace Modules\Dashboard\Http\Controllers;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ImageController extends Controller
{
    // آدرس نسبی پوشه ذخیره تصاویر
    protected string $folder = 'uploads/files/images';

    // آدرس پایه برای ساخت URL کامل
    protected string $baseUrl = 'https://api.sormeh-online.com/storage_public/';

    /**
     * نمایش لیست همه تصاویر موجود در پوشه
     * GET /api/admin/images
     */
    public function index()
    {
        // چک کردن وجود پوشه
        if (!Storage::disk('public')->exists($this->folder)) {
            return response()->json([
                'success' => true,
                'data'    => [],
                'message' => 'پوشه‌ای یافت نشد',
            ]);
        }

        $files = Storage::disk('public')->files($this->folder);

        $images = collect($files)
            ->filter(function ($file) {
                // فقط فایل‌های تصویری
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg']);
            })
            ->map(function ($file) {
                return [
                    'name'       => basename($file),
                    'path'       => $file,
                    'url'        => $this->baseUrl . $file,
                    'size'       => Storage::disk('public')->size($file),
                    'size_human' => $this->humanFileSize(Storage::disk('public')->size($file)),
                    'modified'   => date('Y-m-d H:i:s', Storage::disk('public')->lastModified($file)),
                ];
            })
            ->sortByDesc('modified')
            ->values();

        return response()->json([
            'success' => true,
            'data'    => $images,
            'total'   => $images->count(),
        ]);
    }

    /**
     * آپلود و ذخیره تصویر جدید
     * POST /api/admin/images
     */
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,gif,webp,bmp,svg|max:5120', // حداکثر ۵MB
        ], [
            'file.required' => 'انتخاب فایل الزامی است',
            'file.mimes'    => 'فرمت فایل پشتیبانی نمی‌شود',
            'file.max'      => 'حجم فایل نباید بیشتر از ۵ مگابایت باشد',
        ]);

        // ذخیره فایل در پوشه مورد نظر
        $path = $request->file('file')->store($this->folder, 'public');

        return response()->json([
            'success' => true,
            'message' => 'تصویر با موفقیت آپلود شد',
            'data'    => [
                'name' => basename($path),
                'path' => $path,
                'url'  => $this->baseUrl . $path,
            ],
        ], 201);
    }

    /**
     * حذف یک تصویر از پوشه
     * DELETE /api/admin/images/{filename}
     */
    public function destroy(string $filename)
    {
        // جلوگیری از path traversal
        $filename = basename($filename);
        $fullPath = $this->folder . '/' . $filename;

        if (!Storage::disk('public')->exists($fullPath)) {
            return response()->json([
                'success' => false,
                'message' => 'تصویر یافت نشد',
            ], 404);
        }

        Storage::disk('public')->delete($fullPath);

        return response()->json([
            'success' => true,
            'message' => 'تصویر با موفقیت حذف شد',
        ]);
    }

    /**
     * تبدیل حجم به فرمت خوانا
     */
    private function humanFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
