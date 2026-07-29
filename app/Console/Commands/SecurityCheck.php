<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SecurityCheck extends Command
{
    protected $signature = 'dns-center:security-check';

    protected $description = 'Valida a baseline de segurança sem alterar o ambiente';

    public function handle(): int
    {
        $production = app()->environment('production');
        $blocked = [];
        $warnings = [];
        $ok = [];
        $environmentFailures = &$warnings;

        if ($production) {
            $environmentFailures = &$blocked;
        }

        $this->check(
            $production,
            'APP_ENV está definido como production',
            'APP_ENV não está definido como production',
            $warnings,
            $ok,
        );

        $this->check(
            ! $production || ! config('app.debug'),
            'APP_DEBUG está desativado',
            'APP_DEBUG=true em produção',
            $environmentFailures,
            $ok,
        );
        $this->check(
            ! $production || Str::startsWith((string) config('app.url'), 'https://'),
            'APP_URL usa HTTPS',
            'APP_URL precisa usar HTTPS em produção',
            $environmentFailures,
            $ok,
        );
        $this->check(
            ! $production || config('session.secure') === true,
            'Cookie de sessão exige HTTPS',
            'SESSION_SECURE_COOKIE precisa ser true em produção',
            $environmentFailures,
            $ok,
        );
        $this->check(
            config('session.http_only') === true,
            'Cookie de sessão é HttpOnly',
            'SESSION_HTTP_ONLY precisa ser true',
            $blocked,
            $ok,
        );
        $this->check(
            in_array(config('session.same_site'), ['lax', 'strict'], true),
            'SameSite da sessão é restritivo',
            'SESSION_SAME_SITE deve ser lax ou strict',
            $blocked,
            $ok,
        );
        $this->check(
            filled(config('app.key')),
            'Chave da aplicação está configurada',
            'APP_KEY não está configurada',
            $blocked,
            $ok,
        );
        $this->check(
            config('security.headers.enabled') === true,
            'Headers de segurança estão ativos',
            'SECURITY_HEADERS_ENABLED está desativado',
            $blocked,
            $ok,
        );
        $this->check(
            (int) config('security.login.global_ip_limit') > 0
                && (int) config('security.login.email_ip_limit') > 0,
            'Rate limits de login estão ativos',
            'Rate limit de login está desativado',
            $blocked,
            $ok,
        );
        $this->check(
            config('security.admin_2fa.required') === true,
            '2FA administrativo é obrigatório',
            'ADMIN_2FA_REQUIRED está desativado',
            $blocked,
            $ok,
        );
        $this->check(
            filled(config('logging.default')),
            'Canal de logs está configurado',
            'Canal de logs não está configurado',
            $warnings,
            $ok,
        );

        if (config('security.trusted_proxies') === []) {
            $warnings[] = 'TRUSTED_PROXIES vazio; correto sem proxy, configure CIDRs explícitos atrás de proxy.';
        } else {
            $ok[] = 'Proxies confiáveis foram configurados explicitamente';
        }

        $database = config('database.connections.'.config('database.default'));
        $username = strtolower((string) ($database['username'] ?? ''));
        $password = (string) ($database['password'] ?? '');

        if (
            in_array($username, ['postgres', 'root', 'admin'], true)
            || $password === ''
            || in_array(strtolower($password), ['password', 'postgres', 'changeme'], true)
        ) {
            $warnings[] = 'Credencial de banco vazia ou padrão conhecida detectada';
        } else {
            $ok[] = 'Credencial de banco não corresponde a padrões conhecidos';
        }

        $this->checkSensitiveFilePermissions($warnings, $ok);

        foreach ($ok as $message) {
            $this->line("<info>OK</info> {$message}");
        }
        foreach ($warnings as $message) {
            $this->line("<comment>WARNING</comment> {$message}");
        }
        foreach ($blocked as $message) {
            $this->line("<error>BLOCKED</error> {$message}");
        }

        $this->newLine();
        $this->warn(
            'VALIDAÇÃO EXTERNA: firewall, VPN/allowlist, PostgreSQL, Docker socket, backups e WAF não são configurados nem validados integralmente por este comando.',
        );

        if ($blocked !== []) {
            $this->error('RESULTADO: BLOCKED');

            return self::FAILURE;
        }

        if ($warnings !== []) {
            $this->warn('RESULTADO: WARNING');

            return 2;
        }

        $this->info('RESULTADO: OK');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $failures
     * @param  array<int, string>  $ok
     */
    private function check(
        bool $condition,
        string $success,
        string $failure,
        array &$failures,
        array &$ok,
    ): void {
        if ($condition) {
            $ok[] = $success;

            return;
        }

        $failures[] = $failure;
    }

    /**
     * @param  array<int, string>  $warnings
     * @param  array<int, string>  $ok
     */
    private function checkSensitiveFilePermissions(
        array &$warnings,
        array &$ok,
    ): void {
        $path = base_path('.env');

        if (! is_file($path) || DIRECTORY_SEPARATOR === '\\') {
            return;
        }

        $permissions = fileperms($path);

        if ($permissions === false) {
            $warnings[] = 'Não foi possível verificar permissões do .env';

            return;
        }

        if (($permissions & 0o077) !== 0) {
            $warnings[] = '.env pode ser lido ou escrito por grupo/outros';
        } else {
            $ok[] = 'Permissões básicas do .env são restritivas';
        }
    }
}
