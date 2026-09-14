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
        Schema::table('accounts', function (Blueprint $table) {
            // Self-referential, nullable: a reseller is just an ordinary Account that other
            // accounts point at, not a new top-level model. restrictOnDelete mirrors package_id's
            // own restrict-on-delete: a reseller account with managed accounts under it cannot be
            // deleted out from under them. One level only -- the action that assigns an account to
            // a reseller rejects the assignment if the candidate reseller account itself already
            // has a reseller_account_id set (see AssignAccountToReseller's own doc comment).
            $table->foreignId('reseller_account_id')->nullable()->after('package_id')
                ->constrained('accounts')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reseller_account_id');
        });
    }
};
