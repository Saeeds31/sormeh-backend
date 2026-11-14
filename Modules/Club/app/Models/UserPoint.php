<?php

namespace Modules\Club\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Users\Models\User;

class UserPoint extends Model
{
    protected $fillable = ['user_id', 'total_points', 'level'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
