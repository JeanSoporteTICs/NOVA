{{ app(\App\Modulos\Nova\Services\NovaUserService::class)->fullName(is_array(session('nova_user')) ? session('nova_user') : []) ?: (session('nova_user.username') ?: 'Usuario') }}
