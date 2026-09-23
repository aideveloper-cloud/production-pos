<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('domain', 10)->default('FG')->after('sku'); // RM | FG
            $table->string('base_uom', 20)->nullable()->after('domain');
            $table->string('secondary_uom', 20)->nullable()->after('base_uom');
            $table->string('stock_calc_strategy', 40)->default('QTY_BASED')->after('secondary_uom');
            $table->boolean('tracks_physical_units')->default(false)->after('stock_calc_strategy');
            $table->boolean('spoolman_enabled')->default(false)->after('tracks_physical_units');
            $table->decimal('standard_weight_kg', 14, 4)->nullable()->after('spoolman_enabled');

            $table->index(['domain', 'stock_calc_strategy']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['domain', 'stock_calc_strategy']);
            $table->dropColumn([
                'domain',
                'base_uom',
                'secondary_uom',
                'stock_calc_strategy',
                'tracks_physical_units',
                'spoolman_enabled',
                'standard_weight_kg',
            ]);
        });
    }
};
