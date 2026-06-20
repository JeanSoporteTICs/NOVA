<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogoModulo extends Model
{
    public const CREATED_AT = 'creado_at';
    public const UPDATED_AT = 'actualizado_at';

    protected $table = 'catalogos_modulo';

    protected $fillable = [
        'modulo_id',
        'tipo',
        'clave_externa',
        'nombre',
        'predeterminado',
        'activo',
    ];

    protected $casts = [
        'predeterminado' => 'boolean',
        'activo'         => 'boolean',
    ];

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(ModuloNova::class, 'modulo_id');
    }
}
