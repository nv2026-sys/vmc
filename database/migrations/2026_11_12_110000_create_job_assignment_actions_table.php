<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_assignment_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_assignment_id')->constrained()->cascadeOnDelete();
            $table->string('action', 40);
            $table->json('details')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('occurred_at')->index();
            $table->index(['job_assignment_id', 'action']);
            $table->index(['job_assignment_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_assignment_actions');
    }
};
