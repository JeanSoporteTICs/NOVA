@php
    $categoryOptions = collect($categories ?? [])
        ->map(fn ($row) => trim((string) ($row['nombre'] ?? $row['id'] ?? '')))
        ->filter()->unique()->values();
    $unitOptions = collect($units ?? [])
        ->map(fn ($row) => trim((string) ($row['nombre'] ?? $row['id'] ?? '')))
        ->filter()->unique()->values();
    // RedmineDataRepository::users() solo devuelve usuarios con acceso
    // permitido al modulo TIC. Aqui se exige, ademas, que tanto la cuenta
    // NOVA como el perfil TIC esten activos y que exista un Redmine ID real.
    $activeUsers = collect($users ?? [])->filter(function ($user) {
        $projectState = strtolower(trim((string) ($user['estado_usuario'] ?? $user['estado'] ?? 'activo')));
        $novaState = strtolower(trim((string) ($user['estado_nova'] ?? 'activo')));
        $redmineId = trim((string) ($user['redmine_id'] ?? ''));

        return $projectState === 'activo' && $novaState === 'activo' && ctype_digit($redmineId);
    })->values();
    $assigneeOptions = $activeUsers->map(function ($user) {
        $userId = trim((string) ($user['redmine_id'] ?? ''));
        $displayName = trim((string) (($user['nombre'] ?? '') . ' ' . ($user['apellido'] ?? '')));
        if ($displayName === '') {
            $displayName = trim((string) ($user['nombre_completo'] ?? $user['usuario'] ?? $user['username'] ?? $userId));
        }

        return [
            'label' => $displayName,
            'value' => $userId,
            'chat_id' => trim((string) ($user['telegram_chat_id'] ?? data_get($user, 'telegram_settings.chat_id', ''))),
        ];
    })->filter()->unique('value')->values();
    $draft = session('redmine_quick_preview', []);
    if ((!is_array($draft) || $draft === []) && old('quick_action') === 'send') {
        $draft = session()->getOldInput();
    }
    $draft = is_array($draft) ? $draft : [];
    $hasPreview = $draft !== [];
    $field = static fn (string $name, string $default = ''): string => (string) old($name, $draft[$name] ?? $default);
    $quickResult = session('redmine_quick_result', []);
@endphp

