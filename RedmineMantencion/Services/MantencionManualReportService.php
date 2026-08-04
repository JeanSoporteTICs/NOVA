<?php

namespace App\Modulos\RedmineMantencion\Services;

use App\Modulos\RedmineMantencion\Repositories\MantencionActivityRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionCatalogRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionConfigRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionReportRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class MantencionManualReportService
{
    public function __construct(
        private readonly MantencionConfigRepository $config,
        private readonly MantencionCatalogRepository $catalogs,
        private readonly MantencionReportRepository $reports,
        private readonly MantencionActivityRepository $activity,
    ) {}

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function formData(array $context): array
    {
        $config = $this->configuration();
        $users = $this->activeUsers();
        $currentUser = $this->currentUser($context, $users);
        $today = Carbon::now('America/Santiago')->toDateString();

        return [
            'config' => $config,
            'users' => $users,
            'categories' => $this->catalogs->categorias(),
            'can_assign_others' => $this->canAssignOthers($context),
            'current_user_id' => (string) ($currentUser['id'] ?? ''),
            'current_user_name' => (string) ($currentUser['nombre'] ?? $context['viewer_name'] ?? ''),
            'defaults' => [
                'project_id' => (string) $config['project_id'],
                'tracker_id' => (string) $config['tracker_id'],
                'status_id' => (string) $config['status_id'],
                'priority_id' => (string) $config['priority_id'],
                'fecha_inicio' => $today,
                'fecha_fin' => $today,
                'asignado_a' => (string) ($currentUser['id'] ?? ''),
                'hora_extra' => '0',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $context
     * @return array{saved:bool,error:?string}
     */
    public function create(array $input, array $context): array
    {
        $formData = $this->formData($context);
        $config = $formData['config'];
        $users = $formData['users'];
        $defaults = $formData['defaults'];
        $subject = trim((string) ($input['asunto'] ?? ''));
        $requester = trim((string) ($input['solicitante'] ?? ''));

        if ($subject === '' || $requester === '') {
            return ['saved' => false, 'error' => 'Asunto y solicitante son obligatorios.'];
        }

        $startDate = $this->date((string) ($input['fecha_inicio'] ?? ''));
        $endDate = $this->date((string) ($input['fecha_fin'] ?? ''));
        if (trim((string) ($input['fecha_inicio'] ?? '')) !== '' && $startDate === '') {
            return ['saved' => false, 'error' => 'La fecha de inicio no es válida.'];
        }
        if (trim((string) ($input['fecha_fin'] ?? '')) !== '' && $endDate === '') {
            return ['saved' => false, 'error' => 'La fecha fin no es válida.'];
        }

        $trackerId = $this->optionId($input['tracker_id'] ?? '', $config['trackers'], $defaults['tracker_id']);
        $statusId = $this->optionId($input['status_id'] ?? '', $config['estados'], $defaults['status_id']);
        $priorityId = $this->optionId($input['priority_id'] ?? '', $config['prioridades'], $defaults['priority_id']);
        $assignedId = $formData['can_assign_others']
            ? $this->userId($input['asignado_a'] ?? '', $users)
            : (string) $formData['current_user_id'];
        if ($assignedId === '') {
            $assignedId = (string) $formData['current_user_id'];
        }

        $assignedName = $this->userName($assignedId, $users)
            ?: (string) $formData['current_user_name'];
        $category = $this->categoryName($input['categoria'] ?? '', $formData['categories']);
        $unit = trim((string) ($input['unidad'] ?? ''));
        $phone = trim((string) ($input['anexo'] ?? ''));
        $email = $this->email((string) ($input['core_email'] ?? ''));
        $estimatedHours = $this->hours((string) ($input['tiempo_estimado'] ?? ''));
        $now = Carbon::now('America/Santiago');
        $startDate = $startDate !== '' ? $startDate : (string) $defaults['fecha_inicio'];
        $endDate = $endDate !== '' ? $endDate : $startDate;
        $trackerName = $this->optionName($config['trackers'], $trackerId) ?: 'Soporte';
        $priorityName = $this->optionName($config['prioridades'], $priorityId) ?: 'Normal';
        $statusName = $this->optionName($config['estados'], $statusId);
        $sourceId = sha1(implode('|', [
            'manual', $subject, $requester, $unit, $phone, $email, $now->toAtomString(), Str::uuid()->toString(),
        ]));

        $record = [
            'id' => 'manual-'.Str::uuid()->toString(),
            'fuente' => 'manual',
            'fuente_id' => $sourceId,
            'numero' => $this->phone($phone),
            'mensaje' => $subject,
            'asunto' => $subject,
            'descripcion' => trim((string) ($input['descripcion'] ?? '')),
            'fecha' => $startDate,
            'hora' => $now->format('H:i'),
            'fecha_inicio' => $startDate,
            'fecha_fin' => $endDate,
            'tipo' => $trackerName,
            'tipo_id' => $trackerId,
            'prioridad' => $priorityName,
            'priority_id' => $priorityId,
            'status_id' => $statusId,
            'project_id' => (string) $config['project_id'],
            'proyecto' => (string) $config['project_name'],
            'project_name' => (string) $config['project_name'],
            'estado' => 'pendiente',
            'estado_redmine' => $statusName,
            'hora_extra' => (string) ($input['hora_extra'] ?? '0') === '1' ? '1' : '0',
            'tiempo_estimado' => $estimatedHours,
            'categoria' => $category,
            'unidad' => $unit,
            'unidad_solicitante' => $unit,
            'solicitante' => $requester,
            'asignado_a' => $assignedId,
            'asignado_nombre' => $assignedName,
            'anexo' => $phone,
            'redmine_id' => '',
            'procesado_ts' => '',
            'core_fecha_creacion' => Carbon::parse($startDate, 'America/Santiago')->format('d-m-Y').' '.$now->format('H:i'),
            'core_tipo_solicitud' => $category !== '' ? $category : $trackerName,
            'core_establecimiento' => $unit,
            'core_departamento' => $unit,
            'core_estado' => 'Manual',
            'core_usuario_asignado' => $assignedName,
            'core_email' => $email,
            'core_telefono' => $phone,
            'core_celular' => '',
        ];

        if (! $this->reports->upsertMessage($record, $config)) {
            return ['saved' => false, 'error' => 'No fue posible guardar el pendiente. Intenta nuevamente o revisa el registro del sistema.'];
        }

        $this->activity->record(
            'REPORT_CREATE',
            'Pendiente manual creado: '.$subject,
            (string) ($context['viewer_name'] ?? $assignedName),
            (string) ($context['viewer_id'] ?? $assignedId),
        );

        return ['saved' => true, 'error' => null];
    }

    /** @return array<string,mixed> */
    private function configuration(): array
    {
        $config = $this->config->loadAll() ?? [];

        return array_replace($config, [
            'project_id' => $config['project_id'] ?? 48,
            'project_name' => trim((string) ($config['project_name'] ?? 'Backlog Mantención TI')),
            'tracker_id' => $config['tracker_id'] ?? 3,
            'priority_id' => $config['priority_id'] ?? 2,
            'status_id' => $config['status_id'] ?? 1,
            'trackers' => is_array($config['trackers'] ?? null) ? $config['trackers'] : [],
            'prioridades' => is_array($config['prioridades'] ?? null) ? $config['prioridades'] : [],
            'estados' => is_array($config['estados'] ?? null) ? $config['estados'] : [],
        ]);
    }

    /** @return array<int,array{id:string,nombre:string,nova_id:int}> */
    public function activeUsers(): array
    {
        try {
            if (! Schema::hasTable('usuarios_nova') || ! Schema::hasTable('modulos_nova')) {
                return [];
            }

            $moduleId = DB::table('modulos_nova')->where('clave_modulo', 'redmine-mantencion')->value('id');
            $query = DB::table('usuarios_nova as u');
            if ($moduleId !== null && Schema::hasTable('permisos_usuario_modulo')) {
                $query->leftJoin('permisos_usuario_modulo as p', function ($join) use ($moduleId): void {
                    $join->on('p.usuario_id', '=', 'u.id')->where('p.modulo_id', (int) $moduleId);
                })->where(function ($where): void {
                    $where->where('p.permitido', 1)->orWhereIn('u.rol', ['admin', 'administrador', 'root']);
                });
            } else {
                $query->whereIn('u.rol', ['admin', 'administrador', 'root']);
            }

            return $query
                ->where(function ($where): void {
                    $where->whereNull('u.estado')->orWhereNotIn('u.estado', ['baneado', 'bloqueado', 'inactivo']);
                })
                ->orderBy('u.nombre')
                ->orderBy('u.apellido')
                ->get(['u.id as nova_id', 'u.uuid', 'u.usuario', 'u.redmine_id', 'u.nombre', 'u.apellido'])
                ->map(function (object $row): array {
                    $id = trim((string) ($row->redmine_id ?? ''))
                        ?: trim((string) ($row->usuario ?? $row->uuid ?? ''));
                    $name = trim((string) (($row->nombre ?? '').' '.($row->apellido ?? '')));

                    return ['id' => $id, 'nombre' => $name, 'nova_id' => (int) $row->nova_id];
                })
                ->filter(fn (array $user): bool => $user['id'] !== '' && $user['nombre'] !== '')
                ->unique('nova_id')
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<int,array{id:string,nombre:string,nova_id:int}>  $users
     * @return array{id:string,nombre:string,nova_id:int}|array{}
     */
    private function currentUser(array $context, array $users): array
    {
        $centralId = (int) ($context['central_user_id'] ?? 0);
        $viewerId = trim((string) ($context['viewer_id'] ?? ''));

        foreach ($users as $user) {
            if (($centralId > 0 && $user['nova_id'] === $centralId) || ($viewerId !== '' && $user['id'] === $viewerId)) {
                return $user;
            }
        }

        return [];
    }

    /** @param array<string,mixed> $context */
    private function canAssignOthers(array $context): bool
    {
        $permissions = is_array($context['permissions'] ?? null) ? $context['permissions'] : [];

        return ! empty($permissions['all'])
            || strtolower(trim((string) ($permissions['mensajes'] ?? ''))) === 'todos';
    }

    /** @param array<int,array<string,mixed>> $options */
    private function optionId(mixed $value, array $options, mixed $fallback): string
    {
        foreach ([trim((string) $value), trim((string) $fallback)] as $candidate) {
            foreach ($options as $option) {
                if ($candidate !== '' && (string) ($option['id'] ?? '') === $candidate) {
                    return $candidate;
                }
            }
        }

        return trim((string) ($options[0]['id'] ?? ''));
    }

    /** @param array<int,array<string,mixed>> $options */
    private function optionName(array $options, string $id): string
    {
        foreach ($options as $option) {
            if ((string) ($option['id'] ?? '') === $id) {
                return trim((string) ($option['nombre'] ?? ''));
            }
        }

        return '';
    }

    /** @param array<int,array{id:string,nombre:string,nova_id:int}> $users */
    private function userId(mixed $value, array $users): string
    {
        $candidate = trim((string) $value);
        foreach ($users as $user) {
            if ($candidate !== '' && $user['id'] === $candidate) {
                return $candidate;
            }
        }

        return '';
    }

    /** @param array<int,array{id:string,nombre:string,nova_id:int}> $users */
    private function userName(string $id, array $users): string
    {
        foreach ($users as $user) {
            if ($user['id'] === $id) {
                return $user['nombre'];
            }
        }

        return '';
    }

    /** @param array<int,array<string,mixed>> $categories */
    private function categoryName(mixed $value, array $categories): string
    {
        $candidate = trim((string) $value);
        foreach ($categories as $category) {
            $name = trim((string) ($category['nombre'] ?? ''));
            if ($name !== '' && strcasecmp($name, $candidate) === 0) {
                return $name;
            }
        }

        return '';
    }

    private function date(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        foreach (['Y-m-d', 'd-m-Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value, 'America/Santiago');
                if ($date->format($format) === $value) {
                    return $date->toDateString();
                }
            } catch (\Throwable) {
            }
        }

        return '';
    }

    private function hours(string $value): string
    {
        $value = str_replace(',', '.', trim($value));
        if ($value === '' || ! is_numeric($value) || (float) $value < 0) {
            return '';
        }

        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    private function email(string $value): string
    {
        $value = trim($value);

        return $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
    }

    private function phone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?: '';
        if ($digits === '') {
            return '';
        }

        return str_starts_with($digits, '56') ? '+'.$digits : (strlen($digits) === 9 ? '+56'.$digits : '+'.$digits);
    }
}
