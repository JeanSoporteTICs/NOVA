<?php

namespace RedmineTic\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RedmineTicHoraExtraGrupoReporte extends Model
{
    public $timestamps = false;

    protected $table = 'redmine_tic_horas_extra_grupo_reportes';

    protected $fillable = [
        'grupo_id',
        'reporte_id',
    ];

    protected $casts = [
        'creado_at' => 'datetime',
    ];

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(RedmineTicHoraExtraGrupo::class, 'grupo_id');
    }

    public function reporte(): BelongsTo
    {
        return $this->belongsTo(RedmineTicReporte::class, 'reporte_id');
    }
}
