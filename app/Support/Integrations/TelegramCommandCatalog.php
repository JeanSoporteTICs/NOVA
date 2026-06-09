<?php

namespace App\Support\Integrations;

class TelegramCommandCatalog
{
    public function __construct(private readonly TelegramCommandSettingsRepository $settings)
    {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function commands(): array
    {
        $commands = [
            [
                'key' => 'help',
                'command' => '/ayuda',
                'aliases' => ['/start', '/help'],
                'module' => 'NOVA',
                'description' => 'Muestra los comandos disponibles para el usuario.',
                'input' => 'Sin parametros.',
                'response' => '',
                'enabled' => $this->settings->commandEnabled('help'),
            ],
            [
                'key' => 'status',
                'command' => '/estado',
                'aliases' => ['/status'],
                'module' => 'Telegram',
                'description' => 'Confirma que el listener Telegram esta activo.',
                'input' => 'Sin parametros.',
                'response' => $this->settings->render('status', ['fecha' => 'dd/mm/aaaa hh:mm:ss']),
                'enabled' => $this->settings->commandEnabled('status'),
            ],
            [
                'key' => 'emach',
                'command' => '/emach',
                'aliases' => [],
                'module' => 'EMACH',
                'description' => 'Consulta la ultima marcacion EMACH del usuario asociado al Chat ID.',
                'input' => 'Sin parametros. Requiere credenciales EMACH guardadas.',
                'response' => $this->settings->render('emach_success', [
                    'fecha' => 'dd/mm/aaaa',
                    'hora' => 'hh:mm',
                    'tipo' => 'entrada/salida',
                    'reloj' => 'reloj',
                ]),
                'enabled' => $this->settings->commandEnabled('emach'),
            ],
            [
                'key' => 'tic',
                'command' => '/tic',
                'aliases' => ['/reporte'],
                'module' => 'Redmine TICS',
                'description' => 'Crea un reporte TIC pendiente desde un mensaje separado por comas.',
                'input' => '/tic problema, unidad, solicitante',
                'response' => $this->settings->render('tic_success', [
                    'asunto' => 'problema',
                    'categoria' => 'categoria detectada',
                    'unidad' => 'unidad',
                ]),
                'enabled' => $this->settings->commandEnabled('tic'),
            ],
            [
                'key' => 'test',
                'command' => '/test',
                'aliases' => [],
                'module' => 'Telegram',
                'description' => 'Devuelve una respuesta simple para probar ida y vuelta del bot.',
                'input' => 'Sin parametros.',
                'response' => $this->settings->render('test', ['fecha' => 'dd/mm/aaaa hh:mm:ss']),
                'enabled' => $this->settings->commandEnabled('test'),
            ],
        ];

        $commands[0]['response'] = $this->helpText($commands);

        return $commands;
    }

    /**
     * @param array<int,array<string,mixed>>|null $commands
     */
    public function helpText(?array $commands = null): string
    {
        $lines = [$this->settings->message('help_header')];
        foreach ($commands ?? $this->commands() as $command) {
            if (!($command['enabled'] ?? true)) {
                continue;
            }
            $input = (string) ($command['input'] ?? '');
            $lines[] = trim((string) ($command['command'] ?? '') . ($input !== '' && !str_starts_with($input, 'Sin parametros') ? ' ' . preg_replace('/^' . preg_quote((string) ($command['command'] ?? ''), '/') . '\s*/', '', $input) : ''));
        }

        return implode("\n", array_filter($lines));
    }

    public function settings(): TelegramCommandSettingsRepository
    {
        return $this->settings;
    }
}
