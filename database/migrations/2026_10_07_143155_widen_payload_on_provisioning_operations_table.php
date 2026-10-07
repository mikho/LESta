<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A file uploaded through the browser file manager travels as base64 inside this payload, and
     * the payload is stored encrypted (which grows it again by about a third). As a 64 KB `text`
     * column that capped uploads at roughly 36 KB and made anything larger a database error. The
     * 4 MB upload limit (StoreFileRequest::MAX_FILE_BYTES) encrypts to under 8 MB, so MEDIUMTEXT
     * (16 MB) holds it with room to spare.
     */
    public function up(): void
    {
        Schema::table('provisioning_operations', function (Blueprint $table) {
            $table->mediumText('payload')->change();
        });
    }

    /**
     * Narrowing fails on MariaDB if any stored payload is now larger than 64 KB, which is the
     * correct outcome: it would otherwise silently truncate an encrypted payload.
     */
    public function down(): void
    {
        Schema::table('provisioning_operations', function (Blueprint $table) {
            $table->text('payload')->change();
        });
    }
};
