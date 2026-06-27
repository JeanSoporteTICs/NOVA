<?php

namespace RedmineTic\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Modulos\Nova\Models\ModuloNova;

class RedmineTicHoraExtraGrupo extends Model
{
    public const CREATED_AT = 'creado_at';
    public const UPDATED_AT = 'actualizado_at';

    protected $table = 'redmine_tic_horas_extra_grupos';

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

    public function reportesPivot(): HasMany
    {
        return $this->hasMany(RedmineTicHoraExtraGrupoReporte::class, 'grupo_id');
    }
}
