<?php

namespace Modules\Club\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Orders\Models\Order;
use Modules\Users\Models\User;

class ClubBenefitUsage extends Model
{
    protected $fillable = ['user_id', 'order_id', 'benefit_type', 'level', 'amount', 'period'];

    protected $casts = [
        'amount' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
