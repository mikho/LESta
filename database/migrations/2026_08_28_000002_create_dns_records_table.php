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
            $table->string('name', 191);
            $table->string('type', 16);
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
            // (ADR 0002). A `text` column would hit MySQL/MariaDB's InnoDB index key-length limit
            // and need a prefix-length index or a schema change before ever running there.
            //
            // `name` and `type` are bounded too, not left at the 255-char default, for the same
            // reason: InnoDB's own key-length limit is 3072 bytes, and utf8mb4 spends 4 bytes per
            // character. Four plain `string()` columns at 255 chars each would sum to well over
            // that limit on their own; `name` (191, the common Laravel/MySQL-safe bound for an
            // indexed string column) and `type` (16, comfortably fitting every real
            // App\Enums\DnsRecordType value, the longest of which is 5 characters) keep the whole
            // composite index at (191 + 16 + 512) * 4 = 2876 bytes, safely under 3072 with room
            // to spare for dns_zone_id's own few index bytes.
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
