<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MantencionPermisoRol extends Model
{
    public $timestamps = false;

    protected $table = 'mantencion_permisos_rol';

    protected $fillable = [
        'rol',
        'permiso',
        'valor',
    ];
}
