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
        Schema::create('acme_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('contact_email')->nullable();
            $table->string('directory_url');
            // Encrypted at rest (App\Models\AcmeAccount::casts()): the ACME
            // account key pair PEM material. This key signs every outbound
            // HTTPS request Laravel makes directly to the ACME Certificate
            // Authority; it is never included in a ProvisioningOperation
            // payload and never sent to any node, per the ADR's own
            // "ACME account keys ... are never included in normal
            // desired-state payloads" restriction.
            $table->text('account_key');
            $table->timestamps();

            // One row per directory (staging vs production each get their
            // own lazily-registered account, so switching ACME_DIRECTORY_URL
            // never reuses a staging-registered key against production or
            // vice versa).
            $table->unique('directory_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('acme_accounts');
    }
};
