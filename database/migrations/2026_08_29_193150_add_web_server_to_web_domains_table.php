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
            $table->string('web_server')->default('nginx')->after('web_template');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('web_domains', function (Blueprint $table) {
            $table->dropColumn('web_server');
        });
    }
};
