<?php

namespace App\Modulos\Nova\Models;

use Illuminate\Database\Eloquent\Model;

class NovaSetting extends Model
{
    // Table has only actualizado_at; no created_at column.
    public $timestamps = false;

    protected $table = 'nova_settings';

    protected $fillable = [
        'clave',
        'valor',
        'tipo',
    ];
}
