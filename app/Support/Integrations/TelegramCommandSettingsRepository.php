<?php

namespace App\Support\Integrations;

class TelegramCommandSettingsRepository
{
    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        $settings = json_decode((string) @file_get_contents($this->path()), true);
        $settings = is_array($settings) ? $settings : [];

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

        $directory = dirname($this->path());
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        $written = @file_put_contents($this->path(), json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
        if ($written !== false) {
            @chmod($this->path(), 0666);
        }

        return $written !== false;
    }

    public function commandEnabled(string $key): bool
    {
        return (bool) data_get($this->all(), "commands.{$key}.enabled", true);
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
                'help' => ['enabled' => true],
                'status' => ['enabled' => true],
                'emach' => ['enabled' => true],
                'tic' => ['enabled' => true],
                'test' => ['enabled' => true],
            ],
            'messages' => [
                'help_header' => 'Comandos Telegram NOVA:',
                'status' => "Servicio Telegram NOVA activo\nFecha: {fecha}",
                'test' => 'Mensaje de prueba desde Telegram NOVA: {fecha}',
                'tic_success' => "Reporte TIC recibido\nAsunto: {asunto}\nCategoria: {categoria}\nUnidad: {unidad}\nEstado: pendiente",
                'tic_unavailable' => 'No pude cargar Redmine TIC desde el listener Telegram.',
                'tic_error' => 'No pude crear el reporte TIC: {error}',
                'emach_success' => "Ultima marcacion EMACH\nFecha: {fecha}\nHora: {hora}\nTipo: {tipo}\nReloj: {reloj}",
                'emach_missing_credentials' => 'No tienes credenciales EMACH guardadas en NOVA.',
                'emach_empty' => 'No encontre marcaciones EMACH para el mes actual.',
                'emach_error' => 'No pude consultar EMACH: {error}',
                'disabled' => 'Comando desactivado.',
                'unknown' => 'No entendi ese comando. Usa /ayuda.',
            ],
        ];
    }

    public function path(): string
    {
        return storage_path('app/telegram/command_settings.json');
    }
}
