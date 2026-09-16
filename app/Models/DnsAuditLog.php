<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsAuditLog extends Model
{
    protected $fillable = [
        'organization_id',
        'user_id',
        'actor',
        'action',
        'domain',
        'record_type',
        'record_name',
        'old_value',
        'new_value',
        'status',
        'message',
        'ip_address',
    ];

    private const ACTION_LABELS = [
        'zone.created' => 'Zona criada',
        'zone.settings_updated' => 'Parâmetros da zona atualizados',
        'zone.published' => 'Zona publicada',
        'zone.record.created' => 'Registro adicionado',
        'zone.record.updated' => 'Registro atualizado',
        'zone.record.deleted' => 'Registro removido',
        'server.created' => 'Servidor DNS criado',
        'server.updated' => 'Servidor DNS atualizado',
        'server.status_changed' => 'Status do servidor alterado',
        'user.created' => 'Usuário criado',
        'user.role_updated' => 'Papel do usuário alterado',
        'user.status_updated' => 'Status do usuário alterado',
        'organization.created' => 'Organização criada',
        'auth.login_succeeded' => 'Login',
        'auth.login_failed' => 'Login falhou',
        'auth.logout' => 'Logout',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForOrganization(
        Builder $query,
        int $organizationId,
    ): Builder {
        return $query->where('organization_id', $organizationId);
    }

    public static function actionLabel(string $action): string
    {
        return self::ACTION_LABELS[$action] ?? $action;
    }
}
