<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_spoolman_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('spoolman_filament_id');
            $table->string('spoolman_vendor_name')->nullable();
            $table->string('spoolman_material', 50)->nullable();
            $table->string('spoolman_color_hex', 16)->nullable();
            $table->string('weight_uom', 10)->default('g');
            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('product_id');
            $table->index('spoolman_filament_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_spoolman_mappings');
    }
};
