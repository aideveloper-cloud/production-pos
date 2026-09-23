<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spoolman_consumption_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->unsignedInteger('spoolman_spool_id');
            $table->foreignId('physical_unit_id')->nullable()->constrained('physical_units')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('qty_consumed', 18, 6);
            $table->string('qty_uom', 20);
            $table->decimal('weight_before', 14, 4)->nullable();
            $table->decimal('weight_after', 14, 4)->nullable();
            $table->timestamp('occurred_at');
            $table->json('payload')->nullable();
            $table->string('process_status', 20)->default('PENDING'); // PENDING|POSTED|REJECTED|DUPLICATE
            $table->foreignId('stock_ledger_id')->nullable()->constrained('stock_ledgers')->nullOnDelete();
            $table->string('reject_reason')->nullable();
            $table->timestamps();

            $table->index(['process_status', 'occurred_at']);
            $table->index('spoolman_spool_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spoolman_consumption_events');
    }
};
