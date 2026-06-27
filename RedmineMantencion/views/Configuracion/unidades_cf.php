<?php
require_once __DIR__ . '/../../controllers/auth.php';
auth_require_login('/redmine-mantencion/login.php');
require_once __DIR__ . '/../../controllers/storage.php';
require_once __DIR__ . '/../../controllers/maintenance.php';
if (!auth_can('configuracion')) {
  header('Location: ' . legacy_app_url());
  exit;
}

$h = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$csrf = legacy_csrf_token();
function build_cf_url($platformUrl) {
  if (!$platformUrl) return '';
  $parts = parse_url($platformUrl);
  $prefix = '';
  if (!empty($parts['path']) && strpos($parts['path'], '/gp/') !== false) {
    $prefix = '/gp';
  }
  if (preg_match('#/projects/[^/]+/issues(?:\\.json)?$#', $platformUrl)) {
    return preg_replace('#/projects/[^/]+/issues(?:\\.json)?$#', $prefix . '/custom_fields/11.json', $platformUrl);
  }
  if ($parts && !empty($parts['scheme']) && !empty($parts['host'])) {
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    return $parts['scheme'] . '://' . $parts['host'] . $port . $prefix . '/custom_fields/11.json';
  }
  return '';
}

function load_unidades_local() {
  $repo = function_exists('mantencion_catalog_repository') ? mantencion_catalog_repository() : null;
  return $repo !== null ? $repo->unidades() : [];
}
function save_unidades_local($arr) {
  $repo = function_exists('mantencion_catalog_repository') ? mantencion_catalog_repository() : null;
  if ($repo !== null) {
    $repo->upsertUnidades(is_array($arr) ? $arr : []);
  }
}

$configRepo = function_exists('config_mantencion_repository') ? config_mantencion_repository() : null;
$cfg = $configRepo !== null ? $configRepo->loadAll() : [];
$platformUrl = $cfg['platform_url'] ?? '';
$cfOverride = $cfg['unidades_url'] ?? '';
$apiKey = $cfg['platform_token'] ?? '';

$currentUserId = auth_get_user_id();
$userToken = '';
if ($currentUserId) {
  if (function_exists('auth_central_redmine_api_token')) {
    $userToken = auth_central_redmine_api_token($currentUserId, 'redmine_mantencion');
  }
}
$apiKey = $userToken ?: $apiKey;

$cfUrl = $cfOverride ?: build_cf_url($platformUrl);
$flash = null;
$error = null;
$unidades = load_unidades_local();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (function_exists('csrf_validate')) csrf_validate();
  if (function_exists('maintenance_mode_block_if_enabled')) maintenance_mode_block_if_enabled();
if (!$apiKey) {
  $error = 'Falta token de API (usuario o plataforma).';
} elseif (!$cfUrl) {
  $error = 'URL de API inv&aacute;lida. Revisa platform_url/unidades_url.';
} else {
    $ch = curl_init($cfUrl);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => [
        'X-Redmine-API-Key: ' . $apiKey,
        'Accept: application/json'
      ],
      CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
      $error = 'No se pudo conectar: ' . curl_error($ch) . " (URL: $cfUrl)";
    } else {
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      if ($code >= 400) {
        $error = "HTTP $code al consultar el campo personalizado. URL: $cfUrl";
      } else {
        $json = json_decode($resp, true);
        $values = [];
        if (isset($json['custom_field']['possible_values'])) {
          $values = $json['custom_field']['possible_values'];
        } elseif (isset($json['custom_fields']) && is_array($json['custom_fields'])) {
          foreach ($json['custom_fields'] as $cf) {
            if (!is_array($cf)) continue;
            if ((string)($cf['id'] ?? '') === '11' && isset($cf['possible_values'])) {
              $values = $cf['possible_values'];
              break;
            }
          }
        }
        if (!is_array($values)) {
          $error = 'La respuesta no contiene possible_values.';
        } else {
          $parsed = [];
          foreach ($values as $v) {
            if (is_array($v) && isset($v['value'])) {
              $parsed[] = ['id' => $v['value'], 'nombre' => $v['value']];
            } elseif (is_string($v)) {
              $parsed[] = ['id' => $v, 'nombre' => $v];
            }
          }
          save_unidades_local($parsed);
          $unidades = load_unidades_local();
          $flash = 'Unidades sincronizadas (' . count($parsed) . ' registros).';
        }
      }
    }
    curl_close($ch);
  }
}

$total = is_array($unidades) ? count($unidades) : 0;
?>
<!doctype html>
<html lang="es">
<head>
  <?php $pageTitle = 'Unidades solicitantes'; $includeTheme = true; include __DIR__ . '/../partials/bootstrap-head.php'; ?>
</head>
<body class="bg-light">
<?php $activeNav = 'configuracion'; include __DIR__ . '/../partials/navbar.php'; ?>

<div id="page-content">
  <div class="container-fluid py-4">
    <?php
      $heroIcon = 'bi-building';
      $heroTitle = 'Unidades solicitantes';
      $heroSubtitle = 'Sincronizadas desde custom_fields/11.json (solo lectura)';
      $heroExtras = '<span class="badge bg-white bg-opacity-25 text-white border border-white"><i class="bi bi-collection"></i> Total: ' . $h($total) . '</span>';
      include __DIR__ . '/../partials/hero.php';
    ?>

    <?php if ($flash): ?><div class="alert alert-success"><?= $h($flash) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= $h($error) ?></div><?php endif; ?>

    <div class="card mb-3">
      <div class="card-body">
        <form method="post" class="row g-3 align-items-end">
          <div class="col-lg-6">
            <label class="form-label">URL de API</label>
            <input type="text" class="form-control" value="<?= $h($cfUrl) ?>" disabled>
          </div>
          <div class="col-lg-4">
            <label class="form-label">Token (usuario &gt; plataforma)</label>
            <input type="text" class="form-control" value="<?= $h($apiKey ? '********' : 'No definido') ?>" disabled>
          </div>
          <div class="col-lg-2 d-flex gap-2 justify-content-lg-end">
            <a class="btn btn-outline-secondary w-100" href="../Configuracion/configuracion.php"><i class="bi bi-arrow-left"></i> Volver</a>
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <button class="btn btn-primary w-100" type="submit"><i class="bi bi-arrow-repeat"></i> Actualizar</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="mb-0">Lista de unidades</h5>
          <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-light text-dark"><?= $h($total) ?> unidades</span>
            <input type="text" id="filter" class="form-control form-control-sm" placeholder="Filtrar" style="max-width:220px;">
          </div>
        </div>
        <div class="table-responsive">
          <table class="table align-middle" id="tbl">
            <thead><tr><th style="width:100px;">ID</th><th>Nombre</th></tr></thead>
            <tbody>
              <?php if ($unidades): foreach ($unidades as $u): ?>
                <tr>
                  <td class="text-muted"><?= $h($u['id'] ?? '') ?></td>
                  <td><?= $h($u['nombre'] ?? '') ?></td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="2" class="text-center text-muted">A&uacute;n no hay datos sincronizados.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../partials/bootstrap-scripts.php'; ?>
<script>
  const f = document.getElementById('filter');
  const tbl = document.getElementById('tbl');
  if (f && tbl) {
    f.addEventListener('input', () => {
      const t = f.value.toLowerCase();
      tbl.querySelectorAll('tbody tr').forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(t) ? '' : 'none';
      });
    });
  }
</script>
</body>
</html>
