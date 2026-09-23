<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which physical units travel on a stock transfer. physical_unit_id is the unit in transit;
     * source_unit_id is the unit it came from (the same id when a whole unit travels).
     */
    public function up(): void
    {
        Schema::create('stock_transfer_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_transfer_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('physical_unit_id')->nullable()->constrained('physical_units')->nullOnDelete();
            $table->foreignId('source_unit_id')->nullable()->constrained('physical_units')->nullOnDelete();
            $table->decimal('qty', 18, 6);
            $table->timestamps();

            // Explicit name: the generated one is 67 chars, over MySQL's 64-char identifier limit.
            $table->index(['stock_transfer_id', 'stock_transfer_item_id'], 'stock_transfer_units_transfer_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_units');
    }
};
