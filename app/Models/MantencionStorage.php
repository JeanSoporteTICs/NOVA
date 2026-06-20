<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MantencionStorage extends Model
{
    protected $table = 'redmine_mantencion_storage';

    protected $fillable = [
        'path',
        'content_type',
        'payload_json',
        'payload_text',
        'bytes',
        'checksum',
        'source_mtime',
    ];

    protected $casts = [
        'bytes'        => 'integer',
        'source_mtime' => 'datetime',
    ];
}
