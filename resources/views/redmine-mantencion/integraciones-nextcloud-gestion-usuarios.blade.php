<!doctype html>
<html lang="es">
<head>
  <?php $pageTitle = 'Cambiar contraseñas Nextcloud'; $includeTheme = true; include base_path('RedmineMantencion/views/partials/bootstrap-head.php'); ?>
  <?php $nextcloudUsuariosCssVersion = @filemtime(base_path('RedmineMantencion/assets/css/nextcloud-usuarios.css')) ?: time(); ?>
  <link rel="stylesheet" href="<?= $h($mantencionBaseUrl) ?>/assets/css/nextcloud-usuarios.css?v=<?= (int)$nextcloudUsuariosCssVersion ?>">
  <?php $nextcloudGestionCssVersion = @filemtime(base_path('RedmineMantencion/assets/css/nextcloud-gestion-usuarios.css')) ?: time(); ?>
  <link rel="stylesheet" href="<?= $h($mantencionBaseUrl) ?>/assets/css/nextcloud-gestion-usuarios.css?v=<?= (int)$nextcloudGestionCssVersion ?>">
</head>
<body class="bg-light">
<?php $activeNav = 'integraciones_nextcloud_gestion_usuarios'; include base_path('RedmineMantencion/views/partials/navbar.php'); ?>

<div id="page-content">
  <div class="container-fluid py-4">
    <?php
      $heroIcon = 'bi-key-fill';
      $heroTitle = 'Contraseñas de usuarios Nextcloud';
      $heroSubtitle = 'Busca un grupo, despliega sus usuarios y asigna una nueva contraseña.';
      $heroExtras = '';
      include base_path('RedmineMantencion/views/partials/hero.php');
    ?>
    <?php $nextcloudUsersSection = 'manage'; include base_path('RedmineMantencion/views/partials/nextcloud-users-nav.php'); ?>

    <?php if (trim((string)($flash['message'] ?? '')) !== ''): ?>
      <div data-nova-flash="<?= $h($flash['type'] ?? 'info') ?>" data-nova-flash-message="<?= $h($flash['message']) ?>" hidden></div>
    <?php endif; ?>

    <?php if (!$hasSavedNextcloudCredentials): ?>
      <section class="nextcloud-credential-gate" aria-labelledby="nextcloud-credential-title">
        <div class="nextcloud-credential-gate-mark" aria-hidden="true"><i class="bi bi-cloud-lock"></i></div>
        <div>
          <span class="nextcloud-directory-eyebrow">Conexión requerida</span>
          <h2 id="nextcloud-credential-title">Conecta una cuenta administradora</h2>
          <p>Guarda tu usuario administrador y una contraseña de aplicación para consultar grupos y cambiar contraseñas en Nextcloud.</p>
        </div>
        <a class="btn-nova btn-nova-primary" href="<?= $h($credentialsUrl) ?>"><i class="bi bi-key"></i> Configurar credenciales</a>
      </section>
    <?php elseif ($directoryError !== ''): ?>
      <section class="nova-alert-card is-danger mb-3" role="alert">
        <i class="bi bi-cloud-slash"></i>
        <span><strong>No se pudieron consultar los grupos.</strong> <?= $h($directoryError) ?></span>
        <a class="btn-nova btn-nova-secondary ms-auto" href="<?= $h($managementUrl) ?>"><i class="bi bi-arrow-clockwise"></i> Reintentar</a>
      </section>
    <?php else: ?>
      <section class="nextcloud-directory-shell" aria-labelledby="nextcloud-directory-title" data-group-users-url="<?= $h($groupUsersUrl) ?>">
        <header class="nextcloud-directory-head">
          <div class="nextcloud-directory-heading">
            <span class="nextcloud-directory-eyebrow">Directorio por grupos<?= $nextcloudServer !== '' ? ' · '.$h($nextcloudServer) : '' ?></span>
            <div class="nextcloud-directory-title-row">
              <h2 id="nextcloud-directory-title">Grupos disponibles</h2>
              <span class="nextcloud-directory-count" id="nextcloud-directory-count"><?= count($nextcloudGroups) ?> grupo<?= count($nextcloudGroups) === 1 ? '' : 's' ?></span>
            </div>
            <p>Se reutilizan los grupos guardados en Configuración. Los integrantes se consultan únicamente cuando despliegas un grupo.</p>
          </div>
          <a class="btn-nova btn-nova-secondary" href="<?= $h($groupsConfigUrl) ?>"><i class="bi bi-collection"></i> Administrar grupos</a>
        </header>

        <?php if ($nextcloudGroups === []): ?>
          <div class="nova-empty-state nextcloud-directory-empty">
            <div class="nova-empty-state-icon"><i class="bi bi-people"></i></div>
            <h3>Aún no hay grupos consultados</h3>
            <p>Consulta y guarda los grupos desde Configuración para utilizarlos en esta pantalla.</p>
            <a class="btn-nova btn-nova-primary" href="<?= $h($groupsConfigUrl) ?>"><i class="bi bi-arrow-repeat"></i> Ir a consultar grupos</a>
          </div>
        <?php else: ?>
          <div class="nextcloud-group-toolbar">
            <div class="nextcloud-group-selector">
              <label for="nextcloud-group-filter"><i class="bi bi-collection"></i> Mostrar grupo</label>
              <select class="form-select" id="nextcloud-group-filter">
                <option value="">Todos los grupos</option>
                <?php foreach ($nextcloudGroups as $group): ?>
                  <option value="<?= $h($group) ?>"><?= $h($group) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="nextcloud-directory-search">
              <i class="bi bi-search" aria-hidden="true"></i>
              <label class="visually-hidden" for="nextcloud-group-search">Buscar grupo</label>
              <input id="nextcloud-group-search" type="search" placeholder="Buscar por nombre del grupo" autocomplete="off">
            </div>
          </div>

          <div class="nextcloud-group-directory" id="nextcloud-group-directory">
            <?php foreach ($nextcloudGroups as $groupIndex => $group): ?>
              <?php $groupPanelId = 'nextcloud-group-panel-'.($groupIndex + 1); ?>
              <article class="nextcloud-group-card" data-nextcloud-group-card data-group="<?= $h($group) ?>" data-group-search="<?= $h($group) ?>">
                <button type="button" class="nextcloud-group-toggle" data-nextcloud-group-toggle aria-expanded="false" aria-controls="<?= $h($groupPanelId) ?>">
                  <span class="nextcloud-group-mark" aria-hidden="true"><i class="bi bi-people-fill"></i></span>
                  <span class="nextcloud-group-copy">
                    <strong><?= $h($group) ?></strong>
                    <small data-group-summary>Presiona para consultar sus usuarios</small>
                  </span>
                  <span class="nextcloud-group-state" data-group-state>Sin cargar</span>
                  <i class="bi bi-chevron-down nextcloud-group-chevron" aria-hidden="true"></i>
                </button>
                <div class="nextcloud-group-panel" id="<?= $h($groupPanelId) ?>" data-nextcloud-group-panel hidden>
                  <div class="nextcloud-group-placeholder" data-group-placeholder>
                    <i class="bi bi-cloud-arrow-down"></i>
                    <span>Los usuarios se consultarán al desplegar este grupo.</span>
                  </div>
                  <div class="nextcloud-group-users" data-group-users></div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>

          <div class="nova-empty-state nextcloud-directory-no-results" id="nextcloud-directory-no-results" hidden>
            <div class="nova-empty-state-icon"><i class="bi bi-search"></i></div>
            <h3>No encontramos ese grupo</h3>
            <p>Prueba otro nombre o selecciona “Todos los grupos”.</p>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </div>
