<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MantencionReporte extends Model
{
    public const CREATED_AT = 'creado_at';
    public const UPDATED_AT = 'actualizado_at';

    protected $table = 'redmine_mantencion_reportes';

    protected $fillable = [
        'modulo_id',
        'fuente',
        'fuente_id',
        'id_core',
        'proyecto',
        'project_id',
        'tipo',
        'tipo_id',
        'asunto',
        'descripcion',
        'estado',
        'estado_redmine',
        'estado_id',
        'prioridad',
        'priority_id',
        'id_redmine_asignado',
        'asignado_nombre',
        'categoria_id',
        'solicitante',
        'anexo',
        'unidad_texto',
        'fecha_inicio',
        'fecha_fin',
        'fecha_reporte',
        'hora_reporte',
        'tiempo_estimado',
        'correo',
        'hora_extra',
        'numero_ticket_redmine',
    ];

    protected $casts = [
        'hora_extra'       => 'boolean',
        'tiempo_estimado'  => 'decimal:2',
        'fecha_inicio'     => 'date',
        'fecha_fin'        => 'date',
        'fecha_reporte'    => 'date',
    ];

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(ModuloNova::class, 'modulo_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function gruposHoraExtra(): BelongsToMany
    {
        return $this->belongsToMany(
            MantencionHoraExtraGrupo::class,
            'redmine_mantencion_horas_extra_reportes',
            'reporte_id',
            'grupo_id'
        );
    }
}
