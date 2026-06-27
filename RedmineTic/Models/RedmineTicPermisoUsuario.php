<?php

namespace RedmineTic\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RedmineTicPermisoUsuario extends Model
{
    public $timestamps = false;

    protected $table = 'redmine_tic_permisos_usuario';

    protected $fillable = [
        'perfil_id',
        'clave',
        'valor',
    ];

    public function perfil(): BelongsTo
    {
        return $this->belongsTo(RedmineTicPerfil::class, 'perfil_id');
    }
}
