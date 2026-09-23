<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable WMS stock ledger (Source of Truth for RM/FG movements).
 * POS retail flows still use stock_mutations; WMS/Spoolman post here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('physical_unit_id')->nullable()->constrained('physical_units')->nullOnDelete();
            $table->string('movement_type', 40); // RECEIVE|ISSUE|CONSUMPTION|TRANSFER_IN|TRANSFER_OUT|ADJUSTMENT|COUNT
            $table->decimal('qty', 18, 6); // signed: + in, - out
            $table->string('uom', 20);
            $table->decimal('qty_before', 18, 6)->nullable();
            $table->decimal('qty_after', 18, 6)->nullable();
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at');
            $table->timestamps();

            $table->index(['product_id', 'warehouse_id', 'posted_at']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('movement_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_ledgers');
    }
};
