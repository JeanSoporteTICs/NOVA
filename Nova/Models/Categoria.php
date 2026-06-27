<?php

namespace App\Modulos\Nova\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Categoria extends Model
{
    public const CREATED_AT = 'creado_at';
    public const UPDATED_AT = 'actualizado_at';

    protected $table = 'categorias';

    protected $fillable = [
        'modulo_id',
        'nombre',
        'clave_externa',
        'activo',
        'predeterminado',
    ];

    protected $casts = [
        'activo'        => 'boolean',
        'predeterminado' => 'boolean',
    ];

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(ModuloNova::class, 'modulo_id');
    }
}
