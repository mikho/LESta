<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            // Matches every other admin-managed resource's own route-key convention (Node,
            // Backup, Account, ...) -- Package was the one resource in the app still routable by
            // its raw internal id, since it never had a real route at all until now. Nullable
            // first: real existing rows (the seeded default package) need a one-time backfill
            // below before this can be made required.
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });

        DB::table('packages')->orderBy('id')->select('id')->each(function (object $row): void {
            DB::table('packages')->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
