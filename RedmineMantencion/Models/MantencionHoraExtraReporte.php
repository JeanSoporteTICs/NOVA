<?php

namespace App\Modulos\RedmineMantencion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MantencionHoraExtraReporte extends Model
{
    public const CREATED_AT = 'creado_at';
    public const UPDATED_AT = 'actualizado_at';

    protected $table = 'redmine_mantencion_horas_extra_reportes';

    protected $fillable = [
        'grupo_id',
        'reporte_id',
    ];

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(MantencionHoraExtraGrupo::class, 'grupo_id');
    }

    public function reporte(): BelongsTo
    {
        return $this->belongsTo(MantencionReporte::class, 'reporte_id');
    }
}
