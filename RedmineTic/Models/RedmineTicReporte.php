<?php

namespace RedmineTic\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modulos\Nova\Models\ModuloNova;
use App\Modulos\Nova\Models\CatalogoModulo;

class RedmineTicReporte extends Model
{
    public const CREATED_AT = 'creado_at';
    public const UPDATED_AT = 'actualizado_at';

    protected $table = 'redmine_tic_reportes';

    protected $fillable = [
        'modulo_id',
        'redmine_id',
        'estado',
        'estado_redmine',
        'tipo',
        'prioridad',
        'categoria_catalogo_id',
        'unidad_catalogo_id',
        'unidad_solicitante_catalogo_id',
        'solicitante',
        'asunto',
        'descripcion',
        'fecha',
        'hora',
        'fecha_inicio',
        'fecha_fin',
        'chat_id_telegram',
        'mensaje',
        'asignado_a',
        'hora_extra',
        'tiempo_estimado',
        'origen',
        'procesado_at',
    ];

    protected $casts = [
        'hora_extra'      => 'boolean',
        'tiempo_estimado' => 'decimal:2',
        'fecha'           => 'date',
        'fecha_inicio'    => 'date',
        'fecha_fin'       => 'date',
        'procesado_at'    => 'datetime',
    ];

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(ModuloNova::class, 'modulo_id');
    }

    public function categoriaCatalogo(): BelongsTo
    {
        return $this->belongsTo(CatalogoModulo::class, 'categoria_catalogo_id');
    }

    public function unidadCatalogo(): BelongsTo
    {
        return $this->belongsTo(CatalogoModulo::class, 'unidad_catalogo_id');
    }

    public function unidadSolicitanteCatalogo(): BelongsTo
    {
        return $this->belongsTo(CatalogoModulo::class, 'unidad_solicitante_catalogo_id');
    }
}
