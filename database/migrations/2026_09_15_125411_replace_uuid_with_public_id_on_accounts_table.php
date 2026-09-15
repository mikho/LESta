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
        Schema::table('accounts', function (Blueprint $table) {
            // Replaces `uuid` as this model's own route-key/display identifier: a full v4 UUID is
            // already unguessable, but the user specifically wants a shorter token that is never
            // derived from (or correlated with) this row's own sequential id, to keep an
            // enumeration attempt from ever confirming which accounts exist on the server. A
            // dedicated column, not a reuse of `uuid`, since a 12-character value living in a
            // column named and documented as `uuid` would mislead a future reader.
            $table->string('public_id', 12)->nullable()->unique()->after('id');
        });

        DB::table('accounts')->orderBy('id')->select('id')->each(function (object $row): void {
            DB::table('accounts')->where('id', $row->id)->update(['public_id' => self::generateUniquePublicId()]);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('public_id', 12)->nullable(false)->change();
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }

    private static function generateUniquePublicId(): string
    {
        do {
            $candidate = Str::random(12);
        } while (DB::table('accounts')->where('public_id', $candidate)->exists());

        return $candidate;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });

        DB::table('accounts')->orderBy('id')->select('id')->each(function (object $row): void {
            DB::table('accounts')->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
