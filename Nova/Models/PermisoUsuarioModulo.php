<?php

namespace App\Modulos\Nova\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PermisoUsuarioModulo extends Model
{
    public const CREATED_AT = 'creado_at';
    public const UPDATED_AT = 'actualizado_at';

    protected $table = 'permisos_usuario_modulo';

    protected $fillable = [
        'usuario_id',
        'modulo_id',
        'permitido',
    ];

    protected $casts = [
        'permitido' => 'boolean',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(NovaUser::class, 'usuario_id');
    }

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(ModuloNova::class, 'modulo_id');
    }
}
