<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PasswordRules;
use App\Support\UserPasswordResetter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ResetUserPassword extends Command
{
    protected $signature = 'dns-center:reset-password
        {--email= : E-mail do usuário}
        {--force-change : Exigir troca no próximo login}';

    protected $description = 'Redefine com segurança a senha de um usuário existente';

    public function handle(UserPasswordResetter $resetter): int
    {
        $email = mb_strtolower(trim((string) (
            $this->option('email') ?: $this->ask('E-mail do usuário')
        )));

        $emailValidator = Validator::make(
            ['email' => $email],
            ['email' => ['required', 'email:rfc', 'max:255']],
        );

        if ($emailValidator->fails()) {
            $this->error('Informe um endereço de e-mail válido.');

            return self::INVALID;
        }

        $user = User::query()
            ->with('currentOrganization')
            ->where('email', $email)
            ->first();

        if ($user === null) {
            $this->error('Usuário não encontrado.');

            return self::FAILURE;
        }

        $this->table(['Campo', 'Valor'], [
            ['Nome', $user->name],
            ['E-mail', $user->email],
            ['Status', $user->status],
            ['Administrador da plataforma', $user->is_platform_admin ? 'sim' : 'não'],
            ['Organização atual', $user->currentOrganization?->name ?? 'nenhuma'],
        ]);

        if (! $this->confirm('Confirma a redefinição da senha deste usuário?')) {
            $this->warn('Operação cancelada.');

            return self::FAILURE;
        }

        $password = (string) $this->secret(
            'Nova senha: mínimo 12 caracteres, maiúscula, minúscula, número e símbolo'
        );
        $confirmation = (string) $this->secret('Confirme a nova senha');

        if (! hash_equals($password, $confirmation)) {
            $this->error('As senhas não coincidem.');

            return self::INVALID;
        }

        $validator = Validator::make(
            ['password' => $password],
            ['password' => PasswordRules::rules(false)],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::INVALID;
        }

        try {
            $resetter->reset(
                $user,
                $password,
                (bool) $this->option('force-change'),
                'ADMIN_PASSWORD_RESET',
                'local/system',
                'CLI',
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Não foi possível redefinir a senha.');
            $this->line('Consulte storage/logs/laravel.log.');

            return self::FAILURE;
        }

        $this->info('Senha redefinida com sucesso.');

        return self::SUCCESS;
    }
}
