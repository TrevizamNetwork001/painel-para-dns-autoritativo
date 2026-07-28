<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'dns_nameserver_identities',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('organization_id')
                    ->constrained()
                    ->cascadeOnDelete();

                /*
                 * O servidor físico é opcional porque uma identidade
                 * também pode representar DNS externo, Anycast, NAT
                 * ou infraestrutura administrada por terceiros.
                 */
                $table->foreignId('dns_server_id')
                    ->nullable()
                    ->constrained('dns_servers')
                    ->nullOnDelete();

                $table->string('name', 120);
                $table->string('hostname', 255);

                $table->string('ipv4_address', 45)
                    ->nullable();

                $table->string('ipv6_address', 45)
                    ->nullable();

                $table->boolean('enabled')
                    ->default(true);

                $table->text('notes')
                    ->nullable();

                $table->timestampsTz();

                $table->unique(
                    [
                        'organization_id',
                        'hostname',
                    ],
                    'dns_ns_identities_org_hostname_unique',
                );

                $table->index(
                    [
                        'organization_id',
                        'enabled',
                    ],
                    'dns_ns_identities_org_enabled_index',
                );

                $table->index(
                    [
                        'organization_id',
                        'dns_server_id',
                    ],
                    'dns_ns_identities_org_server_index',
                );
            },
        );

        Schema::create(
            'dns_nameserver_profiles',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('organization_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->string('name', 120);

                $table->boolean('is_default')
                    ->default(false);

                $table->boolean('enabled')
                    ->default(true);

                $table->text('notes')
                    ->nullable();

                $table->timestampsTz();

                $table->unique(
                    [
                        'organization_id',
                        'name',
                    ],
                    'dns_ns_profiles_org_name_unique',
                );

                $table->index(
                    [
                        'organization_id',
                        'enabled',
                    ],
                    'dns_ns_profiles_org_enabled_index',
                );
            },
        );

        Schema::create(
            'dns_nameserver_profile_identity',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('dns_nameserver_profile_id')
                    ->constrained('dns_nameserver_profiles')
                    ->cascadeOnDelete();

                $table->foreignId('dns_nameserver_identity_id')
                    ->constrained('dns_nameserver_identities')
                    ->cascadeOnDelete();

                /*
                 * A posição define a ordem administrativa:
                 * primeiro NS, segundo NS e assim por diante.
                 */
                $table->unsignedSmallInteger('position')
                    ->default(1);

                $table->timestampsTz();

                $table->unique(
                    [
                        'dns_nameserver_profile_id',
                        'dns_nameserver_identity_id',
                    ],
                    'dns_ns_profile_identity_unique',
                );

                $table->unique(
                    [
                        'dns_nameserver_profile_id',
                        'position',
                    ],
                    'dns_ns_profile_position_unique',
                );
            },
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'dns_nameserver_profile_identity',
        );

        Schema::dropIfExists(
            'dns_nameserver_profiles',
        );

        Schema::dropIfExists(
            'dns_nameserver_identities',
        );
    }
};
