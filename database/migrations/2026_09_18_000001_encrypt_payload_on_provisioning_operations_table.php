<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * provisioning_operations.payload regularly carries real secrets in plaintext on the way to
     * a node (a backup's AES-256-GCM encryption key, an ACME-issued certificate's private key, a
     * freshly generated mailbox password) even though every one of those secrets' own primary
     * storage column (Backup::encryption_key, AcmeAccount::account_key, MailAccount::password) is
     * already Laravel `encrypted`. This closes that gap by encrypting payload the same way. A
     * native JSON column cannot hold Laravel's encrypted string (it is not valid JSON, so MySQL/
     * MariaDB's own JSON CHECK constraint would reject every insert), so the column itself widens
     * to text first -- mirrors backups.encryption_key and mail_accounts.password, both already
     * text columns for exactly this reason.
     */
    public function up(): void
    {
        Schema::table('provisioning_operations', function (Blueprint $table) {
            $table->text('payload')->change();
        });

        // Crypt::encryptString()/decryptString() below, never the global encrypt()/decrypt()
        // helpers (which default to $serialize = true): Eloquent's own `encrypted:array` cast
        // calls the encrypter with serialize FALSE (HasAttributes::castAttributeAsEncryptedString),
        // so backfilling with the serializing helpers would write data the model's own cast could
        // never correctly decrypt again -- confirmed the hard way (an unserialize() failure on
        // rollback) before landing on this fix.
        DB::table('provisioning_operations')->orderBy('id')->chunkById(200, function ($operations) {
            foreach ($operations as $operation) {
                DB::table('provisioning_operations')
                    ->where('id', $operation->id)
                    ->update(['payload' => Crypt::encryptString($operation->payload)]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('provisioning_operations')->orderBy('id')->chunkById(200, function ($operations) {
            foreach ($operations as $operation) {
                DB::table('provisioning_operations')
                    ->where('id', $operation->id)
                    ->update(['payload' => Crypt::decryptString($operation->payload)]);
            }
        });

        Schema::table('provisioning_operations', function (Blueprint $table) {
            $table->json('payload')->change();
        });
    }
};
