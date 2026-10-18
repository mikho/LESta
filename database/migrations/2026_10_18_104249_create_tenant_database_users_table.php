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
        Schema::create('tenant_database_users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_database_id')->constrained()->cascadeOnDelete();
            $table->string('label', 16);
            // <database_name>_<label>, at most 64 + 1 + 16 characters. No
            // password column: an extra user's password is shown once and
            // never stored (TenantDatabaseUser's own doc comment).
            $table->string('username', 81)->unique();
            $table->string('access', 16);
            $table->timestamps();

            $table->unique(['tenant_database_id', 'label']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_database_users');
    }
};
