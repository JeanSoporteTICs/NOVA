<?php

namespace RedmineTic\Models;

use Illuminate\Database\Eloquent\Model;

class RedmineTicPermisoCatalogo extends Model
{
    public $timestamps = false;

    protected $table = 'redmine_tic_permisos_catalogo';

    protected $fillable = [
        'clave',
        'tipo',
        'descripcion',
        'orden',
    ];

    protected $casts = [
        'orden' => 'integer',
    ];
}
