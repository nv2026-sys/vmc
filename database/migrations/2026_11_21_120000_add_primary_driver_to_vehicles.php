<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->foreignId('assigned_driver_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });

        if (DB::connection()->getDriverName() === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX vehicles_assigned_driver_user_id_unique ON vehicles (assigned_driver_user_id) WHERE assigned_driver_user_id IS NOT NULL');
        } else {
            Schema::table('vehicles', function (Blueprint $table): void {
                $table->unique('assigned_driver_user_id');
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            DB::statement('DROP INDEX vehicles_assigned_driver_user_id_unique ON vehicles');
        } else {
            Schema::table('vehicles', function (Blueprint $table): void {
                $table->dropUnique(['assigned_driver_user_id']);
            });
        }

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropForeign(['assigned_driver_user_id']);
            $table->dropColumn('assigned_driver_user_id');
        });
    }
};
