<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The API token a change was made through, when it was. No foreign key: a revoked token's row
     * is deleted, and nulling a hashed audit field would break the chain (AuditEvent::chainHash).
     */
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->unsignedBigInteger('api_token_id')->nullable()->after('actor_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropColumn('api_token_id');
        });
    }
};
