<?php

namespace App\Modulos\RedmineMantencion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MantencionNextcloudUsuario extends Model
{
    protected $table = 'redmine_mantencion_nextcloud_historial_usuarios';

    protected $fillable = [
        'lote_id',
        'tipo',
        'userid',
        'display_name',
        'email',
        'grupo',
        'password',
        'status',
        'message',
    ];

    protected $hidden = ['password'];

    public function lote(): BelongsTo
    {
        return $this->belongsTo(MantencionNextcloudLote::class, 'lote_id');
    }
}
