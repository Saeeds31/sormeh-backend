<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('low_stock_threshold')->nullable()->after('stock');
        });
        Schema::table('product_variants', function (Blueprint $table) {
            $table->timestamp('low_stock_notified_at')->nullable()->after('stock');
            $table->index(['low_stock_notified_at', 'stock'], 'idx_low_stock_scan');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropIndex('idx_low_stock_scan');
            $table->dropColumn('low_stock_notified_at');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('low_stock_threshold');
        });
    }
};
