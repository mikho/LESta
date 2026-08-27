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
        Schema::create('web_domain_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('web_domain_id')->constrained()->cascadeOnDelete();
            $table->string('alias')->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('web_domain_aliases');
    }
};
