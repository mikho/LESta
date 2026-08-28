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
        Schema::create('dns_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('dns_zone_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->unsignedInteger('priority')->nullable();
            $table->string('value', 512);
            $table->timestamp('suspended_at')->nullable()->index();
            $table->string('suspension_source')->nullable();
            $table->timestamps();

            // Full (zone, name, type, value) uniqueness is the DNS-correct constraint: records
            // that legitimately repeat name+type with a different value (round-robin A/AAAA,
            // multiple apex NS records, multiple TXT records) must stay distinct rows. `value` is
            // a bounded string (512 chars, comfortably fits realistic RDATA including
            // concatenated DKIM/SPF TXT values) rather than `text` specifically so this index
            // works unmodified on this project's decided production database, MariaDB 11.4 LTS
            // (ADR 0002), and not only on sqlite, which is what local dev/testing actually uses
            // today per config/database.php's default. A `text` column would hit MySQL/MariaDB's
            // InnoDB index key-length limit and need a prefix-length index or a schema change
            // before ever running there.
            $table->unique(['dns_zone_id', 'name', 'type', 'value']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dns_records');
    }
};
