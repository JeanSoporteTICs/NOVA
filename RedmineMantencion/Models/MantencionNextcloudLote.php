<?php

namespace App\Modulos\RedmineMantencion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MantencionNextcloudLote extends Model
{
    protected $table = 'redmine_mantencion_nextcloud_historial_lotes';

    protected $fillable = [
        'modulo_id',
        'legacy_id',
        'created_at_cl',
        'expires_at',
    ];

    protected $casts = [
        'created_at_cl' => 'datetime',
        'expires_at'    => 'datetime',
    ];

    public function usuarios(): HasMany
    {
        return $this->hasMany(MantencionNextcloudUsuario::class, 'lote_id');
    }
}
