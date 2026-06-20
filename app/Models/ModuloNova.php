<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModuloNova extends Model
{
    public const CREATED_AT = 'creado_at';
    public const UPDATED_AT = 'actualizado_at';

    protected $table = 'modulos_nova';

    protected $fillable = [
        'clave_modulo',
        'nombre',
        'descripcion',
        'icono',
        'tipo',
        'ruta',
        'entrada',
        'habilitado',
        'en_mantencion',
        'orden',
    ];

    protected $casts = [
        'habilitado'     => 'boolean',
        'en_mantencion'  => 'boolean',
        'orden'          => 'integer',
    ];

    public function permisosUsuario(): HasMany
    {
        return $this->hasMany(PermisoUsuarioModulo::class, 'modulo_id');
    }

    public function configuraciones(): HasMany
    {
        return $this->hasMany(ConfiguracionModulo::class, 'modulo_id');
    }

    public function categorias(): HasMany
    {
        return $this->hasMany(Categoria::class, 'modulo_id');
    }

    public function unidades(): HasMany
    {
        return $this->hasMany(Unidad::class, 'modulo_id');
    }

    public function catalogos(): HasMany
    {
        return $this->hasMany(CatalogoModulo::class, 'modulo_id');
    }

    public function opciones(): HasMany
    {
        return $this->hasMany(ModuloOpcion::class, 'modulo_id');
    }
}
