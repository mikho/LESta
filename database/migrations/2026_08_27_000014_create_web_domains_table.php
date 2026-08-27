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
        Schema::create('web_domains', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('node_id')->constrained()->restrictOnDelete();
            $table->foreignId('ip_allocation_id')->constrained()->restrictOnDelete();
            $table->string('domain')->unique();
            $table->string('web_template')->default('default');
            $table->string('ssl_mode')->default('none');
            $table->string('certificate_authority')->nullable();
            $table->timestamp('certificate_issued_at')->nullable();
            $table->timestamp('certificate_expires_at')->nullable();
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
        Schema::dropIfExists('web_domains');
    }
};
