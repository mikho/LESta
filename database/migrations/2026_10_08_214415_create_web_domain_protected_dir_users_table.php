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
        Schema::create('web_domain_protected_dir_users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('protected_dir_id')->constrained('web_domain_protected_dirs')->cascadeOnDelete();
            $table->string('username', 32);
            $table->string('password_hash', 200);
            $table->timestamps();

            $table->unique(['protected_dir_id', 'username'], 'protected_dir_users_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('web_domain_protected_dir_users');
    }
};
