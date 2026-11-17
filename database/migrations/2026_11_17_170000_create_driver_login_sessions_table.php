<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_login_sessions', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('session_id')->unique();
            $table->unsignedInteger('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_login_sessions');
    }
};
