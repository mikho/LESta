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
        Schema::table('web_domains', function (Blueprint $table) {
            $table->string('last_certificate_error')->nullable()->after('certificate_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('web_domains', function (Blueprint $table) {
            $table->dropColumn('last_certificate_error');
        });
    }
};
