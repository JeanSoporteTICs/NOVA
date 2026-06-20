<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MantencionHoraExtraGrupo extends Model
{
    public const CREATED_AT = 'creado_at';
    public const UPDATED_AT = 'actualizado_at';

    protected $table = 'redmine_mantencion_horas_extra_grupos';

    protected $fillable = [
        'modulo_id',
        'fecha',
        'hora_inicio',
        'hora_fin',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(ModuloNova::class, 'modulo_id');
    }

    public function reportes(): BelongsToMany
    {
        return $this->belongsToMany(
            MantencionReporte::class,
            'redmine_mantencion_horas_extra_reportes',
            'grupo_id',
            'reporte_id'
        );
    }
}
