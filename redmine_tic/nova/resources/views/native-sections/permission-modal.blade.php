<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form class="modal-content" method="post" action="{{ $formAction }}">
            @csrf
            <input type="hidden" name="config_action" value="{{ $configAction }}">
            @foreach (($hiddenFields ?? []) as $field => $value)
                <input type="hidden" name="{{ $field }}" value="{{ $value }}">
            @endforeach
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5">{{ $title }}</h2>
                    <div class="text-muted fw-semibold">{{ $subtitle }}</div>
                </div>
                <button type="button" class="btn-close" data-nova-modal-close data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    @if (!empty($showRoleSelect))
                        <div class="col-12 col-md-6">
                            <label class="form-label">Rol asignado</label>
                            <select class="form-select" name="user_role">
                                @foreach (array_unique(array_merge(array_keys($roles), [$selectedRole ?? 'usuario'])) as $roleOption)
                                    <option value="{{ $roleOption }}" @selected(($selectedRole ?? 'usuario') === $roleOption)>{{ $roleOption }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div class="col-12">
                        <h3 class="rm-permission-title">Alcance</h3>
                        <div class="row g-3">
                            <div class="col-12 col-md-4">
                                <label class="form-label">Reportes</label>
                                <select class="form-select" name="perm_mensajes_scope">
                                    <option value="todos" @selected(($permissions['mensajes'] ?? 'asignados') === 'todos')>Ver todos</option>
                                    <option value="asignados" @selected(($permissions['mensajes'] ?? 'asignados') !== 'todos')>Solo asignados</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Horas extra</label>
                                <select class="form-select" name="perm_horas_scope">
                                    <option value="todos" @selected(($permissions['horas_extra'] ?? 'asignados') === 'todos')>Ver todas</option>
                                    <option value="asignados" @selected(($permissions['horas_extra'] ?? 'asignados') !== 'todos')>Solo asignadas</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Historico</label>
                                <select class="form-select" name="perm_historico_scope">
                                    <option value="todos" @selected(($permissions['historico_scope'] ?? 'asignados') === 'todos')>Ver todos</option>
                                    <option value="asignados" @selected(($permissions['historico_scope'] ?? 'asignados') !== 'todos')>Solo asignados</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <h3 class="rm-permission-title">Accesos a vistas</h3>
                        <div class="rm-permission-grid">
                            @foreach ($viewPermissions as $permissionKey => $permissionLabel)
                                <label class="form-check rm-modal-check">
                                    <input class="form-check-input" type="checkbox" name="perm_{{ $permissionKey }}" value="1" @checked(!empty($permissions[$permissionKey]))>
                                    <span class="form-check-label">{{ $permissionLabel }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="col-12">
                        <h3 class="rm-permission-title">Editar y eliminar datos</h3>
                        <div class="rm-permission-grid">
                            @foreach ($dataActionPermissions as $permissionKey => $permissionLabel)
                                <label class="form-check rm-modal-check">
                                    <input class="form-check-input" type="checkbox" name="perm_{{ $permissionKey }}" value="1" @checked(!empty($permissions[$permissionKey]))>
                                    <span class="form-check-label">{{ $permissionLabel }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="col-12">
                        <h3 class="rm-permission-title">Permisos de configuracion</h3>
                        <div class="rm-permission-grid">
                            @foreach ($configPermissions as $permissionKey => $permissionLabel)
                                <label class="form-check rm-modal-check">
                                    <input class="form-check-input" type="checkbox" name="perm_{{ $permissionKey }}" value="1" @checked(!empty($permissions[$permissionKey]))>
                                    <span class="form-check-label">{{ $permissionLabel }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-nova-modal-close data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i>Guardar permisos</button>
            </div>
        </form>
    </div>
</div>

