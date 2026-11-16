<?php

namespace Modules\Specifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Products\Models\Product;
use Modules\Specifications\Models\UserSpecificationSelection;

class Specification extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'is_system'];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function values()
    {
        return $this->hasMany(SpecificationValue::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_specifications')
            ->withPivot('specification_value_id', 'custom_value')
            ->withTimestamps();
    }

    public function userSelections()
    {
        return $this->hasMany(UserSpecificationSelection::class);
    }

    /**
     * فقط مشخصات سیستمی
     */
    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }
}