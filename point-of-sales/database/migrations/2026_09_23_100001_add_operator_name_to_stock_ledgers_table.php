<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_ledgers', function (Blueprint $table) {
            $table->string('operator_name', 100)->nullable()->after('created_by');
            $table->decimal('weight_kg', 18, 4)->nullable()->after('uom');
            $table->index('operator_name');
        });
    }

    public function down(): void
    {
        Schema::table('stock_ledgers', function (Blueprint $table) {
            $table->dropIndex(['operator_name']);
            $table->dropColumn(['operator_name', 'weight_kg']);
        });
    }
};
