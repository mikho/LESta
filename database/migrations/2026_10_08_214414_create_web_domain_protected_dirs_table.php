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
        Schema::create('web_domain_protected_dirs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('web_domain_id')->constrained()->cascadeOnDelete();
            $table->string('path', 200);
            $table->string('realm', 64);
            $table->timestamps();

            $table->unique(['web_domain_id', 'path']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('web_domain_protected_dirs');
    }
};
