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
            $table->string('waf_mode', 10)->default('off')->after('ssl_mode');
            $table->json('waf_excluded_rules')->nullable()->after('waf_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('web_domains', function (Blueprint $table) {
            $table->dropColumn(['waf_mode', 'waf_excluded_rules']);
        });
    }
};
