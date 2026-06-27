<?php

namespace RedmineTic\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modulos\Nova\Models\ModuloNova;

class RedmineTicActivityLog extends Model
{
    public $timestamps = false;

    protected $table = 'redmine_tic_activity_logs';

    protected $fillable = [
        'modulo_id',
        'evento',
        'contexto',
        'linea',
    ];

    protected $casts = [
        'creado_at' => 'datetime',
    ];

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(ModuloNova::class, 'modulo_id');
    }
}
