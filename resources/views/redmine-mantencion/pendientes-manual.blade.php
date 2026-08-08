<!doctype html>
<html lang="es">
<head>
  <?php $pageTitle = 'Pendiente Manual'; $includeTheme = true; include base_path('RedmineMantencion/views/partials/bootstrap-head.php'); ?>
</head>
<body>
<?php $activeNav = 'manual'; include base_path('RedmineMantencion/views/partials/navbar.php'); ?>
<div id="page-content">
  <?php $pendienteManualCssVersion = @filemtime(base_path('RedmineMantencion/assets/css/pendiente-manual.css')) ?: time(); ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($mantencionBaseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/css/pendiente-manual.css?v=<?= (int)$pendienteManualCssVersion ?>">
  <div class="container-fluid py-4">
    <?php
      $heroIcon = 'bi-pencil-square';
      $heroTitle = 'Pendiente Manual';
      $heroSubtitle = 'Formulario manual con la misma estructura operativa del ticket en Redmine.';
      include base_path('RedmineMantencion/views/partials/hero.php');
    ?>

    <?php if ($flash): ?><div data-nova-flash="success" data-nova-flash-message="<?= $h($flash) ?>" hidden></div><?php endif; ?>
    <?php if ($error): ?><div data-nova-flash="error" data-nova-flash-message="<?= $h($error) ?>" hidden></div><?php endif; ?>

    <div class="card manual-card">
      <div class="card-body manual-grid">
        <form method="post" action="<?= $h($manualPendingUrl) ?>" class="d-flex flex-column gap-3">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <input type="hidden" name="_token" value="<?= $h($laravelCsrf) ?>">
          <input type="hidden" name="project_id" value="<?= $h($form['project_id'] ?? $cfg['project_id'] ?? 48) ?>">

          <div class="field-row single">
            <div class="field-label">Proyecto *</div>
            <div>
              <select class="form-select" disabled>
                <option selected>&raquo; <?= $h($cfg['project_name'] ?? 'Backlog Mantención TI') ?></option>
              </select>
            </div>
          </div>

          <div class="field-row">
            <div class="field-label">Tipo *</div>
            <div>
              <select name="tracker_id" class="form-select" required>
                <?php foreach (($cfg['trackers'] ?? []) as $tracker): ?>
                  <option value="<?= $h($tracker['id'] ?? '') ?>" <?= (string)($form['tracker_id'] ?? '') === (string)($tracker['id'] ?? '') ? 'selected' : '' ?>>
                    <?= $h($tracker['nombre'] ?? '') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div></div>
            <div></div>
          </div>

          <div class="field-row single">
            <div class="field-label">Asunto *</div>
            <div><input name="asunto" class="form-control" value="<?= $h($form['asunto'] ?? '') ?>" required></div>
          </div>

          <div class="field-row single">
            <div class="field-label">Descripción</div>
            <div>
              <div class="toolbar manual-description-tabs" role="tablist" aria-label="Vista de descripción">
                <button type="button" class="manual-description-tab is-active" id="description-edit-tab" role="tab" aria-selected="true">Modificar</button>
                <button type="button" class="manual-description-tab" id="description-preview-tab" role="tab" aria-selected="false">Previsualizar</button>
              </div>
              <textarea name="descripcion" id="manual-descripcion" class="form-control editor" aria-labelledby="description-edit-tab"><?= $h($form['descripcion'] ?? '') ?></textarea>
              <div class="manual-description-preview" id="manual-description-preview" role="tabpanel" aria-labelledby="description-preview-tab" hidden></div>
              <!-- <div class="form-text">Campo opcional. Puedes pegar texto y tablas juntos; las celdas de Excel conservarán su estructura.</div> -->
            </div>
          </div>

          <div class="field-row">
            <div class="field-label">Estado *</div>
            <div>
              <select name="status_id" class="form-select">
                <?php foreach (($cfg['estados'] ?? []) as $estado): ?>
                  <option value="<?= $h($estado['id'] ?? '') ?>" <?= (string)($form['status_id'] ?? '') === (string)($estado['id'] ?? '') ? 'selected' : '' ?>>
                    <?= $h($estado['nombre'] ?? '') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field-label">Fecha de inicio</div>
            <div>
              <input type="date" name="fecha_inicio" id="manual-fecha-inicio" class="form-control" value="<?= $h($pendientesService->dateForInput($form['fecha_inicio'] ?? '')) ?>">
            </div>
          </div>

          <div class="field-row">
            <div class="field-label">Prioridad *</div>
            <div>
              <select name="priority_id" class="form-select">
                <?php foreach (($cfg['prioridades'] ?? []) as $prioridad): ?>
                  <option value="<?= $h($prioridad['id'] ?? '') ?>" <?= (string)($form['priority_id'] ?? '') === (string)($prioridad['id'] ?? '') ? 'selected' : '' ?>>
                    <?= $h($prioridad['nombre'] ?? '') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field-label">Fecha fin</div>
            <div>
              <input type="date" name="fecha_fin" id="manual-fecha-fin" class="form-control" value="<?= $h($pendientesService->dateForInput($form['fecha_fin'] ?? $form['fecha_inicio'] ?? '')) ?>">
            </div>
          </div>

          <div class="field-row">
            <div class="field-label">Asignado a</div>
            <div>
              <div class="d-flex align-items-center gap-2">
                <?php if ($canAssignOtherUsers): ?>
                  <select name="asignado_a" id="asignado_a" class="form-select mantencion-select2" data-mantencion-select2 data-placeholder="Seleccionar usuario activo">
                    <option value=""></option>
                    <?php foreach ($users as $user): ?>
                      <option value="<?= $h($user['id']) ?>" <?= (string)($form['asignado_a'] ?? '') === (string)$user['id'] ? 'selected' : '' ?>>
                        <?= $h($user['nombre']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button type="button" class="btn btn-outline-secondary btn-sm" id="assign-me" data-assign-me-id="<?= $h($assignMeUserId) ?>" <?= $assignMeUserId === '' ? 'disabled title="No se encontró tu usuario activo en Mantención"' : '' ?>>Asignarme</button>
                <?php else: ?>
                  <input type="text" class="form-control" value="<?= $h($form['core_usuario_asignado'] ?? '') ?>" readonly>
                <?php endif; ?>
              </div>
            </div>
            <div class="field-label">Tiempo estimado</div>
            <div>
              <div class="input-group">
                <input name="tiempo_estimado" class="form-control" value="<?= $h($form['tiempo_estimado'] ?? '') ?>">
                <span class="input-group-text">Horas</span>
              </div>
            </div>
          </div>

          <div class="field-row">
            <div class="field-label">Categoría</div>
            <div>
              <?php $currentManualCategory = trim((string)($form['categoria'] ?? '')); ?>
              <select name="categoria" id="manual-categoria" class="form-select mantencion-select2" data-mantencion-select2 data-placeholder="Seleccionar categoría">
                <option value=""></option>
                <?php if ($currentManualCategory !== '' && !in_array($currentManualCategory, $categoryOptions, true)): ?>
                  <option value="<?= $h($currentManualCategory) ?>" selected><?= $h($currentManualCategory) ?></option>
                <?php endif; ?>
                <?php foreach ($categoryOptions as $categoryOption): ?>
                  <option value="<?= $h($categoryOption) ?>" <?= $currentManualCategory === $categoryOption ? 'selected' : '' ?>><?= $h($categoryOption) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div></div>
            <div></div>
          </div>

          <div class="field-row single">
            <div class="field-label">Solicitante</div>
            <div><input name="solicitante" class="form-control" placeholder="Persona que solicita la actividad" value="<?= $h($form['solicitante'] ?? '') ?>" required></div>
          </div>

          <div class="field-row">
            <div class="field-label">Anexo</div>
            <div><input name="anexo" class="form-control" placeholder="Número telefónico de contacto" value="<?= $h($form['anexo'] ?? '') ?>"></div>
            <div class="field-label">Correo</div>
            <div><input name="core_email" class="form-control" placeholder="Correo electrónico" value="<?= $h($form['core_email'] ?? '') ?>"></div>
          </div>

          <div class="field-row">
            <div class="field-label">Unidad</div>
            <div><input name="unidad" class="form-control" placeholder="Lugar donde realizar la actividad" value="<?= $h($form['unidad'] ?? '') ?>"></div>
            <div class="field-label">Hora Extra *</div>
            <div>
              <select name="hora_extra" class="form-select">
                <option value="0" <?= ($form['hora_extra'] ?? '0') === '0' ? 'selected' : '' ?>>No</option>
                <option value="1" <?= ($form['hora_extra'] ?? '0') === '1' ? 'selected' : '' ?>>Sí</option>
              </select>
            </div>
          </div>

          <div class="rm-manual-actions">
            <button class="btn-nova btn-nova-success" type="submit" <?= $maintenanceMode ? 'disabled title="Plataforma en mantención"' : '' ?>><i class="bi bi-plus-circle"></i>Crear pendiente</button>
            <a class="btn btn-outline-secondary" href="<?= $h(legacy_app_url()) ?>"><i class="bi bi-inboxes"></i>Ver pendientes</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php include base_path('RedmineMantencion/views/partials/bootstrap-scripts.php'); ?>
<script data-partial-nav-script>
  (() => {
    const initMantencionManualSelect2 = () => {
      if (!window.jQuery?.fn?.select2) return;
      window.jQuery('[data-mantencion-select2]').each(function () {
        const select = window.jQuery(this);
        if (select.hasClass('select2-hidden-accessible')) return;
        select.select2({
          width: '100%',
          allowClear: false,
          dropdownCssClass: 'tic-select2-dropdown',
          placeholder: this.dataset.placeholder || 'Seleccionar',
          language: {
            noResults: () => 'No se encontraron resultados',
            searching: () => 'Buscando...'
          }
        });
      });
    };
    initMantencionManualSelect2();

    const currentUrl = new URL(window.location.href);
    if (currentUrl.searchParams.get('created') === '1') {
      currentUrl.searchParams.delete('created');
      window.history.replaceState({}, document.title, currentUrl.toString());
    }

    const startDateInput = document.getElementById('manual-fecha-inicio');
    const endDateInput = document.getElementById('manual-fecha-fin');
    const syncManualDates = () => {
      if (startDateInput && endDateInput) endDateInput.value = startDateInput.value;
    };
    startDateInput?.addEventListener('input', syncManualDates);
    startDateInput?.addEventListener('change', syncManualDates);
    syncManualDates();

    const descriptionInput = document.getElementById('manual-descripcion');
    const descriptionPreview = document.getElementById('manual-description-preview');
    const descriptionEditTab = document.getElementById('description-edit-tab');
    const descriptionPreviewTab = document.getElementById('description-preview-tab');

    const cleanCell = value => String(value || '')
      .replace(/\r?\n/g, '<br>')
      .replace(/\|/g, '\\|')
      .trim();

    const rowsToMarkdown = rows => {
      const normalizedRows = rows
        .map(row => row.map(cleanCell))
        .filter(row => row.some(cell => cell !== ''));
      const columnCount = normalizedRows.reduce((max, row) => Math.max(max, row.length), 0);
      if (normalizedRows.length < 1 || columnCount < 2) return '';
      normalizedRows.forEach(row => {
        while (row.length < columnCount) row.push('');
      });
      const line = row => `| ${row.join(' | ')} |`;
      return [
        line(normalizedRows[0]),
        line(Array(columnCount).fill('---')),
        ...normalizedRows.slice(1).map(line),
      ].join('\n');
    };

    const tableRowsFromElement = table => Array.from(table.rows).map(row =>
      Array.from(row.cells).map(cell => cell.innerText || cell.textContent || '')
    );

    const plainClipboardToMarkdown = text => {
      const lines = String(text || '').replace(/\r\n?/g, '\n').split('\n');
      const output = [];
      let foundTable = false;

      for (let index = 0; index < lines.length;) {
        if (!lines[index].includes('\t')) {
          output.push(lines[index]);
          index += 1;
          continue;
        }

        const rows = [];
        while (index < lines.length && lines[index].includes('\t')) {
          rows.push(lines[index].split('\t'));
          index += 1;
        }
        const markdown = rowsToMarkdown(rows);
        if (markdown) {
          foundTable = true;
          output.push(markdown);
        } else {
          output.push(...rows.map(row => row.join('\t')));
        }
      }

      return {
        foundTable,
        markdown: output.join('\n').replace(/\n{3,}/g, '\n\n').trim(),
      };
    };

    const htmlClipboardToMarkdown = html => {
      if (!html) return { foundTable: false, markdown: '' };
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const tables = Array.from(doc.querySelectorAll('table'));
      if (!tables.length) return { foundTable: false, markdown: '' };

      const replacements = new Map();
      tables.forEach((table, index) => {
        const markdown = rowsToMarkdown(tableRowsFromElement(table));
        if (!markdown) return;
        const marker = `NOVA_CLIPBOARD_TABLE_${index}`;
        replacements.set(marker, markdown);
        table.replaceWith(doc.createTextNode(`\n${marker}\n`));
      });
      if (!replacements.size) return { foundTable: false, markdown: '' };

      const blockTags = new Set([
        'ADDRESS', 'ARTICLE', 'ASIDE', 'BLOCKQUOTE', 'DIV', 'FOOTER', 'H1', 'H2',
        'H3', 'H4', 'H5', 'H6', 'HEADER', 'LI', 'MAIN', 'P', 'PRE', 'SECTION',
      ]);
      const nodeText = node => {
        if (node.nodeType === Node.TEXT_NODE) return node.nodeValue || '';
        if (node.nodeType !== Node.ELEMENT_NODE) return '';
        if (node.tagName === 'BR') return '\n';
        const content = Array.from(node.childNodes).map(nodeText).join('');
        return blockTags.has(node.tagName) ? `${content}\n` : content;
      };

      let markdown = nodeText(doc.body)
        .replace(/\u00a0/g, ' ')
        .replace(/[ \t]+\n/g, '\n')
        .replace(/\n[ \t]+/g, '\n');
      replacements.forEach((tableMarkdown, marker) => {
        markdown = markdown.replace(marker, `\n${tableMarkdown}\n`);
      });

      return {
        foundTable: true,
        markdown: markdown.replace(/\n{3,}/g, '\n\n').trim(),
      };
    };

    const clipboardDescriptionMarkdown = clipboardData => {
      const plainResult = plainClipboardToMarkdown(clipboardData.getData('text/plain'));
      if (plainResult.foundTable) return plainResult.markdown;
      const htmlResult = htmlClipboardToMarkdown(clipboardData.getData('text/html'));
      return htmlResult.foundTable ? htmlResult.markdown : '';
    };

    const insertDescriptionBlock = content => {
      const start = descriptionInput.selectionStart ?? descriptionInput.value.length;
      const end = descriptionInput.selectionEnd ?? start;
      const before = descriptionInput.value.slice(0, start);
      const after = descriptionInput.value.slice(end);
      const prefix = before === '' || before.endsWith('\n\n')
        ? ''
        : (before.endsWith('\n') ? '\n' : '\n\n');
      const suffix = after.startsWith('\n\n')
        ? ''
        : (after.startsWith('\n') ? '\n' : '\n\n');
      const inserted = `${prefix}${content.trim()}${suffix}`;
      descriptionInput.value = `${before}${inserted}${after}`;
      const cursor = before.length + inserted.length;
      descriptionInput.setSelectionRange(cursor, cursor);
      descriptionInput.dispatchEvent(new Event('input', { bubbles: true }));
    };

    descriptionInput?.addEventListener('paste', event => {
      const markdown = clipboardDescriptionMarkdown(event.clipboardData);
      if (!markdown) return;
      event.preventDefault();
      insertDescriptionBlock(markdown);
    });

    const markdownCells = line => {
      const source = line.trim().replace(/^\||\|$/g, '');
      const cells = [];
      let cell = '';
      for (let index = 0; index < source.length; index += 1) {
        const character = source[index];
        if (character === '\\' && source[index + 1] === '|') {
          cell += '|';
          index += 1;
        } else if (character === '|') {
          cells.push(cell.trim().replace(/<br>/g, '\n'));
          cell = '';
        } else {
          cell += character;
        }
      }
      cells.push(cell.trim().replace(/<br>/g, '\n'));
      return cells;
    };
    const isMarkdownSeparator = line =>
      /^\s*\|?(?:\s*:?-{3,}:?\s*\|)+\s*$/.test(line || '');
    const isMarkdownTableRow = line => {
      const value = String(line || '').trim();
      return value.startsWith('|') && value.endsWith('|') && value.slice(1, -1).includes('|');
    };
    const renderDescriptionPreview = () => {
      if (!descriptionPreview || !descriptionInput) return;
      descriptionPreview.replaceChildren();
      const value = descriptionInput.value;
      if (!value.trim()) {
        const text = document.createElement('div');
        text.className = 'manual-description-preview__text';
        text.textContent = 'Sin descripción.';
        descriptionPreview.appendChild(text);
        return;
      }

      const lines = value.replace(/\r\n?/g, '\n').split('\n');
      const appendText = textLines => {
        const content = textLines.join('\n').replace(/^\n+|\n+$/g, '');
        if (!content) return;
        const text = document.createElement('div');
        text.className = 'manual-description-preview__text';
        text.textContent = content;
        descriptionPreview.appendChild(text);
      };
      const appendTable = tableLines => {
        const table = document.createElement('table');
        table.className = 'table table-sm table-bordered align-middle mb-0';
        const thead = document.createElement('thead');
        const tbody = document.createElement('tbody');
        const appendRow = (target, cells, tag) => {
          const tr = document.createElement('tr');
          cells.forEach(cellValue => {
            const cell = document.createElement(tag);
            cell.textContent = cellValue;
            tr.appendChild(cell);
          });
          target.appendChild(tr);
        };
        appendRow(thead, markdownCells(tableLines[0]), 'th');
        tableLines.slice(2).forEach(line => appendRow(tbody, markdownCells(line), 'td'));
        table.append(thead, tbody);
        const wrapper = document.createElement('div');
        wrapper.className = 'table-responsive manual-description-preview__table';
        wrapper.appendChild(table);
        descriptionPreview.appendChild(wrapper);
      };

      let textLines = [];
      for (let index = 0; index < lines.length;) {
        const startsTable = index + 1 < lines.length
          && isMarkdownTableRow(lines[index])
          && isMarkdownSeparator(lines[index + 1]);
        if (!startsTable) {
          textLines.push(lines[index]);
          index += 1;
          continue;
        }

        appendText(textLines);
        textLines = [];
        const tableLines = [lines[index], lines[index + 1]];
        index += 2;
        while (index < lines.length && isMarkdownTableRow(lines[index])) {
          tableLines.push(lines[index]);
          index += 1;
        }
        appendTable(tableLines);
      }
      appendText(textLines);
    };

    const showDescriptionMode = preview => {
      if (!descriptionInput || !descriptionPreview) return;
      if (preview) renderDescriptionPreview();
      descriptionInput.hidden = preview;
      descriptionPreview.hidden = !preview;
      descriptionEditTab?.classList.toggle('is-active', !preview);
      descriptionPreviewTab?.classList.toggle('is-active', preview);
      descriptionEditTab?.setAttribute('aria-selected', preview ? 'false' : 'true');
      descriptionPreviewTab?.setAttribute('aria-selected', preview ? 'true' : 'false');
    };
    descriptionEditTab?.addEventListener('click', () => showDescriptionMode(false));
    descriptionPreviewTab?.addEventListener('click', () => showDescriptionMode(true));

    const assignMeBtn = document.getElementById('assign-me');
    const assignedSelect = document.getElementById('asignado_a');
    const currentUserId = assignMeBtn?.dataset.assignMeId || '';
    if (assignMeBtn && assignedSelect) {
      assignMeBtn.addEventListener('click', () => {
        if (!currentUserId) {
          window.NovaToast?.warning?.('No se encontró tu usuario activo en Mantención.');
          return;
        }
        if (![...assignedSelect.options].some(option => option.value === currentUserId)) {
          window.NovaToast?.warning?.('Tu usuario no está disponible en la lista de asignación.');
          return;
        }
        assignedSelect.value = currentUserId;
        assignedSelect.dispatchEvent(new Event('change', { bubbles: true }));
      });
    }
  })();
</script>
</body>
</html>
