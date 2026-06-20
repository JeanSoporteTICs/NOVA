<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createCatalogTables();
        $this->createReportTables();
        $this->migrateCatalogs();
        $this->migrateMantencionReports();
        $this->migrateMantencionOvertime();
    }

    public function down(): void
    {
        Schema::dropIfExists('horas_extras');
        Schema::dropIfExists('redmine_mantencion_reportes');
        Schema::dropIfExists('unidades');
        Schema::dropIfExists('categorias');
    }

    private function createCatalogTables(): void
    {
        if (!Schema::hasTable('categorias')) {
            Schema::create('categorias', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('modulo_id')->nullable()->constrained('modulos_nova')->nullOnDelete();
                $table->string('nombre', 255);
                $table->string('clave_externa', 120)->nullable();
                $table->string('origen', 40)->default('normalizado')->index();
                $table->boolean('activo')->default(true)->index();
                $table->json('datos_extra')->nullable();
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
                $table->unique(['modulo_id', 'nombre', 'origen'], 'uq_categorias_modulo_nombre_origen');
            });
        }

        if (!Schema::hasTable('unidades')) {
            Schema::create('unidades', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('modulo_id')->nullable()->constrained('modulos_nova')->nullOnDelete();
                $table->string('nombre', 255);
                $table->string('clave_externa', 120)->nullable();
                $table->string('origen', 40)->default('normalizado')->index();
                $table->boolean('activo')->default(true)->index();
                $table->json('datos_extra')->nullable();
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
                $table->unique(['modulo_id', 'nombre', 'origen'], 'uq_unidades_modulo_nombre_origen');
            });
        }
    }

    private function createReportTables(): void
    {
        if (!Schema::hasTable('redmine_mantencion_reportes')) {
            Schema::create('redmine_mantencion_reportes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('modulo_id')->nullable()->constrained('modulos_nova')->nullOnDelete();
                $table->string('local_id', 120)->nullable()->unique();
                $table->string('id_core', 160)->nullable()->index();
                $table->string('proyecto', 180)->nullable();
                $table->string('project_id', 80)->nullable()->index();
                $table->string('tipo', 120)->nullable();
                $table->string('tipo_id', 80)->nullable();
                $table->text('asunto')->nullable();
                $table->longText('descripcion')->nullable();
                $table->string('estado', 80)->nullable()->index();
                $table->string('estado_redmine', 120)->nullable();
                $table->string('prioridad', 80)->nullable();
                $table->string('priority_id', 80)->nullable();
                $table->string('id_redmine_asignado', 80)->nullable()->index();
                $table->string('asignado_nombre', 180)->nullable();
                $table->foreignId('categoria_id')->nullable()->constrained('categorias')->nullOnDelete();
                $table->string('solicitante', 255)->nullable();
                $table->string('anexo', 120)->nullable();
                $table->foreignId('unidad_id')->nullable()->constrained('unidades')->nullOnDelete();
                $table->string('unidad_nombre', 255)->nullable();
                $table->date('fecha_inicio')->nullable()->index();
                $table->date('fecha_fin')->nullable();
                $table->date('fecha_reporte')->nullable();
                $table->time('hora_reporte')->nullable();
                $table->decimal('tiempo_estimado', 10, 2)->nullable();
                $table->string('correo', 255)->nullable();
                $table->boolean('hora_extra')->default(false)->index();
                $table->unsignedInteger('numero_ticket_redmine')->nullable()->index();
                $table->string('source_path', 255)->nullable()->index();
                $table->json('datos_extra')->nullable();
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
            });
        }

        if (!Schema::hasTable('horas_extras')) {
            Schema::create('horas_extras', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('modulo_id')->nullable()->constrained('modulos_nova')->nullOnDelete();
                $table->string('proyecto', 180)->nullable();
                $table->string('project_id', 80)->nullable()->index();
                $table->foreignId('usuario_id')->nullable()->constrained('usuarios_nova')->nullOnDelete();
                $table->string('id_redmine_asignado', 80)->nullable()->index();
                $table->unsignedInteger('numero_ticket_redmine')->nullable()->index();
                $table->string('reporte_local_id', 120)->nullable()->index();
                $table->date('fecha')->nullable()->index();
                $table->time('hora_inicio')->nullable();
                $table->time('hora_termino')->nullable();
                $table->decimal('cantidad', 10, 2)->nullable();
                $table->string('source_path', 255)->nullable()->index();
                $table->char('origen_hash', 64)->unique();
                $table->json('datos_extra')->nullable();
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
            });
        }
    }

    private function migrateCatalogs(): void
    {
        if (Schema::hasTable('catalogos_modulo')) {
            foreach (DB::table('catalogos_modulo')->orderBy('id')->get() as $row) {
                $type = (string) ($row->tipo ?? '');
                if ($type === 'categoria') {
                    $this->upsertCategory((int) $row->modulo_id, (string) $row->nombre, [
                        'clave_externa' => $row->clave_externa,
                        'origen' => 'catalogos_modulo',
                        'activo' => (int) $row->activo === 1,
                        'datos_extra' => ['catalogo_id' => $row->id, 'predeterminado' => (bool) $row->predeterminado],
                    ]);
                }

                if ($type === 'unidad') {
                    $this->upsertUnit((int) $row->modulo_id, (string) $row->nombre, [
                        'clave_externa' => $row->clave_externa,
                        'origen' => 'catalogos_modulo',
                        'activo' => (int) $row->activo === 1,
                        'datos_extra' => ['catalogo_id' => $row->id, 'predeterminado' => (bool) $row->predeterminado],
                    ]);
                }
            }
        }

        $mantencionModuleId = $this->moduleId('redmine-mantencion');
        foreach ($this->storageJson('categorias.json') as $item) {
            if (is_array($item)) {
                $this->upsertCategory($mantencionModuleId, $this->itemName($item), [
                    'clave_externa' => $this->value($item, ['id', 'value', 'clave', 'clave_externa']),
                    'origen' => 'redmine_mantencion_storage',
                    'datos_extra' => $item,
                ]);
            }
        }

        foreach ($this->storageJson('unidades.json') as $item) {
            if (is_array($item)) {
                $this->upsertUnit($mantencionModuleId, $this->itemName($item), [
                    'clave_externa' => $this->value($item, ['id', 'value', 'clave', 'clave_externa']),
                    'origen' => 'redmine_mantencion_storage',
                    'datos_extra' => $item,
                ]);
            }
        }
    }

    private function migrateMantencionReports(): void
    {
        if (!Schema::hasTable('redmine_mantencion_storage')) {
            return;
        }

        $moduleId = $this->moduleId('redmine-mantencion');
        $rows = DB::table('redmine_mantencion_storage')
            ->where('content_type', 'json')
            ->where('path', 'like', 'reportes/%')
            ->orderBy('path')
            ->get(['path', 'payload_json']);

        foreach ($rows as $row) {
            $reports = $this->decodeJson((string) $row->payload_json);
            if (!is_array($reports)) {
                continue;
            }

            foreach ($reports as $report) {
                if (!is_array($report)) {
                    continue;
                }

                $localId = $this->value($report, ['id', 'local_id']);
                if ($localId === '') {
                    $localId = hash('sha256', (string) $row->path . json_encode($report));
                }

                DB::table('redmine_mantencion_reportes')->updateOrInsert(
                    ['local_id' => $localId],
                    $this->mantencionReportValues($moduleId, (string) $row->path, $report, $localId)
                );
            }
        }
    }

    private function migrateMantencionOvertime(): void
    {
        if (!Schema::hasTable('redmine_mantencion_storage')) {
            return;
        }

        $moduleId = $this->moduleId('redmine-mantencion');
        $rows = DB::table('redmine_mantencion_storage')
            ->where('content_type', 'json')
            ->where('path', 'like', 'horasExtras/%')
            ->orderBy('path')
            ->get(['path', 'payload_json']);

        foreach ($rows as $row) {
            $groups = $this->decodeJson((string) $row->payload_json);
            if (!is_array($groups)) {
                continue;
            }

            foreach ($groups as $groupIndex => $group) {
                if (!is_array($group)) {
                    continue;
                }

                $reports = $group['reports'] ?? $this->numericReports($group);
                if (!is_array($reports) || $reports === []) {
                    continue;
                }

                foreach ($reports as $reportIndex => $report) {
                    if (!is_array($report)) {
                        continue;
                    }

                    $localId = $this->value($report, ['id', 'local_id']);
                    $hash = hash('sha256', (string) $row->path . '|' . $groupIndex . '|' . $reportIndex . '|' . $localId);
                    $redmineId = $this->integerOrNull($this->value($report, ['redmine_id', 'numero_ticket_redmine']));
                    $assignedRedmineId = $this->value($report, ['asignado_a', 'id_redmine_asignado']);

                    DB::table('horas_extras')->updateOrInsert(
                        ['origen_hash' => $hash],
                        [
                            'modulo_id' => $moduleId,
                            'proyecto' => $this->value($report, ['project_name', 'proyecto']),
                            'project_id' => $this->value($report, ['project_id']),
                            'usuario_id' => $this->novaUserIdByRedmine($assignedRedmineId),
                            'id_redmine_asignado' => $assignedRedmineId ?: null,
                            'numero_ticket_redmine' => $redmineId,
                            'reporte_local_id' => $localId ?: null,
                            'fecha' => $this->parseDate($this->value($group, ['fecha']) ?: $this->value($report, ['fecha_inicio', 'fecha'])),
                            'hora_inicio' => $this->parseTime($this->value($group, ['hora_inicio'])),
                            'hora_termino' => $this->parseTime($this->value($group, ['hora_fin', 'hora_termino'])),
                            'cantidad' => $this->decimalOrNull($this->value($report, ['tiempo_estimado', 'cantidad'])),
                            'source_path' => (string) $row->path,
                            'datos_extra' => json_encode(['group' => $group, 'report' => $report], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'actualizado_at' => now(),
                            'creado_at' => now(),
                        ]
                    );
                }
            }
        }
    }

    private function mantencionReportValues(int $moduleId, string $sourcePath, array $report, string $localId): array
    {
        $categoryName = $this->value($report, ['categoria']);
        $unitName = $this->value($report, ['unidad', 'unidad_solicitante', 'core_departamento', 'core_establecimiento']);
        $redmineId = $this->integerOrNull($this->value($report, ['redmine_id', 'numero_ticket_redmine']));

        return [
            'modulo_id' => $moduleId,
            'local_id' => $localId,
            'id_core' => $this->coreId($report),
            'proyecto' => $this->value($report, ['project_name', 'proyecto']),
            'project_id' => $this->value($report, ['project_id']),
            'tipo' => $this->value($report, ['tipo', 'core_tipo_solicitud']),
            'tipo_id' => $this->value($report, ['tipo_id', 'tracker_id']),
            'asunto' => $this->value($report, ['asunto', 'mensaje']),
            'descripcion' => $this->value($report, ['descripcion']),
            'estado' => $this->value($report, ['estado']),
            'estado_redmine' => $this->value($report, ['estado_redmine']),
            'prioridad' => $this->value($report, ['prioridad']),
            'priority_id' => $this->value($report, ['priority_id']),
            'id_redmine_asignado' => $this->value($report, ['asignado_a', 'id_redmine_asignado']) ?: null,
            'asignado_nombre' => $this->value($report, ['asignado_nombre', 'core_usuario_asignado']),
            'categoria_id' => $categoryName !== '' ? $this->categoryId($moduleId, $categoryName) : null,
            'solicitante' => $this->value($report, ['solicitante']),
            'anexo' => $this->value($report, ['anexo', 'core_telefono', 'core_celular', 'numero']),
            'unidad_id' => $unitName !== '' ? $this->unitId($moduleId, $unitName) : null,
            'unidad_nombre' => $unitName ?: null,
            'fecha_inicio' => $this->parseDate($this->value($report, ['fecha_inicio'])),
            'fecha_fin' => $this->parseDate($this->value($report, ['fecha_fin'])),
            'fecha_reporte' => $this->parseDate($this->value($report, ['fecha', 'core_fecha_creacion'])),
            'hora_reporte' => $this->parseTime($this->value($report, ['hora'])),
            'tiempo_estimado' => $this->decimalOrNull($this->value($report, ['tiempo_estimado'])),
            'correo' => $this->value($report, ['correo', 'core_email']) ?: null,
            'hora_extra' => $this->truthy($this->value($report, ['hora_extra'])),
            'numero_ticket_redmine' => $redmineId,
            'source_path' => $sourcePath,
            'datos_extra' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'actualizado_at' => now(),
            'creado_at' => now(),
        ];
    }

    private function upsertCategory(?int $moduleId, string $name, array $values): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        DB::table('categorias')->updateOrInsert(
            ['modulo_id' => $moduleId, 'nombre' => $name, 'origen' => $values['origen'] ?? 'normalizado'],
            [
                'clave_externa' => $values['clave_externa'] ?? null,
                'activo' => $values['activo'] ?? true,
                'datos_extra' => json_encode($values['datos_extra'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'actualizado_at' => now(),
                'creado_at' => now(),
            ]
        );
    }

    private function upsertUnit(?int $moduleId, string $name, array $values): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        DB::table('unidades')->updateOrInsert(
            ['modulo_id' => $moduleId, 'nombre' => $name, 'origen' => $values['origen'] ?? 'normalizado'],
            [
                'clave_externa' => $values['clave_externa'] ?? null,
                'activo' => $values['activo'] ?? true,
                'datos_extra' => json_encode($values['datos_extra'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'actualizado_at' => now(),
                'creado_at' => now(),
            ]
        );
    }

    private function categoryId(int $moduleId, string $name): ?int
    {
        $this->upsertCategory($moduleId, $name, ['origen' => 'reportes_mantencion']);

        return DB::table('categorias')
            ->where('modulo_id', $moduleId)
            ->where('nombre', trim($name))
            ->orderByRaw("FIELD(origen, 'reportes_mantencion', 'redmine_mantencion_storage', 'catalogos_modulo') DESC")
            ->value('id');
    }

    private function unitId(int $moduleId, string $name): ?int
    {
        $this->upsertUnit($moduleId, $name, ['origen' => 'reportes_mantencion']);

        return DB::table('unidades')
            ->where('modulo_id', $moduleId)
            ->where('nombre', trim($name))
            ->orderByRaw("FIELD(origen, 'reportes_mantencion', 'redmine_mantencion_storage', 'catalogos_modulo') DESC")
            ->value('id');
    }

    private function moduleId(string $key): ?int
    {
        return DB::table('modulos_nova')->where('clave_modulo', $key)->value('id');
    }

    private function storageJson(string $path): array
    {
        if (!Schema::hasTable('redmine_mantencion_storage')) {
            return [];
        }

        $payload = DB::table('redmine_mantencion_storage')
            ->where('path', $path)
            ->where('content_type', 'json')
            ->value('payload_json');

        return $this->decodeJson((string) $payload);
    }

    private function decodeJson(string $payload): array
    {
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function itemName(array $item): string
    {
        return $this->value($item, ['nombre', 'name', 'label', 'text', 'value']);
    }

    private function value(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && !is_array($row[$key])) {
                return trim((string) $row[$key]);
            }
        }

        return '';
    }

    private function coreId(array $report): ?string
    {
        if ($this->value($report, ['fuente']) === 'core') {
            return $this->value($report, ['fuente_id']) ?: null;
        }

        return $this->value($report, ['id_core', 'core_id']) ?: null;
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y-m-d H:i', 'd-m-Y H:i', 'd/m/Y H:i'] as $format) {
            try {
                return Carbon::createFromFormat($format, substr($value, 0, strlen($format)))->toDateString();
            } catch (Throwable) {
                continue;
            }
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function parseTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['H:i:s', 'H:i'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->format('H:i:s');
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private function decimalOrNull(string $value): ?float
    {
        $value = str_replace(',', '.', trim($value));

        return is_numeric($value) ? (float) $value : null;
    }

    private function integerOrNull(string $value): ?int
    {
        return ctype_digit(trim($value)) ? (int) $value : null;
    }

    private function truthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'si', 'sí', 's', 'true', 'yes'], true);
    }

    private function numericReports(array $group): array
    {
        $reports = [];
        foreach ($group as $key => $value) {
            if (is_numeric($key) && is_array($value)) {
                $reports[] = $value;
            }
        }

        return $reports;
    }

    private function novaUserIdByRedmine(string $redmineId): ?int
    {
        if (trim($redmineId) === '') {
            return null;
        }

        return DB::table('usuarios_nova')->where('redmine_id', trim($redmineId))->value('id');
    }
};
