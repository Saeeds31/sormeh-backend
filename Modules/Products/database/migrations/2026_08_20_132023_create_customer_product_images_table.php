<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customer_product_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('order_item_id')
                ->nullable()
                ->constrained('order_items')
                ->nullOnDelete();
            $table->json('images');
            $table->string('thumbnail_path')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])
                ->default('pending');
            $table->text('reject_reason')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->string('caption', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['product_id', 'status']);   // نمایش عکس‌های تاییدشده محصول
            $table->index(['user_id', 'created_at']);  // آپلودهای کاربر
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_product_photos');
    }
};
