<?php

namespace App\Support\Integrations;

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
     * @param array<string,mixed> $payload
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
        return $this->resolveModuleId() !== null
            ? 'configuraciones_modulo:telegram'
            : $this->filesystemPath();
    }

    public function message(string $key): string
    {
        return (string) data_get($this->all(), "messages.{$key}", data_get($this->defaults(), "messages.{$key}", ''));
    }

    public function render(string $key, array $replace = []): string
    {
        $message = $this->message($key);
        foreach ($replace as $name => $value) {
            $message = str_replace('{' . $name . '}', (string) $value, $message);
        }

        return $message;
    }

    /**
     * @return array<string,mixed>
     */
    public function defaults(): array
    {
        return [
            'commands' => [
                'help'   => ['enabled' => true],
                'status' => ['enabled' => true],
                'emach'  => ['enabled' => true],
                'tic'    => ['enabled' => true],
                'test'   => ['enabled' => true],
            ],
            'messages' => [
                'help_header'              => 'Comandos Telegram NOVA:',
                'status'                   => "Servicio Telegram NOVA activo\nFecha: {fecha}",
                'test'                     => 'Mensaje de prueba desde Telegram NOVA: {fecha}',
                'tic_success'              => "Reporte TIC recibido\nAsunto: {asunto}\nCategoria: {categoria}\nUnidad: {unidad}\nEstado: pendiente",
                'tic_unavailable'          => 'No pude cargar Redmine TIC desde el listener Telegram.',
                'tic_error'               => 'No pude crear el reporte TIC: {error}',
                'emach_success'            => "Ultima marcacion EMACH\nFecha: {fecha}\nHora: {hora}\nTipo: {tipo}\nReloj: {reloj}",
                'emach_missing_credentials'=> 'No tienes credenciales EMACH guardadas en NOVA.',
                'emach_empty'              => 'No encontre marcaciones EMACH para el mes actual.',
                'emach_error'              => 'No pude consultar EMACH: {error}',
                'disabled'                 => 'Comando desactivado.',
                'unknown'                  => 'No entendi ese comando. Usa /ayuda.',
            ],
        ];
    }

    // ------------------------------------------------------------------
    // DB persistence (configuraciones_modulo for clave_modulo='telegram')
    // ------------------------------------------------------------------

    private function resolveModuleId(): ?int
    {
        static $id = false;
        if ($id !== false) {
            return $id === null ? null : (int) $id;
        }
        try {
            if (!Schema::hasTable('modulos_nova') || !Schema::hasTable('configuraciones_modulo')) {
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
            return $this->loadFromFileSystem();
        }
        try {
            $rows = DB::table('configuraciones_modulo')
                ->where('modulo_id', $moduleId)
                ->get(['clave', 'valor', 'tipo']);
            if ($rows->isEmpty()) {
                // Seed from filesystem on first load
                $seed = $this->loadFromFileSystem();
                if ($seed !== []) {
                    $this->saveToDb($seed);
                }
                return $seed;
            }
            $out = [];
            foreach ($rows as $row) {
                $out[(string) $row->clave] = $this->cast((string) ($row->valor ?? ''), (string) ($row->tipo ?? 'string'));
            }
            return $out;
        } catch (\Throwable) {
            return $this->loadFromFileSystem();
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
                $type   = is_bool($value) ? 'bool' : (is_int($value) ? 'int' : (is_array($value) ? 'json' : 'string'));
                $stored = match ($type) {
                    'json'  => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null,
                    'bool'  => $value ? '1' : '0',
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

    /** @return array<string,mixed> */
    private function loadFromFileSystem(): array
    {
        $path = $this->filesystemPath();
        $settings = json_decode((string) @file_get_contents($path), true);
        return is_array($settings) ? $settings : [];
    }

    private function filesystemPath(): string
    {
        return storage_path('app/telegram/command_settings.json');
    }

    private function cast(string $value, string $type): mixed
    {
        return match ($type) {
            'json' => json_decode($value, true) ?? [],
            'bool' => in_array(strtolower($value), ['1', 'true', 'si', 'sí', 'yes'], true),
            'int'  => (int) $value,
            default => $value,
        };
    }
}
