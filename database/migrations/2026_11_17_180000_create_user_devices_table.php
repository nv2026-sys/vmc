<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('fcm_token_hash', 64)->unique();
            $table->longText('fcm_token');
            $table->string('device_type', 16);
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'device_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
    }
};
