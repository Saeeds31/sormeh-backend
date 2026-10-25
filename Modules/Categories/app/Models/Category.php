<?php

namespace Modules\Categories\Models;

use App\Support\CacheService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Products\Models\Product;

// use Modules\Categories\Database\Factories\CategoryFactory;

class Category extends Model
{
    protected $fillable = ['title', 'meta_description', 'meta_title', 'slug', 'main_image', 'slug', 'description', 'icon', 'parent_id', 'show_in_home', 'show_products_in_home'];
    protected $casts = [
        'show_in_home' => 'boolean',
        'show_products_in_home' => 'boolean',

    ];
    // رابطه با محصولات
    public function products()
    {
        return $this->belongsToMany(Product::class, 'category_product', 'category_id', 'product_id');
    }

    // دسته‌بندی والد
    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    // دسته‌بندی‌های فرزند
    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }
    public function allChildren()
    {
        return $this->children()->with('allChildren');
    }
    public function descendantIds(): array
    {
        $ids = [$this->id];
        foreach ($this->allChildren as $child) {
            $ids = array_merge($ids, $child->descendantIds());
        }
        return $ids;
    }

    public function productsWithDescendants()
    {
        $ids = $this->descendantIds();

        return Product::whereHas('categories', fn($q) => $q->whereIn('categories.id', $ids));
    }
    protected static function booted()
    {
        $clearCache = fn() => CacheService::forgetCategories();
        static::saved($clearCache);
        static::deleted($clearCache);
    }
}
