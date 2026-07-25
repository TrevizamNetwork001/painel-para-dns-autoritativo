<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

class CreatePlatformAdmin extends Command
{
    protected $signature = 'dns-center:create-admin
        {--organization=trevizam-network : Slug da empresa inicial}
        {--name= : Nome completo}
        {--email= : E-mail do administrador}';

    protected $description = 'Cria ou atualiza o primeiro administrador do DNS Center';

    public function handle(): int
    {
        $organization = Organization::query()
            ->where('slug', (string) $this->option('organization'))
            ->where('status', 'active')
            ->first();

        if (! $organization) {
            $this->error('Empresa ativa não encontrada.');

            return self::FAILURE;
        }

        $name = trim((string) (
            $this->option('name')
            ?: $this->ask('Nome completo')
        ));

        $email = mb_strtolower(trim((string) (
            $this->option('email')
            ?: $this->ask('E-mail')
        )));

        $password = (string) $this->secret(
            'Senha: mínimo 12 caracteres, maiúscula, minúscula, número e símbolo'
        );

        $confirmation = (string) $this->secret('Confirme a senha');

        if ($password !== $confirmation) {
            $this->error('As senhas não coincidem.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            [
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ],
            [
                'name' => ['required', 'string', 'min:3', 'max:150'],
                'email' => ['required', 'email:rfc', 'max:255'],
                'password' => [
                    'required',
                    Password::min(12)
                        ->mixedCase()
                        ->numbers()
                        ->symbols(),
                ],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        try {
            $user = DB::transaction(function () use (
                $organization,
                $name,
                $email,
                $password
            ): User {
                $user = User::query()->firstOrNew([
                    'email' => $email,
                ]);

                $user->forceFill([
                    'name' => $name,
                    'password' => Hash::make($password),
                    'current_organization_id' => $organization->id,
                    'is_platform_admin' => true,
                    'status' => 'active',
                    'email_verified_at' => now(),
                ])->save();

                $user->organizations()->syncWithoutDetaching([
                    $organization->id => [
                        'role' => 'organization_admin',
                        'status' => 'active',
                        'is_default' => true,
                    ],
                ]);

                return $user->fresh([
                    'currentOrganization',
                    'organizations',
                ]);
            });
        } catch (Throwable $exception) {
            report($exception);

            $this->error('Não foi possível criar o administrador.');
            $this->line('Consulte storage/logs/laravel.log.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Administrador configurado com sucesso.');

        $this->table(
            ['Campo', 'Valor'],
            [
                ['Nome', $user->name],
                ['E-mail', $user->email],
                ['Empresa', $organization->name],
                ['Papel', 'organization_admin'],
                ['Administrador da plataforma', 'sim'],
                ['Status', $user->status],
            ],
        );

        return self::SUCCESS;
    }
}
