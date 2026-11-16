<?php
// database/migrations/xxxx_create_user_specification_selections_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_specification_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('specification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('specification_value_id')->constrained()->cascadeOnDelete();
            // هر کاربر برای هر مشخصه فقط یک انتخاب دارد
            $table->unique(['user_id', 'specification_id']);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_specification_selections');
    }
};
