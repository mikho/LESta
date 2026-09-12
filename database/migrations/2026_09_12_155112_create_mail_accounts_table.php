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
        Schema::create('mail_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('mail_domain_id')->constrained()->cascadeOnDelete();
            $table->string('local_part');
            // Encrypted at rest, matching TenantDatabase.password's own precedent. Real
            // Dovecot-consumable password hashing (SHA512-CRYPT/ARGON2ID, never the legacy MD5
            // this project's own Capability Matrix explicitly flags as unacceptable) happens at
            // real-capability provisioning time, not here: this column holds the one plaintext
            // LESta itself generates, the same shape as every other credential in this project.
            $table->text('password');
            $table->unsignedInteger('quota_mb')->nullable();
            $table->string('forward_to')->nullable();
            $table->boolean('forward_only')->default(false);
            $table->boolean('autoreply_enabled')->default(false);
            $table->text('autoreply_message')->nullable();
            $table->timestamp('suspended_at')->nullable()->index();
            $table->string('suspension_source')->nullable();
            $table->timestamps();

            $table->unique(['mail_domain_id', 'local_part']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_accounts');
    }
};
