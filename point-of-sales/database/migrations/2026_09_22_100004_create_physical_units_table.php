<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('physical_units', function (Blueprint $table) {
            $table->id();
            $table->string('unit_code')->unique();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->string('lot_code', 100)->nullable();
            $table->string('unit_type', 30)->default('SPOOL'); // SPOOL|ROLL|DRUM|SHEET_PACK|OTHER
            $table->string('status', 30)->default('ACTIVE'); // ACTIVE|QUARANTINE|DEPLETED|SCRAPPED|TRANSFERRED_OUT
            $table->decimal('nominal_qty', 14, 4)->nullable();
            $table->decimal('nominal_weight', 14, 4)->nullable();
            $table->decimal('actual_weight', 14, 4)->nullable();
            $table->string('weight_uom', 10)->default('KG');
            $table->string('weight_source', 30)->default('MANUAL'); // MANUAL|SCALE|SPOOLMAN|IMPORT|ESTIMATED
            $table->timestamp('weight_verified_at')->nullable();
            $table->unsignedInteger('spoolman_spool_id')->nullable()->unique();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('depleted_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'warehouse_id', 'status']);
            $table->index('lot_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_units');
    }
};
