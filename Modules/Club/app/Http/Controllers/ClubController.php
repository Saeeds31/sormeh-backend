<?php

namespace Modules\Club\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Club\Models\ClubBenefitUsage;
use Modules\Club\Models\PointTransaction;
use Modules\Club\Models\UserPoint;
use Modules\Club\Services\ClubBenefitService;
use Modules\Club\Services\ClubLevelService;
use Modules\Club\Services\ClubService;
use Modules\Users\Models\User;

class ClubController extends Controller
{
    public function __construct(
        protected ClubService $club,
        protected ClubLevelService $levels,
        protected ClubBenefitService $benefits,
    ) {}

    /** لیست کاربران بر اساس امتیاز */
    public function users(Request $request)
    {
        $query = User::query()
            ->leftJoin('user_points', 'users.id', '=', 'user_points.user_id')
            ->select('users.*', 'user_points.total_points', 'user_points.level')
            ->orderByDesc('user_points.total_points');

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('users.full_name', 'like', "%{$search}%")
                    ->orWhere('users.mobile', 'like', "%{$search}%");
            });
        }
        if ($level = $request->get('level')) {
            $query->where('user_points.level', $level);
        }

        return response()->json($query->paginate(20));
    }

    /** جزئیات امتیاز یک کاربر */
    public function userDetail(User $user)
    {
        $row = UserPoint::firstOrCreate(['user_id' => $user->id]);

        $transactions = PointTransaction::where('user_id', $user->id)
            ->latest()->paginate(20);

        $usages = ClubBenefitUsage::where('user_id', $user->id)
            ->with('order')->latest()->paginate(20);

        return response()->json([
            'user'         => $user,
            'total_points' => $row->total_points,
            'level'        => $row->level,
            'transactions' => $transactions,
            'usages'       => $usages,
        ]);
    }

    /** افزودن امتیاز دستی */
    public function addPoints(Request $request, User $user)
    {
        $data = $request->validate([
            'points'      => 'required|integer|min:1',
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $tx = $this->club->addPoints(
            $user,
            $data['points'],
            $data['title'],
            $data['description'] ?? null,
            'manual',
            null,
            auth()->id()
        );

        return response()->json(['message' => 'امتیاز افزوده شد', 'transaction' => $tx]);
    }

    /** کسر امتیاز دستی */
    public function deductPoints(Request $request, User $user)
    {
        $data = $request->validate([
            'points'      => 'required|integer|min:1',
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $tx = $this->club->deductPoints(
            $user,
            $data['points'],
            $data['title'],
            $data['description'] ?? null,
            'manual',
            null,
            auth()->id()
        );

        return response()->json(['message' => 'امتیاز کسر شد', 'transaction' => $tx]);
    }

    /** موارد استفاده‌شده از مزایا به تفکیک سفارش */
    public function usages(User $user)
    {
        return response()->json(
            ClubBenefitUsage::where('user_id', $user->id)
                ->with('order')->latest()->paginate(20)
        );
    }
    /** خلاصه: جمع امتیاز + سطح + مزایا */
    public function summary()
    {
        $user = auth()->user();
        $row  = UserPoint::firstOrCreate(['user_id' => $user->id]);

        return response()->json([
            'total_points'           => $row->total_points,
            'level'                  => $row->level,
            'discount_percent'       => $this->levels->discountFor($row->level),
            'free_shipping_remaining' => $this->benefits->remainingFreeShipping($user),
            'can_use_free_shipping'  => $this->benefits->canUseFreeShipping($user),
        ]);
    }

    /** جدول امتیازها */
    public function transactions()
    {
        return response()->json(
            PointTransaction::where('user_id', auth()->id())
                ->latest()->paginate(20)
        );
    }

    /** مزایای استفاده‌شده در هر سطح */
    public function usagesFront()
    {
        return response()->json(
            ClubBenefitUsage::where('user_id', auth()->id())
                ->with('order')->latest()->paginate(20)
        );
    }

    /** آیا مجاز به ارسال رایگان است؟ */
    public function canUseFreeShipping()
    {
        return response()->json([
            'allowed'   => $this->benefits->canUseFreeShipping(auth()->user()),
            'remaining' => $this->benefits->remainingFreeShipping(auth()->user()),
        ]);
    }
    public function userTransactions(User $user)
    {
        return response()->json(
            PointTransaction::where('user_id', $user->id)
                ->latest()->paginate(20)
        );
    }
}