</div>

<?php if ($hasSavedNextcloudCredentials && $directoryError === ''): ?>
<div class="modal fade nextcloud-user-edit-modal" id="nextcloudUserPasswordModal" tabindex="-1" aria-labelledby="nextcloud-user-password-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="<?= $h($managementUrl) ?>" id="nextcloud-user-password-form" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
        <input type="hidden" name="action" value="change_nextcloud_user_password">
        <input type="hidden" name="userid" id="nextcloud-password-userid" value="">
        <input type="hidden" name="return_group" id="nextcloud-password-return-group" value="">
        <div class="modal-header nextcloud-user-edit-head">
          <div class="nextcloud-user-edit-identity">
            <span class="nextcloud-user-edit-avatar" aria-hidden="true"><i class="bi bi-key-fill"></i></span>
            <span>
              <small>Cambiar contraseña</small>
              <h2 class="modal-title" id="nextcloud-user-password-title">Usuario Nextcloud</h2>
              <code id="nextcloud-password-group"></code>
            </span>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div class="nextcloud-user-edit-note">
            <i class="bi bi-shield-lock"></i>
            <span>La contraseña se envía directamente a Nextcloud. NOVA no la almacena ni la incluye en la actividad.</span>
          </div>
          <div class="nextcloud-password-only-fields">
            <div>
              <label class="form-label" for="nextcloud-edit-password">Nueva contraseña</label>
              <div class="nextcloud-password-input">
                <input class="form-control" type="password" name="password" id="nextcloud-edit-password" minlength="8" maxlength="256" autocomplete="new-password" placeholder="Mínimo 8 caracteres" required>
                <button type="button" data-password-toggle="nextcloud-edit-password" aria-label="Mostrar nueva contraseña"><i class="bi bi-eye"></i></button>
              </div>
            </div>
            <div>
              <label class="form-label" for="nextcloud-edit-password-confirmation">Confirmar contraseña</label>
              <div class="nextcloud-password-input">
                <input class="form-control" type="password" name="password_confirmation" id="nextcloud-edit-password-confirmation" minlength="8" maxlength="256" autocomplete="new-password" placeholder="Repite la nueva contraseña" required>
                <button type="button" data-password-toggle="nextcloud-edit-password-confirmation" aria-label="Mostrar confirmación"><i class="bi bi-eye"></i></button>
              </div>
            </div>
          </div>
          <div class="invalid-feedback d-block" id="nextcloud-password-feedback" hidden>Las contraseñas deben coincidir.</div>
        </div>
        <div class="modal-footer nextcloud-user-edit-actions">
          <button type="button" class="btn-nova btn-nova-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cancelar</button>
          <button type="submit" class="btn-nova btn-nova-primary" <?= $maintenanceMode ? 'disabled title="Plataforma en mantención"' : '' ?>><i class="bi bi-cloud-check"></i> Cambiar contraseña</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include base_path('RedmineMantencion/views/partials/bootstrap-scripts.php'); ?>
