<?php

namespace App\Modulos\Telegram\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TelegramCommandSettingsRepository
{
    private const MODULE_KEY = 'telegram';

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        $settings = $this->loadFromDb();

        return array_replace_recursive($this->defaults(), $settings);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function save(array $payload): bool
    {
        $settings = $this->all();
        $commands = [];
        foreach (array_keys($this->defaults()['commands']) as $key) {
            $commands[$key] = [
                'enabled' => (bool) data_get($payload, "commands.{$key}.enabled", false),
            ];
        }

        $messages = [];
        foreach ($this->defaults()['messages'] as $key => $default) {
            $value = trim((string) data_get($payload, "messages.{$key}", ''));
            $messages[$key] = $value !== '' ? $value : $default;
        }

        $settings['commands'] = $commands;
        $settings['messages'] = $messages;
        $settings['updated_at'] = date(DATE_ATOM);

        return $this->saveToDb($settings);
    }

    public function commandEnabled(string $key): bool
    {
        return (bool) data_get($this->all(), "commands.{$key}.enabled", true);
    }

    public function path(): string
    {
        return 'configuraciones_modulo:telegram';
    }

    public function message(string $key): string
    {
        return (string) data_get($this->all(), "messages.{$key}", data_get($this->defaults(), "messages.{$key}", ''));
    }

    public function render(string $key, array $replace = []): string
    {
        $message = $this->message($key);
        foreach ($replace as $name => $value) {
            $message = str_replace('{'.$name.'}', (string) $value, $message);
        }

        return $message;
    }

    /**
     * @param  array<string,mixed>  $replace
     */
    public function renderEmachMark(array $replace): string
    {
        return $this->render(
            $this->emachMessageKey((string) ($replace['tipo'] ?? '')),
            $replace
        );
    }

    public function emachMessageKey(string $type): string
    {
        $normalizedType = strtoupper(trim($type));

        if (str_contains($normalizedType, 'ENTRADA')) {
            return 'emach_success_entrada';
        }

        if (str_contains($normalizedType, 'SALIDA')) {
            return 'emach_success_salida';
        }

        return 'emach_success';
    }

    /**
     * @return array<string,mixed>
     */
    public function defaults(): array
    {
        return [
            'commands' => [
                'help' => ['enabled' => true],
                'status' => ['enabled' => true],
                'chat_id' => ['enabled' => true],
                'emach' => ['enabled' => true],
                'tic' => ['enabled' => true],
                'test' => ['enabled' => true],
            ],
            'messages' => [
                'help_header' => 'Comandos Telegram NOVA:',
                'status' => "Servicio Telegram NOVA activo\nFecha: {fecha}",
                'chat_id' => "Tu Chat ID es: {chat_id}\nCopia este número y guárdalo en NOVA > Mis integraciones > Telegram.",
                'test' => 'Mensaje de prueba desde Telegram NOVA: {fecha}',
                'tic_success' => "Reporte TIC recibido\nAsunto: {asunto}\nCategoría: {categoria}\nUnidad: {unidad}\nEstado: pendiente",
                'tic_unavailable' => 'No pude cargar Redmine TIC desde el listener Telegram.',
                'tic_error' => 'No pude crear el reporte TIC: {error}',
                'tic_mode_activated' => "Modo TIC activado hasta {hasta}.\nAhora puedes enviar: problema, unidad, solicitante",
                'tic_mode_deactivated' => 'Modo TIC desactivado. Los mensajes normales ya no se crearán como reportes.',
                'tic_mode_status_active' => "El modo TIC está activo hasta {hasta}.\nFormato: problema, unidad, solicitante",
                'tic_mode_status_inactive' => 'El modo TIC está inactivo. Usa /tic activar para habilitarlo durante el día.',
                'tic_mode_invalid_format' => "No se creó el reporte.\nUsa el formato: problema, unidad, solicitante",
                'emach_success_entrada' => "📍Última marcación EMACH\n📅Fecha: {fecha}\n🕒Hora: {hora}\n🟢Tipo: {tipo}\n🕰️Reloj: {reloj}",
                'emach_success_salida' => "📍Última marcación EMACH\n📅Fecha: {fecha}\n🕒Hora: {hora}\n🔴Tipo: {tipo}\n🕰️Reloj: {reloj}",
                'emach_success' => "📍Última marcación EMACH\n📅Fecha: {fecha}\n🕒Hora: {hora}\nTipo: {tipo}\n🕰️Reloj: {reloj}",
                'emach_missing_chat_id' => 'Tu Chat ID no está asociado a un usuario NOVA. Ingresa a NOVA > Mis integraciones y guarda tu TELEGRAM_CHAT_ID.',
                'emach_user_lookup_error' => 'No pude consultar tu usuario NOVA desde el servicio Telegram. Revisa la conexión de Docker con la base de datos.',
                'emach_missing_credentials' => 'No tienes credenciales EMACH guardadas en NOVA.',
                'emach_empty' => 'No encontré marcaciones EMACH para el mes actual.',
                'emach_error' => 'No pude consultar EMACH: {error}',
                'disabled' => 'Comando desactivado.',
                'unknown' => 'No entendí ese comando. Usa /ayuda.',
            ],
        ];
    }

    private function resolveModuleId(): ?int
    {
        static $id = false;
        if ($id !== false) {
            return $id === null ? null : (int) $id;
        }
        try {
            if (! Schema::hasTable('modulos_nova') || ! Schema::hasTable('configuraciones_modulo')) {
                return $id = null;
            }
            $row = DB::table('modulos_nova')->where('clave_modulo', self::MODULE_KEY)->first(['id']);
            $id = $row ? (int) $row->id : null;
        } catch (\Throwable) {
            $id = null;
        }

        return $id === null ? null : (int) $id;
    }

    /** @return array<string,mixed> */
    private function loadFromDb(): array
    {
        $moduleId = $this->resolveModuleId();
        if ($moduleId === null) {
            return [];
        }
        try {
            $rows = DB::table('configuraciones_modulo')
                ->where('modulo_id', $moduleId)
                ->get(['clave', 'valor', 'tipo']);
            if ($rows->isEmpty()) {
                return [];
            }
            $out = [];
            foreach ($rows as $row) {
                $out[(string) $row->clave] = $this->cast((string) ($row->valor ?? ''), (string) ($row->tipo ?? 'string'));
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $settings */
    private function saveToDb(array $settings): bool
    {
        $moduleId = $this->resolveModuleId();
        if ($moduleId === null) {
            return false;
        }
        try {
            foreach ($settings as $key => $value) {
                $key = trim((string) $key);
                if ($key === '') {
                    continue;
                }
                $type = is_bool($value) ? 'bool' : (is_int($value) ? 'int' : (is_array($value) ? 'json' : 'string'));
                $stored = match ($type) {
                    'json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null,
                    'bool' => $value ? '1' : '0',
                    default => (string) $value,
                };
                DB::table('configuraciones_modulo')->updateOrInsert(
                    ['modulo_id' => $moduleId, 'clave' => $key],
                    ['valor' => $stored, 'tipo' => $type, 'actualizado_at' => now()]
                );
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function cast(string $value, string $type): mixed
    {
        return match ($type) {
            'json' => json_decode($value, true) ?? [],
            'bool' => in_array(strtolower($value), ['1', 'true', 'si', 'sí', 'yes'], true),
            'int' => (int) $value,
            default => $value,
        };
    }
}
