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
        Schema::create('mail_domains', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('node_id')->constrained()->restrictOnDelete();
            $table->string('domain')->unique();
            // Tenant-facing intent flags captured now, ahead of the real mail.smtp-imap.v1
            // capability that will actually enforce them -- mirroring WebDomain.ssl_mode's own
            // precedent (captured in Phase 2, enforced only once Phase 11's real ACME capability
            // shipped). See the Mail Threat Model's own "Boundary 6" section for why
            // antivirus/antispam default on (safe by default) while dkim defaults off (there is no
            // real key-generation/rotation mechanism yet to back the intent).
            $table->boolean('antivirus_enabled')->default(true);
            $table->boolean('antispam_enabled')->default(true);
            $table->boolean('dkim_enabled')->default(false);
            $table->string('catchall_email')->nullable();
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
        Schema::dropIfExists('mail_domains');
    }
};
