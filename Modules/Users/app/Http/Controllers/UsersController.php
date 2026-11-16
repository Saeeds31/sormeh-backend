<?php

namespace Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\Club\Models\PointTransaction;
use Modules\Club\Services\ClubService;
use Modules\Settings\Models\Setting;
use Modules\Users\Http\Requests\UserStoreRequest;
use Modules\Users\Http\Requests\UserUpdateRequest;
use Modules\Users\Models\Role;
use Modules\Users\Models\User;
use Modules\Wallet\Models\Wallet;

class UsersController extends Controller
{
    public function adminInfo(Request $request)
    {
        $user = $request->user();
        $permissions = $user->permissions;
        return response()->json([
            'message' => 'اطلاعات ادمین',
            'user' => $user,
            'permissions' => $permissions
        ]);
    }
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'full_name'     => 'nullable|string|max:255',
            'password'      => 'nullable|string|min:6',
            'national_code' => 'nullable|string|max:10|unique:users,national_code,' . $user->id,
            'avatar'        => 'nullable|file|max:1024',
            'birth_date'    => 'nullable|date',
        ]);

        if (!empty($validated['password'])) {
            $validated['password'] = bcrypt($validated['password']);
        } else {
            unset($validated['password']);
        }

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('users', 'public');
            $validated['avatar'] = $path;
        }

        // ================================================================
        // باشگاه مشتریان: بررسی تکمیل پروفایل (قبل از update)
        // ================================================================
        $requiredFields = ['full_name', 'national_code', 'birth_date'];

        $wasIncomplete = false;
        foreach ($requiredFields as $field) {
            if (empty($user->{$field})) {
                $wasIncomplete = true;
                break;
            }
        }

        // به‌روزرسانی کاربر
        $user->update($validated);
        $user->refresh();

        // ================================================================
        // باشگاه مشتریان: اگر قبلاً ناقص بود و حالا کامل شده → امتیاز بده
        // ================================================================
        if ($wasIncomplete) {
            $isNowComplete = true;
            foreach ($requiredFields as $field) {
                if (empty($user->{$field})) {
                    $isNowComplete = false;
                    break;
                }
            }

            if ($isNowComplete) {
                $this->awardProfileCompletionPoints($user);
            }
        }

        return response()->json([
            'message' => 'پروفایل با موفقیت بروزرسانی شد.',
            'user'    => $user,
        ]);
    }

    /**
     * اعطای امتیاز تکمیل پروفایل (یک‌بار برای هر کاربر).
     */
    protected function awardProfileCompletionPoints(User $user): void
    {
        $clubEnabled = (bool)  Setting::where('key', 'club.enabled')->value('value');
        if (!$clubEnabled) {
            return;
        }

        $points = (int) Setting::where('key', 'club.profile_completion_points')->value('value');
        if ($points <= 0) {
            return;
        }

        // جلوگیری از امتیاز تکراری برای تکمیل پروفایل
        $alreadyAwarded = PointTransaction::where('user_id', $user->id)
            ->where('source', 'profile')
            ->exists();

        if ($alreadyAwarded) {
            return;
        }

        app(ClubService::class)->addPoints(
            $user,
            $points,
            'تکمیل پروفایل',
            'تکمیل پروفایل کاربری',
            'profile',
            null // بدون reference
        );
    }

    // لیست کاربران
    public function userProfile(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'user' => $user,
            'message' => 'اطلاعات کاربر'
        ]);
    }
    public function index(Request $request)
    {
        $query = User::with(['roles', 'addresses', 'wallet']);

        // اگر پارامتر search ارسال شده باشد
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%");
            });
        }

        // اگر پارامتر role ارسال شده باشد
        if ($roleSlug = $request->get('role')) {
            $query->whereHas('roles', function ($q) use ($roleSlug) {
                $q->where('slug', $roleSlug);
            });
        }

        $users = $query->latest()->paginate(20);

        return response()->json($users);
    }
    // لیست مدیران
    public function managerIndex(Request $request)
    {
        $query = User::with(['roles', 'addresses', 'wallet']);
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%");
            });
        }
        $users = $query->whereHas('roles', function ($query) {
            $query->whereNotIn('slug', ['customer', 'superAdmin']);
        })->get();
        return response()->json($users);
    }
    // ساخت کاربر جدید
    public function store(UserStoreRequest $request)
    {
        $data = $request->validated();
        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('users', 'public');
            $data['avatar'] = $path;
        }
        $customerRoleId = Role::where('slug', 'customer')->value('id');
        if (!$customerRoleId) {
            return response()->json([
                'message' => 'نقش پیشفرض مشتری وجود ندارد لطفا این نقش را در دیتابیس تعریف کنید'
            ], 422);
        }
        $data['password'] = Hash::make($data['password']);
        $user = User::create($data);
        Wallet::create([
            'user_id' => $user->id,
            'balance' =>  0,
        ]);
        $user->roles()->sync([$customerRoleId]);
        return response()->json($user->load(['roles', 'addresses', 'wallet']), 201);
    }

    // نمایش یک کاربر
    public function show(User $user)
    {
        return response()->json($user->load(['roles', 'addresses', 'wallet']));
    }

    // ویرایش کاربر
    public function update(UserUpdateRequest $request, User $user)
    {
        $data = $request->validated();
        // if (isset($data['mobile'])) {
        //     unset($data['mobile']);
        // }
        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }
        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('users', 'public');
            $data['avatar'] = $path;
        }
        $user->update($data);
        return response()->json($user->load(['roles', 'addresses', 'wallet']));
    }

    // حذف کاربر
    public function destroy(User $user)
    {
        $user->delete();
        return response()->json(['message' => 'User deleted successfully']);
    }
    public function getAddresses(User $user)
    {
        $addresses = $user->addresses()->with(['province', 'city'])->get();

        return response()->json([
            'success' => true,
            'data' => $addresses,
        ]);
    }
}
