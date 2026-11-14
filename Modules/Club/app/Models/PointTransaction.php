<?php

namespace Modules\Club\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Users\Models\User;

class PointTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'points',
        'type',
        'title',
        'description',
        'source',
        'reference_type',
        'reference_id',
        'admin_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function reference()
    {
        return $this->morphTo();
    }
    public function admin()
    {
        return $this->belongsTo(User::class);
    }
}
