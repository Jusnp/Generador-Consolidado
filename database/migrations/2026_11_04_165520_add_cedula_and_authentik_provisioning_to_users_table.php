<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega la cédula (usuario en Authentik) y los identificadores y el estado
     * del aprovisionamiento. Los usuarios existentes conservan su vinculación:
     * los que ya tienen subject OIDC quedan como vinculados; el resto, sin vincular.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('cedula', 20)->nullable()->unique()->after('name');
            $table->unsignedBigInteger('authentik_pk')->nullable()->unique()->after('authentik_subject');
            $table->uuid('authentik_uuid')->nullable()->unique()->after('authentik_pk');
            $table->string('authentik_status', 20)->default('unlinked')->after('authentik_uuid');
            $table->uuid('authentik_provisioning_key')->nullable()->unique()->after('authentik_status');
            $table->string('authentik_sync_error', 500)->nullable()->after('authentik_provisioning_key');
            $table->timestamp('authentik_provisioned_at')->nullable()->after('authentik_sync_error');
            $table->timestamp('authentik_first_login_at')->nullable()->after('authentik_provisioned_at');
        });

        DB::table('users')
            ->whereNotNull('authentik_subject')
            ->update(['authentik_status' => 'linked']);
    }

    /**
     * Elimina las columnas del aprovisionamiento. El correo sigue admitiendo nulos:
     * los usuarios creados sin correo impedirían restaurar NOT NULL.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['cedula']);
            $table->dropUnique(['authentik_pk']);
            $table->dropUnique(['authentik_uuid']);
            $table->dropUnique(['authentik_provisioning_key']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'cedula',
                'authentik_pk',
                'authentik_uuid',
                'authentik_status',
                'authentik_provisioning_key',
                'authentik_sync_error',
                'authentik_provisioned_at',
                'authentik_first_login_at',
            ]);
        });
    }
};
