<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->string('operational_status', 20)->default('ON_ROAD')->after('status')->index();
        });

        Schema::create('vehicle_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('previous_status', 20)->nullable();
            $table->string('new_status', 20);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('changed_at')->index();
            $table->string('source', 30)->default('api');
            $table->index(['vehicle_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_status_histories');

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropIndex(['operational_status']);
            $table->dropColumn('operational_status');
        });
    }
};
