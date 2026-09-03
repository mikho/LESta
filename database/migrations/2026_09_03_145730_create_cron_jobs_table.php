<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cron_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('node_id')->constrained()->restrictOnDelete();
            $table->string('minute')->default('*');
            $table->string('hour')->default('*');
            $table->string('day_of_month')->default('*');
            $table->string('month')->default('*');
            $table->string('day_of_week')->default('*');
            $table->string('command', 1024);
            $table->unsignedInteger('desired_state_version')->default(1);
            $table->timestamp('suspended_at')->nullable()->index();
            $table->string('suspension_source')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cron_jobs');
    }
};
