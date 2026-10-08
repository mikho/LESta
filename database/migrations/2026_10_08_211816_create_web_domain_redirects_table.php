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
        Schema::create('web_domain_redirects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('web_domain_id')->constrained()->cascadeOnDelete();
            $table->string('source', 200);
            $table->string('target', 500);
            $table->unsignedSmallInteger('status')->default(301);
            $table->boolean('prefix')->default(false);
            $table->timestamps();

            $table->unique(['web_domain_id', 'source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('web_domain_redirects');
    }
};
