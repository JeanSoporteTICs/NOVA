<?php

namespace App\Modulos\Nova\Models;

use Illuminate\Database\Eloquent\Model;

class NovaAuditLog extends Model
{
    // Table uses registrado_at as its only time column; not managed by Eloquent timestamps.
    public $timestamps = false;

    protected $table = 'nova_audit_logs';

    protected $fillable = [
        'event',
        'message',
        'user_id',
        'user_name',
        'ip',
        'contexto',
    ];

    protected $casts = [
        'contexto'      => 'array',
        'registrado_at' => 'datetime',
    ];
}
