<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Production POS (/pos/production): the shop floor works ERPNext Work Orders from the WMS tablet.
| ERPNext owns the Work Order; these tables hold what the floor adds — who worked on it when, and
| the finished quantity reported. Good quantity is received into a WMS warehouse through the
| stock ledger (reference_type production_output); the stock bridge posts it to ERPNext as a
| Manufacture Stock Entry against the Work Order.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_logs', function (Blueprint $table) {
            $table->id();
            $table->string('work_order', 140)->index();
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operator_name', 191)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index(['work_order', 'ended_at']);
        });

        Schema::create('production_outputs', function (Blueprint $table) {
            $table->id();
            $table->string('work_order', 140)->index();
            $table->string('item_code', 140);
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->decimal('good_qty', 21, 9)->default(0);
            $table->decimal('scrap_qty', 21, 9)->default(0);
            $table->string('scrap_reason', 500)->nullable();
            $table->string('uom', 20);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operator_name', 191)->nullable();
            // Sent by the tablet with every report: a retry after a dropped connection is not counted twice.
            $table->string('client_key', 100)->unique();
            $table->foreignId('stock_ledger_id')->nullable()->constrained('stock_ledgers')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_outputs');
        Schema::dropIfExists('production_logs');
    }
};
