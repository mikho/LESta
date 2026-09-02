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
        Schema::create('tenant_databases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('node_id')->constrained()->restrictOnDelete();
            $table->string('label', 40);
            $table->string('database_name', 64)->unique();
            $table->string('database_user', 64);
            // Encrypted at rest (App\Models\TenantDatabase::casts()), matching
            // AcmeAccount.account_key's own precedent. Unlike that key, this
            // password genuinely must reach the node at least once (on create
            // and on a dedicated rotate operation); see
            // TenantDatabase::toProvisioningPayload()'s own doc comment for the
            // explicit-parameter invariant that keeps every other verb's
            // payload from ever carrying it.
            $table->text('password');
            $table->unsignedInteger('desired_state_version')->default(1);
            $table->timestamp('suspended_at')->nullable()->index();
            $table->string('suspension_source')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'label']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_databases');
    }
};
