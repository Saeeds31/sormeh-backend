<?php

namespace Modules\Specifications\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Users\Models\User;

class UserSpecificationSelection extends Model
{
    protected $fillable = [
        'user_id',
        'specification_id',
        'specification_value_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function specification()
    {
        return $this->belongsTo(Specification::class);
    }

    public function specificationValue()
    {
        return $this->belongsTo(SpecificationValue::class);
    }
}