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
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            // Encrypted at rest (App\Models\Backup::casts()), matching TenantDatabase.password's
            // own precedent exactly: a real symmetric key, generated fresh per backup, needed
            // again for a future restore. Unlike a DKIM key (deliberately node-only, since its
            // whole purpose is a live signing operation on that same node), a backup encryption
            // key must be recoverable independently of the node that created it -- if the node
            // is ever lost, an encryption key that only ever lived on that same node would make
            // its own backup artifact permanently unreadable, defeating the entire point of
            // taking one. The control plane holding it is the correct, safer home.
            $table->text('encryption_key');
            // Deliberately reuses App\Enums\ProvisioningStatus rather than a
            // dedicated Backup-only status enum: a backup's own lifecycle is
            // exactly "one ProvisioningOperation, tracked here too for a
            // quick list view without a join," the same denormalization
            // WebDomain/MailDomain's own suspended_at already applies for
            // their own state. Defaults to Pending (matching
            // ProvisioningStatus::Pending's own string value) until
            // RecordsBackupArtifact updates it from the real terminal
            // result.
            $table->string('status')->default('pending');
            // Populated only once the real mail... no, the real backup
            // capability reports back via ResultEnvelope.data (see
            // RecordsBackupArtifact): which of the node's own backs_up-
            // declared capabilities were actually present and included,
            // the artifact's real size/checksum, and its own node-local
            // path. All nullable: a Pending or Failed backup has none of
            // these yet.
            $table->json('included_capabilities')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum')->nullable();
            $table->string('artifact_path')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('desired_state_version')->default(1);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
