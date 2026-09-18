<?php

namespace App\Support;

use App\Models\PlatformSetting;

/**
 * Credenciais da cópia de backup no Cloudflare R2, guardadas criptografadas.
 * A senha de criptografia dos dumps NÃO fica aqui de propósito: se ficasse no
 * banco, o backup não abriria justamente no desastre em que o banco se perde.
 */
class BackupR2Settings
{
    public const KEY = 'backup_r2';

    public const FIELDS = ['account_id', 'bucket', 'prefix', 'access_key_id', 'secret_access_key'];

    public static function record(): ?PlatformSetting
    {
        return PlatformSetting::query()->where('key', self::KEY)->first();
    }

    /**
     * @return array<string, string>|null
     */
    public static function get(): ?array
    {
        $value = self::record()?->value;

        return is_array($value) ? $value : null;
    }

    public static function configured(): bool
    {
        $value = self::get();

        return $value !== null
            && filled($value['account_id'] ?? null)
            && filled($value['bucket'] ?? null)
            && filled($value['access_key_id'] ?? null)
            && filled($value['secret_access_key'] ?? null);
    }

    /**
     * @param  array<string, string>  $values
     */
    public static function save(array $values, ?int $userId): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => array_intersect_key($values, array_flip(self::FIELDS)), 'updated_by' => $userId],
        );
    }

    public static function forget(): void
    {
        PlatformSetting::query()->where('key', self::KEY)->delete();
    }

    /** "6969…4e5b": suficiente para reconhecer a chave, sem expô-la. */
    public static function mask(?string $value): string
    {
        if ($value === null || strlen($value) < 12) {
            return $value ? '••••' : '';
        }

        return substr($value, 0, 4).'…'.substr($value, -4);
    }
}
