<?php
function log_security_event(string $tag, string $details): void {
    try {
        $sessionUser = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
        $context = array_filter([
            'user_id' => trim((string)($sessionUser['id'] ?? '')),
            'user_name' => trim((string)(($sessionUser['nombre'] ?? '') . ' ' . ($sessionUser['apellido'] ?? ''))),
        ], static fn(string $value): bool => $value !== '');
        \Illuminate\Support\Facades\DB::table('mantencion_log')->insert([
            'canal' => 'seguridad', 'tipo' => $tag, 'detalle' => $details,
            'contexto' => $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'registrado_at' => now(),
        ]);
    } catch (Throwable) {}
}
