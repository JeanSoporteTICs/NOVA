<?php

namespace RedmineTic\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\NovaUser;

class RedmineTicPerfil extends Model
{
    public const CREATED_AT = 'creado_at';
    public const UPDATED_AT = 'actualizado_at';

    protected $table = 'redmine_tic_perfiles_usuario';

    protected $fillable = [
        'usuario_id',
        'rol',
        'estado_usuario',
        'redmine_membership_id',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(NovaUser::class, 'usuario_id');
    }

    public function permisos(): HasMany
    {
        return $this->hasMany(RedmineTicPermisoUsuario::class, 'perfil_id');
    }
}
