<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';
        $unsigned = true;
        if ($mysql) {
            $userIdType = DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TABLE_NAME', 'users')
                ->where('COLUMN_NAME', 'id')
                ->value('COLUMN_TYPE');
            if (! is_string($userIdType)) {
                throw new RuntimeException('Unable to determine the users.id type for the driver assignment foreign key.');
            }
            $unsigned = str_contains(strtolower($userIdType), 'unsigned');
        }

        if (! Schema::hasColumn('job_assignments', 'assigned_driver_user_id')) {
            Schema::table('job_assignments', function (Blueprint $table) use ($unsigned): void {
                $column = $unsigned
                    ? $table->unsignedBigInteger('assigned_driver_user_id')
                    : $table->bigInteger('assigned_driver_user_id');
                $column->nullable()->after('team_leader_user_id');
            });
        } elseif ($mysql) {
            $driverIdType = DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TABLE_NAME', 'job_assignments')
                ->where('COLUMN_NAME', 'assigned_driver_user_id')
                ->value('COLUMN_TYPE');
            if (! is_string($driverIdType)) {
                throw new RuntimeException('Unable to determine the assigned driver column type.');
            }
            if (str_contains(strtolower($driverIdType), 'unsigned') !== $unsigned) {
                DB::statement($unsigned
                    ? 'ALTER TABLE job_assignments MODIFY assigned_driver_user_id BIGINT UNSIGNED NULL'
                    : 'ALTER TABLE job_assignments MODIFY assigned_driver_user_id BIGINT NULL');
            }
        }

        $foreignKeyExists = collect(Schema::getForeignKeys('job_assignments'))
            ->contains(fn (array $foreignKey): bool => $foreignKey['columns'] === ['assigned_driver_user_id']
                && $foreignKey['foreign_table'] === 'users');
        if (! $foreignKeyExists) {
            Schema::table('job_assignments', function (Blueprint $table): void {
                $table->foreign('assigned_driver_user_id')
                    ->references('id')
                    ->on('users');
            });
        }

        DB::table('job_assignments')
            ->whereNotNull('team_leader_user_id')
            ->update(['assigned_driver_user_id' => DB::raw('team_leader_user_id')]);
    }

    public function down(): void
    {
        Schema::table('job_assignments', function (Blueprint $table): void {
            $table->dropForeign(['assigned_driver_user_id']);
            $table->dropColumn('assigned_driver_user_id');
        });
    }
};
