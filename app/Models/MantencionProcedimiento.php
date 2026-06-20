<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MantencionProcedimiento extends Model
{
    public const CREATED_AT = 'creado_at';
    public const UPDATED_AT = 'actualizado_at';

    protected $table = 'redmine_mantencion_procedimientos';

    protected $fillable = [
        'modulo_id',
        'legacy_id',
        'record_type',
        'folder_id',
        'share_token',
        'title',
        'content_html',
        'page_size',
        'file_name',
        'file_original_name',
        'file_mime',
        'file_size',
        'file_url',
        'storage_driver',
        'nextcloud_path',
        'nextcloud_share_id',
        'nextcloud_share_url',
        'uploaded_at',
        'draft_pending',
        'author_id',
        'author_name',
    ];

    protected $casts = [
        'file_size'     => 'integer',
        'draft_pending' => 'boolean',
        'uploaded_at'   => 'datetime',
    ];
}
