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
        Schema::create('mailing_lists', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('mail_domain_id')->constrained()->cascadeOnDelete();
            $table->string('local_part', 64);
            $table->string('owner_email', 254);
            $table->string('post_policy', 10)->default('members');
            $table->string('subject_prefix', 40)->nullable();
            $table->boolean('reply_to_list')->default(false);
            $table->timestamps();

            $table->unique(['mail_domain_id', 'local_part']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mailing_lists');
    }
};
