<?php

namespace RedmineTic\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modulos\Nova\Models\ModuloNova;

class RedmineTicPermisoRol extends Model
{
    public $timestamps = false;

    protected $table = 'redmine_tic_permisos_rol';

    protected $fillable = [
        'modulo_id',
        'rol',
        'clave',
        'valor',
    ];

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(ModuloNova::class, 'modulo_id');
    }
}
