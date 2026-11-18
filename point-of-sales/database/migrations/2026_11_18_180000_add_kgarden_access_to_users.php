<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// People whose access is managed in ERPNext (K Garden central access) and their employee code.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_code', 50)->nullable()->unique()->after('email');
            $table->boolean('kgarden_managed')->default(false)->after('employee_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['employee_code', 'kgarden_managed']);
        });
    }
};