<section class="tic-quick-report" data-tic-quick-report @if ($hasPreview) data-quick-preview-ready="1" @endif>
    @if (is_array($quickResult) && $quickResult !== [])
        <article class="tic-quick-result" role="status">
            <span class="tic-quick-result-icon"><i class="bi bi-check2-circle"></i></span>
            <div>
                <small>Despacho completado</small>
                <h2>Reporte Redmine #{{ $quickResult['redmine_id'] ?? '' }}</h2>
                <p>
                    Responsable: {{ $quickResult['responsable'] ?? 'Sin identificar' }}.
                    @if (!empty($quickResult['telegram_sent']))
                        La notificación Telegram fue enviada.
                    @elseif (empty($quickResult['telegram_configured']))
                        El responsable no tiene Chat ID configurado.
                    @else
                        La notificación Telegram no pudo enviarse.
                    @endif
                </p>
            </div>
            @if (!empty($quickResult['url']))
                <a class="btn-nova btn-nova-primary" href="{{ $quickResult['url'] }}" target="_blank" rel="noopener noreferrer">
                    <i class="bi bi-box-arrow-up-right"></i> Abrir reporte
                </a>
            @endif
        </article>
    @endif

    @if ($errors->any())
        <div class="nova-alert-card is-danger mb-3" role="alert">
            <i class="bi bi-exclamation-octagon"></i>
            <div>
                <strong>Revisa los datos del reporte.</strong>
                <ul class="mb-0 mt-1">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        </div>
    @endif

    <article class="tic-quick-intake">
        <form method="POST" action="{{ route('redmine.native.quick-report.action') }}" class="tic-quick-intake-form">
            @csrf
            <input type="hidden" name="quick_action" value="preview">
            <div class="tic-quick-intake-grid">
                <div class="tic-quick-intake-fields">
                    <div class="tic-quick-command">
                        <span class="tic-quick-command-mark" aria-hidden="true"><i class="bi bi-terminal"></i></span>
                        <div class="flex-grow-1">
                            <label for="tic-quick-input">Problema, unidad, solicitante</label>
                            <input id="tic-quick-input" class="form-control" name="quick_input" maxlength="700" required
                                   value="{{ old('quick_input') }}" placeholder="Ej: Impresora no imprime, SOME HBV, Ana Pérez">
                        </div>
                    </div>
                    <div>
                        <label class="form-label" for="tic-quick-assignee">Responsable</label>
                        <select class="form-select tic-webhook-select2" id="tic-quick-assignee" name="asignado_a" required
                                data-tic-webhook-select2 data-placeholder="Seleccionar responsable">
                            <option value=""></option>
                            @foreach ($assigneeOptions as $option)
                                <option value="{{ $option['value'] }}" data-chat-id="{{ $option['chat_id'] }}"
                                        @selected((string) old('asignado_a', $draft['asignado_a'] ?? '') === (string) $option['value'])>
                                    {{ $option['label'] }}{{ $option['chat_id'] === '' ? ' · sin Telegram' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="tic-quick-intake-actions">
                        <button class="btn-nova btn-nova-primary" type="submit">
                            <i class="bi bi-magic"></i> Generar vista previa
                        </button>
                    </div>
                </div>
                <div class="tic-quick-intake-description">
                    <div class="tic-quick-notes-head">
                        <label class="form-label" for="tic-quick-description">Notas de llamadas / descripción</label>
                        <small class="tic-quick-notes-status" data-quick-notes-status aria-live="polite">
                            <i class="bi bi-cloud-check" aria-hidden="true"></i>
                            <span>Guardado en esta sesión</span>
                        </small>
                    </div>
                    <textarea class="form-control" id="tic-quick-description" name="quick_description" rows="5" maxlength="4000"
                              data-quick-notes data-notes-save-url="{{ route('redmine.native.quick-report.notes') }}"
                              placeholder="Anota aquí los llamados, problemas, equipos afectados y observaciones.">{{ old('quick_description', session('redmine_tic.quick_report_notes', '')) }}</textarea>
                </div>
            </div>
        </form>
    </article>

    @if ($hasPreview)
        <div class="tic-quick-draft-launch" role="status">
            <div class="tic-quick-draft-launch-copy">
                <span class="tic-quick-draft-launch-icon"><i class="bi bi-pencil-square"></i></span>
                <div>
                    <strong>Borrador listo para revisar</strong>
                    <span>Abre el panel para modificar los datos y enviarlos a Redmine.</span>
                </div>
            </div>
            <button class="btn-nova btn-nova-primary" type="button"
                    data-nova-drawer-open="tic-quick-preview-drawer" aria-controls="tic-quick-preview-drawer">
                <i class="bi bi-layout-sidebar-inset-reverse"></i> Abrir revisión
            </button>
        </div>

        <div class="nova-drawer tic-quick-preview-drawer" id="tic-quick-preview-drawer" role="dialog"
             aria-labelledby="tic-quick-preview-drawer-title" aria-hidden="true" data-quick-preview-drawer>
            <div class="nova-drawer-dialog">
                <div class="nova-drawer-content">
                    <form method="POST" action="{{ route('redmine.native.quick-report.action') }}" class="tic-quick-drawer-form" data-tic-quick-form>
                        @csrf
                        <input type="hidden" name="quick_action" value="send">
                        <input type="hidden" name="quick_input" value="{{ old('quick_input', $draft['mensaje'] ?? '') }}">
                        <input type="hidden" name="mensaje" value="{{ $field('mensaje') }}">
                        <input type="hidden" name="chat_id_telegram" value="">

                        <header class="nova-drawer-header tic-quick-drawer-header">
                            <div class="d-flex align-items-center gap-3">
                                <span class="detail-drawer-icon"><i class="bi bi-pencil-square"></i></span>
                                <div>
                                    <p class="detail-drawer-kicker">2 · Revisión</p>
                                    <h2 id="tic-quick-preview-drawer-title">Modifica lo necesario</h2>
                                </div>
                            </div>
                            <div class="tic-quick-drawer-controls" aria-label="Controles de la revisión">
                                <button type="button" data-quick-drawer-minimize title="Minimizar" aria-label="Minimizar revisión">
                                    <i class="bi bi-dash-lg"></i>
                                </button>
                                <button type="button" data-quick-drawer-maximize title="Maximizar" aria-label="Maximizar revisión">
                                    <i class="bi bi-arrows-fullscreen"></i>
                                </button>
                                <button type="button" data-nova-drawer-close title="Cerrar" aria-label="Cerrar revisión">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                        </header>

                        <div class="nova-drawer-body">
                            <div class="tic-quick-drawer-workspace">
                                <section class="tic-quick-editor">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label" for="quick-tipo">Tipo</label>
                                            <input class="form-control" id="quick-tipo" name="tipo" maxlength="80" value="{{ $field('tipo', 'Soporte') }}" data-quick-source="tipo">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label" for="quick-prioridad">Prioridad</label>
                                            <select class="form-select" id="quick-prioridad" name="prioridad" data-quick-source="prioridad">
                                                @foreach (['NORMAL', 'BAJA', 'ALTA', 'URGENTE'] as $priority)
                                                    <option value="{{ $priority }}" @selected($field('prioridad', 'NORMAL') === $priority)>{{ $priority }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label" for="quick-asunto">Problema / asunto</label>
                                            <input class="form-control" id="quick-asunto" name="asunto" maxlength="220" required value="{{ $field('asunto') }}" data-quick-source="asunto">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label" for="quick-descripcion">Descripción</label>
                                            <textarea class="form-control" id="quick-descripcion" name="descripcion" rows="4" maxlength="4000"
                                                      placeholder="Agrega detalles, equipo afectado u observaciones" data-quick-source="descripcion">{{ $field('descripcion') }}</textarea>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label" for="quick-solicitante">Solicitante</label>
                                            <input class="form-control" id="quick-solicitante" name="solicitante" maxlength="160" value="{{ $field('solicitante') }}" data-quick-source="solicitante">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label" for="quick-unidad">Ubicación</label>
                                            <input class="form-control" id="quick-unidad" name="unidad" maxlength="180" value="{{ $field('unidad') }}" data-quick-source="unidad">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label" for="quick-unidad-solicitante">Unidad solicitante</label>
                                            <select class="form-select tic-webhook-select2" id="quick-unidad-solicitante" name="unidad_solicitante"
                                                    required data-tic-webhook-select2 data-placeholder="Seleccionar unidad vigente" data-quick-source="unidad_solicitante">
                                                <option value=""></option>
                                                @foreach ($unitOptions as $unitOption)
                                                    <option value="{{ $unitOption }}" @selected($field('unidad_solicitante') === (string) $unitOption)>{{ $unitOption }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label" for="quick-categoria">Categoría</label>
                                            <select class="form-select tic-webhook-select2" id="quick-categoria" name="categoria"
                                                    data-tic-webhook-select2 data-placeholder="Seleccionar categoría" data-quick-source="categoria">
                                                <option value=""></option>
                                                @foreach ($categoryOptions as $categoryOption)
                                                    <option value="{{ $categoryOption }}" @selected($field('categoria') === (string) $categoryOption)>{{ $categoryOption }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label" for="quick-asignado">Responsable</label>
                                            <select class="form-select tic-webhook-select2" id="quick-asignado" name="asignado_a" required
                                                    data-tic-webhook-select2 data-placeholder="Seleccionar responsable" data-quick-source="responsable">
                                                <option value=""></option>
                                                @foreach ($assigneeOptions as $option)
                                                    <option value="{{ $option['value'] }}" data-chat-id="{{ $option['chat_id'] }}" data-assignee-name="{{ $option['label'] }}"
                                                            @selected($field('asignado_a') === (string) $option['value'])>
                                                        {{ $option['label'] }}{{ $option['chat_id'] === '' ? ' · sin Telegram' : '' }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>

                                    <details class="tic-quick-advanced">
                                        <summary><i class="bi bi-sliders"></i> Fechas, hora extra y estimación</summary>
                                        <div class="row g-3 pt-3">
                                            <div class="col-md-4"><label class="form-label" for="quick-fecha-inicio">Fecha inicio</label><input class="form-control" id="quick-fecha-inicio" type="date" name="fecha_inicio" value="{{ $field('fecha_inicio') }}"></div>
                                            <div class="col-md-4"><label class="form-label" for="quick-fecha-fin">Fecha fin</label><input class="form-control" id="quick-fecha-fin" type="date" name="fecha_fin" value="{{ $field('fecha_fin') }}"></div>
                                            <div class="col-md-4"><label class="form-label" for="quick-fecha">Fecha reporte</label><input class="form-control" id="quick-fecha" type="date" name="fecha" value="{{ $field('fecha') }}"></div>
                                            <div class="col-md-4"><label class="form-label" for="quick-hora">Hora</label><input class="form-control" id="quick-hora" type="time" name="hora" value="{{ $field('hora') }}"></div>
                                            <div class="col-md-4"><label class="form-label" for="quick-hora-extra">Hora extra</label><select class="form-select" id="quick-hora-extra" name="hora_extra"><option value="NO" @selected($field('hora_extra', 'NO') === 'NO')>No</option><option value="SI" @selected($field('hora_extra') === 'SI')>Sí</option></select></div>
                                            <div class="col-md-4"><label class="form-label" for="quick-tiempo">Tiempo estimado</label><input class="form-control" id="quick-tiempo" name="tiempo_estimado" maxlength="40" value="{{ $field('tiempo_estimado') }}" placeholder="Ej: 1.5"></div>
                                        </div>
                                    </details>
                                </section>

                                <aside class="tic-quick-preview" id="tic-quick-confirm-summary" aria-live="polite" hidden>
                                    <div class="tic-quick-preview-topline"><span>Resumen del ticket</span><i class="bi bi-ticket-detailed"></i></div>
                                    <div class="tic-quick-ticket-number">REDMINE · NUEVO</div>
                                    <h3 data-quick-output="asunto">{{ $field('asunto') }}</h3>
                                    <p class="tic-quick-preview-description" data-quick-output="descripcion">{{ $field('descripcion', 'Sin descripción adicional') }}</p>
                                    <dl>
                                        <div><dt>Solicitante</dt><dd data-quick-output="solicitante">{{ $field('solicitante', 'Sin indicar') }}</dd></div>
                                        <div><dt>Ubicación</dt><dd data-quick-output="unidad">{{ $field('unidad', 'Sin indicar') }}</dd></div>
                                        <div><dt>Unidad</dt><dd data-quick-output="unidad_solicitante">{{ $field('unidad_solicitante', 'Sin indicar') }}</dd></div>
                                        <div><dt>Categoría</dt><dd data-quick-output="categoria">{{ $field('categoria', 'Sin indicar') }}</dd></div>
                                        <div><dt>Prioridad</dt><dd data-quick-output="prioridad">{{ $field('prioridad', 'NORMAL') }}</dd></div>
                                        <div><dt>Responsable</dt><dd data-quick-output="responsable">Seleccionado</dd></div>
                                    </dl>
                                    <div class="tic-quick-preview-note"><i class="bi bi-info-circle"></i> El ID y el vínculo se incorporarán al mensaje Telegram después de que Redmine confirme la creación.</div>
                                </aside>
                            </div>
                        </div>

                        <footer class="nova-drawer-footer tic-quick-send-bar">
                            <div class="tic-quick-telegram-state" data-telegram-state>
                                <i class="bi bi-telegram"></i><span>Revisando Telegram del responsable…</span>
                            </div>
                            <button class="btn-nova btn-nova-success" type="submit"
                                    data-app-confirm="Se creará este reporte directamente en Redmine y se notificará al responsable asignado."
                                    data-app-confirm-title="Enviar reporte a Redmine" data-app-confirm-text="Enviar a Redmine"
                                    data-app-confirm-preview="#tic-quick-confirm-summary"
                                    data-app-confirm-tone="primary">
                                <i class="bi bi-send-check"></i> Enviar directamente a Redmine
                            </button>
                        </footer>
                    </form>
                </div>
            </div>
        </div>
    @endif
</section>

@php $quickReportJsVersion = @filemtime(public_path('assets/redmine-tic-quick-report.js')) ?: '1'; @endphp
<script src="{{ asset('assets/redmine-tic-quick-report.js') }}?v={{ $quickReportJsVersion }}"></script>
