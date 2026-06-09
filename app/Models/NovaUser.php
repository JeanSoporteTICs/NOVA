<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NovaUser extends Model
{
    public const CREATED_AT = 'creado_at';
    public const UPDATED_AT = 'actualizado_at';

    protected $table = 'usuarios_nova';

    protected $fillable = [
        'uuid',
        'usuario',
        'rut',
        'redmine_id',
        'nombre',
        'apellido',
        'email',
        'rol',
        'estado',
        'password',
        'usuario_core',
        'ultimo_login_at',
    ];

    protected $hidden = [
        'password',
    ];
}
