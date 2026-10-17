<?php

use App\Models\AuditEvent;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The account an audit event belongs to, so an account's members can read their own log.
     * Derived from the audited resource (AuditEvent::resolveAccountId), so it is not part of the
     * hash chain: the existing rows keep verifying. Existing rows are filled in here, and the
     * audit_events.view_any permission (the platform-wide log) is granted to provider_admin, since
     * upgrades run migrations, not the seeders.
     */
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id')->nullable()->after('auditable_id')->index();
        });

        AuditEvent::query()->orderBy('id')->chunkById(500, function ($events): void {
            foreach ($events as $event) {
                $accountId = $event->resolveAccountId();

                if ($accountId !== null) {
                    AuditEvent::query()->whereKey($event->id)->update(['account_id' => $accountId]);
                }
            }
        });

        $permission = Permission::query()->firstOrCreate(['name' => 'audit_events.view_any']);
        Role::query()->where('name', 'provider_admin')->first()?->permissions()->syncWithoutDetaching([$permission->id]);
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropColumn('account_id');
        });

        Permission::query()->where('name', 'audit_events.view_any')->first()?->roles()->detach();
        Permission::query()->where('name', 'audit_events.view_any')->delete();
    }
};
