<?php

namespace App\Modulos\Nova\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegracionUsuario extends Model
{
    public const CREATED_AT = 'creado_at';
    public const UPDATED_AT = 'actualizado_at';

    protected $table = 'integraciones_usuario';

    protected $fillable = [
        'usuario_id',
        'tipo',
        'usuario_externo',
        'valor_secreto',
    ];

    protected $hidden = [
        'valor_secreto',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(NovaUser::class, 'usuario_id');
    }
}
