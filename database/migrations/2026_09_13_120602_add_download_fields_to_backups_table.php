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
        Schema::table('backups', function (Blueprint $table) {
            // Populated by PreparesBackupDownload once a real, decrypted plaintext copy has
            // been written to local storage: download_path is a storage-disk-relative path (never
            // exposed to the browser directly), download_ready_at/download_expires_at bound how
            // long that plaintext copy is allowed to sit on disk before a scheduled prune command
            // deletes it, since it is real decrypted config-plane content, not itself
            // re-encrypted at rest the way the sealed node-side artifact is.
            $table->string('download_path')->nullable()->after('artifact_path');
            $table->timestamp('download_ready_at')->nullable()->after('download_path');
            $table->timestamp('download_expires_at')->nullable()->after('download_ready_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn(['download_path', 'download_ready_at', 'download_expires_at']);
        });
    }
};
