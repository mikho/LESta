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
        // The default string() length (255) is shorter than the 500-char
        // cap IssueAcmeCertificate::handle() already applies via
        // Str::limit($e->getMessage(), 500, ''), so a real ACME failure
        // message near that cap truncates the SQL insert instead of the
        // PHP string, throwing a QueryException (1406) rather than storing
        // the error. Never exercised until Pebble ran for real in CI.
        Schema::table('web_domains', function (Blueprint $table) {
            $table->string('last_certificate_error', 500)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('web_domains', function (Blueprint $table) {
            $table->string('last_certificate_error')->nullable()->change();
        });
    }
};
