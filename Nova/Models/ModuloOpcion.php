<?php

namespace App\Modulos\Nova\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModuloOpcion extends Model
{
    public const CREATED_AT = 'creado_at';
    public const UPDATED_AT = 'actualizado_at';

    protected $table = 'modulo_opciones';

    protected $fillable = [
        'modulo_id',
        'tipo',
        'id_externo',
        'nombre',
        'predeterminado',
        'activo',
        'orden',
    ];

    protected $casts = [
        'predeterminado' => 'boolean',
        'activo'         => 'boolean',
        'orden'          => 'integer',
    ];

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(ModuloNova::class, 'modulo_id');
    }
}
