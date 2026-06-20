<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'telegram_id_chat',
        'ultimo_login_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'ultimo_login_at' => 'datetime',
    ];

    public function permisosModulo(): HasMany
    {
        return $this->hasMany(PermisoUsuarioModulo::class, 'usuario_id');
    }

    public function integraciones(): HasMany
    {
        return $this->hasMany(IntegracionUsuario::class, 'usuario_id');
    }

    public function perfilTic(): HasOne
    {
        return $this->hasOne(\RedmineTic\Models\RedmineTicPerfil::class, 'usuario_id');
    }
}
