<?php

namespace Modules\Products\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Orders\Models\OrderItem;
use Modules\Users\Models\User;

// use Modules\Products\Database\Factories\ProductFactory;

class CustomerProductPhoto  extends Model
{

    protected $fillable = [
        'user_id',
        'product_id',
        'order_item_id',
        'images',
        'thumbnail_path',
        'status',
        'reject_reason',
        'moderated_by',
        'moderated_at',
        'caption',
    ];
    protected $casts = [
        'moderated_at' => 'datetime',
        'images'       => 'array',

    ];

    public function moderator()
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }
}
