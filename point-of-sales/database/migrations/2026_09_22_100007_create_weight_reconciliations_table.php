<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weight_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->timestamp('as_of');
            $table->decimal('ledger_expected_weight', 14, 4)->default(0);
            $table->decimal('physical_sum_weight', 14, 4)->default(0);
            $table->decimal('spoolman_sum_weight', 14, 4)->nullable();
            $table->decimal('variance_weight', 14, 4)->default(0);
            $table->string('dq_status', 20)->default('OK'); // OK|WARN|REVIEW|BLOCK
            $table->string('resolution', 30)->nullable(); // NONE|ADJUSTMENT|COUNT|IGNORED
            $table->unsignedBigInteger('adjustment_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'warehouse_id', 'as_of']);
            $table->index('dq_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weight_reconciliations');
    }
};
