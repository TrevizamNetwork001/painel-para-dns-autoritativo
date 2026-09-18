<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // O valor nunca fica em texto puro no banco (nem nos dumps de backup).
            'value' => 'encrypted:array',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
