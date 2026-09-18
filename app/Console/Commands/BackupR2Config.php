<?php

namespace App\Console\Commands;

use App\Support\BackupR2Settings;
use Illuminate\Console\Command;

class BackupR2Config extends Command
{
    protected $signature = 'dns-center:backup-r2-config';

    protected $description = 'Imprime (KEY=VALOR) as credenciais do R2 configuradas no painel, para o script de backup do servidor';

    public function handle(): int
    {
        if (! BackupR2Settings::configured()) {
            return self::FAILURE;
        }

        $settings = BackupR2Settings::get();

        // Valores restritos a [A-Za-z0-9._/-] na validação: seguros para o script ler.
        foreach ([
            'R2_ACCOUNT_ID' => $settings['account_id'],
            'R2_BUCKET' => $settings['bucket'],
            'R2_PREFIX' => $settings['prefix'] ?? '',
            'R2_ACCESS_KEY_ID' => $settings['access_key_id'],
            'R2_SECRET_ACCESS_KEY' => $settings['secret_access_key'],
        ] as $name => $value) {
            $this->line($name.'='.$value);
        }

        return self::SUCCESS;
    }
}
