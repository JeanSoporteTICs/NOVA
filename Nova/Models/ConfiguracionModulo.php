<?php

namespace App\Modulos\Nova\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfiguracionModulo extends Model
{
    public const CREATED_AT = 'creado_at';
    public const UPDATED_AT = 'actualizado_at';

    protected $table = 'configuraciones_modulo';

    protected $fillable = [
        'modulo_id',
        'clave',
        'valor',
        'tipo',
    ];

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(ModuloNova::class, 'modulo_id');
    }
}