<script data-partial-nav-script>
document.addEventListener('DOMContentLoaded', () => {
  const shell = document.querySelector('[data-group-users-url]');
  if (!shell) return;

  const normalize = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  const groupSelect = document.getElementById('nextcloud-group-filter');
  const groupSearch = document.getElementById('nextcloud-group-search');
  const cards = Array.from(document.querySelectorAll('[data-nextcloud-group-card]'));
  const count = document.getElementById('nextcloud-directory-count');
  const noResults = document.getElementById('nextcloud-directory-no-results');
  const groupUsersUrl = shell.dataset.groupUsersUrl || '';

  const visibleGroups = () => cards.filter(card => !card.hidden);
  const applyGroupFilter = () => {
    const selected = normalize(groupSelect?.value);
    const term = normalize(groupSearch?.value);
    cards.forEach(card => {
      const group = normalize(card.dataset.group);
      const matchesSelected = selected === '' || group === selected;
      const matchesSearch = term === '' || normalize(card.dataset.groupSearch).includes(term);
      card.hidden = !(matchesSelected && matchesSearch);
    });
    const visible = visibleGroups();
    if (count) count.textContent = `${visible.length} grupo${visible.length === 1 ? '' : 's'}`;
    if (noResults) noResults.hidden = visible.length !== 0;
    if (selected !== '' && visible.length === 1) openGroup(visible[0]);
  };

  const userRow = (user, group) => {
    const id = String(user?.id || '').trim();
    const row = document.createElement('div');
    row.className = 'nextcloud-group-user';

    const identity = document.createElement('div');
    identity.className = 'nextcloud-group-user-identity';
    const icon = document.createElement('span');
    icon.setAttribute('aria-hidden', 'true');
    icon.innerHTML = '<i class="bi bi-person"></i>';
    const copy = document.createElement('span');
    const strong = document.createElement('strong');
    strong.textContent = id;
    const small = document.createElement('small');
    small.textContent = `Grupo: ${group}`;
    copy.append(strong, small);
    identity.append(icon, copy);

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn-nova btn-nova-primary';
    button.setAttribute('data-bs-toggle', 'modal');
    button.setAttribute('data-bs-target', '#nextcloudUserPasswordModal');
    button.dataset.nextcloudUser = JSON.stringify({ id, group });
    button.setAttribute('aria-label', `Cambiar contraseña de ${id}`);
    button.innerHTML = '<i class="bi bi-key"></i><span>Cambiar contraseña</span>';
    row.append(identity, button);
    return row;
  };

  const renderGroupUsers = (card, users) => {
    const group = card.dataset.group || '';
    const container = card.querySelector('[data-group-users]');
    const placeholder = card.querySelector('[data-group-placeholder]');
    if (!container) return;
    container.replaceChildren();
    if (placeholder) placeholder.hidden = true;

    if (!Array.isArray(users) || users.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'nextcloud-group-empty';
      empty.innerHTML = '<i class="bi bi-person-x"></i><span>Este grupo no tiene usuarios visibles.</span>';
      container.append(empty);
    } else {
      users.forEach(user => container.append(userRow(user, group)));
    }
    card.querySelector('[data-group-summary]').textContent = `${users.length} usuario${users.length === 1 ? '' : 's'}`;
    card.querySelector('[data-group-state]').textContent = 'Cargado';
    card.classList.add('is-loaded');
    card.dataset.loaded = '1';
  };

  const loadGroup = async card => {
    if (card.dataset.loaded === '1' || card.dataset.loading === '1') return;
    card.dataset.loading = '1';
    card.classList.add('is-loading');
    const group = card.dataset.group || '';
    card.querySelector('[data-group-state]').textContent = 'Consultando…';
    window.appUi?.setIntegrationLoading?.(true, {
      title: 'Consultando usuarios de Nextcloud',
      detail: `Buscando integrantes del grupo ${group}.`,
      provider: 'nextcloud'
    });
    try {
      const url = new URL(groupUsersUrl, window.location.href);
      url.searchParams.set('group', group);
      const response = await fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.message || 'No fue posible consultar el grupo.');
      renderGroupUsers(card, payload.users || []);
    } catch (error) {
      const placeholder = card.querySelector('[data-group-placeholder]');
      if (placeholder) {
        placeholder.hidden = false;
        placeholder.classList.add('is-error');
        placeholder.querySelector('i').className = 'bi bi-cloud-slash';
        placeholder.querySelector('span').textContent = error.message || 'No fue posible consultar este grupo.';
      }
      card.querySelector('[data-group-state]').textContent = 'Error';
      window.appUi?.toast?.(error.message || 'No fue posible consultar este grupo.', 'error');
    } finally {
      delete card.dataset.loading;
      card.classList.remove('is-loading');
      window.appUi?.setIntegrationLoading?.(false);
    }
  };

  const openGroup = card => {
    const toggle = card.querySelector('[data-nextcloud-group-toggle]');
    const panel = card.querySelector('[data-nextcloud-group-panel]');
    if (!toggle || !panel) return;
    toggle.setAttribute('aria-expanded', 'true');
    panel.hidden = false;
    card.classList.add('is-open');
    loadGroup(card);
  };

  cards.forEach(card => card.querySelector('[data-nextcloud-group-toggle]')?.addEventListener('click', () => {
    const panel = card.querySelector('[data-nextcloud-group-panel]');
    const toggle = card.querySelector('[data-nextcloud-group-toggle]');
    const willOpen = panel?.hidden ?? true;
    if (willOpen) openGroup(card);
    else {
      panel.hidden = true;
      toggle?.setAttribute('aria-expanded', 'false');
      card.classList.remove('is-open');
    }
  }));
  groupSelect?.addEventListener('change', applyGroupFilter);
  groupSearch?.addEventListener('input', applyGroupFilter);

  const requestedGroup = new URL(window.location.href).searchParams.get('group') || '';
  if (requestedGroup && groupSelect) groupSelect.value = requestedGroup;
  applyGroupFilter();

  const modal = document.getElementById('nextcloudUserPasswordModal');
  const form = document.getElementById('nextcloud-user-password-form');
  const password = document.getElementById('nextcloud-edit-password');
  const passwordConfirmation = document.getElementById('nextcloud-edit-password-confirmation');
  const passwordFeedback = document.getElementById('nextcloud-password-feedback');

  modal?.addEventListener('show.bs.modal', event => {
    form?.reset();
    if (passwordFeedback) passwordFeedback.hidden = true;
    let user = {};
    try { user = JSON.parse(event.relatedTarget?.dataset.nextcloudUser || '{}'); } catch (error) { user = {}; }
    document.getElementById('nextcloud-password-userid').value = user.id || '';
    document.getElementById('nextcloud-password-return-group').value = user.group || '';
    document.getElementById('nextcloud-user-password-title').textContent = user.id || 'Usuario Nextcloud';
    document.getElementById('nextcloud-password-group').textContent = user.group ? `Grupo: ${user.group}` : '';
  });
  modal?.addEventListener('shown.bs.modal', () => password?.focus());

  document.querySelectorAll('[data-password-toggle]').forEach(button => button.addEventListener('click', () => {
    const input = document.getElementById(button.dataset.passwordToggle);
    if (!input) return;
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    button.querySelector('i').className = `bi ${show ? 'bi-eye-slash' : 'bi-eye'}`;
    button.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
  }));

  form?.addEventListener('submit', event => {
    if (password?.value !== passwordConfirmation?.value) {
      event.preventDefault();
      if (passwordFeedback) passwordFeedback.hidden = false;
      passwordConfirmation?.focus();
    }
  });

});
</script>
</body>
</html>
