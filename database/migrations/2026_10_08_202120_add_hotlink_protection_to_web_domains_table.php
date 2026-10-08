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
            $table->boolean('hotlink_protection')->default(false)->after('waf_preset');
            $table->json('hotlink_allowed_hosts')->nullable()->after('hotlink_protection');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('web_domains', function (Blueprint $table) {
            $table->dropColumn(['hotlink_protection', 'hotlink_allowed_hosts']);
        });
    }
};
