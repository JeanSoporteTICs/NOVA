<?php

$pageTitle = 'EMACH';
$activeNav = 'inicio';
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$user = $_SESSION['user'] ?? [];
$currentYear = (int) date('Y');
$currentMonth = (int) date('n');
$requestData = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET;
$submitted = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$selectedYear = max(2020, min(2100, (int) ($requestData['ano'] ?? $currentYear)));
$selectedMonth = max(1, min(12, (int) ($requestData['mes'] ?? $currentMonth)));
$emachUsername = trim((string) ($requestData['emach_usuario'] ?? ''));
$emachPassword = (string) ($requestData['emach_password'] ?? '');
$rememberEmachCredentials = (string) ($requestData['remember_emach_credentials'] ?? '') === '1';
$credentialMessage = '';
$monthNames = [
  1 => 'Enero',
  2 => 'Febrero',
  3 => 'Marzo',
  4 => 'Abril',
  5 => 'Mayo',
  6 => 'Junio',
  7 => 'Julio',
  8 => 'Agosto',
  9 => 'Septiembre',
  10 => 'Octubre',
  11 => 'Noviembre',
  12 => 'Diciembre',
];
$selectedMonthName = $monthNames[$selectedMonth] ?? (string) $selectedMonth;

$columns = [
  'Codigo enrolamiento',
  'RUN',
  'Nombre',
  'Fecha',
  'Marcas',
  'Tipo',
  'Reloj',
  'Long.',
  'Lat.',
  'Precision',
];

function emach_nova_users_path(): string {
  return function_exists('storage_path') ? storage_path('app/nova/users.json') : __DIR__ . '/../storage/app/nova/users.json';
}

function emach_read_nova_users(): array {
  $path = emach_nova_users_path();
  $raw = (string) @file_get_contents($path);
  $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
  $users = json_decode($raw, true);
  return is_array($users) ? array_values(array_filter($users, 'is_array')) : [];
}

function emach_write_nova_users(array $users): bool {
  $path = emach_nova_users_path();
  $directory = dirname($path);
  if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
    return false;
  }
  $written = @file_put_contents($path, json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL, LOCK_EX);
  if ($written !== false) {
    @chmod($path, 0666);
  }
  return $written !== false;
}

function emach_session_user(): array {
  if (function_exists('request')) {
    $novaUser = request()->session()->get('nova_user');
    if (is_array($novaUser)) {
      return $novaUser;
    }
  }
  return is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
}

function emach_find_current_user_index(array $users): ?int {
  $sessionUser = emach_session_user();
  $needles = array_values(array_filter(array_map(static fn($value): string => strtolower((string) preg_replace('/[^0-9a-z]/i', '', (string) $value)), [
    $sessionUser['id'] ?? '',
    $sessionUser['username'] ?? '',
    $sessionUser['rut'] ?? '',
    $sessionUser['rut_sin_dv'] ?? '',
    $sessionUser['core_user'] ?? '',
    $sessionUser['redmine_id'] ?? '',
    $sessionUser['legacy']['id'] ?? '',
  ])));
  if ($needles === []) {
    return null;
  }
  foreach ($users as $index => $user) {
    $candidates = array_values(array_filter(array_map(static fn($value): string => strtolower((string) preg_replace('/[^0-9a-z]/i', '', (string) $value)), [
      $user['id'] ?? '',
      $user['username'] ?? '',
      $user['rut'] ?? '',
      $user['rut_sin_dv'] ?? '',
      $user['core_user'] ?? '',
      $user['redmine_id'] ?? '',
    ])));
    if (array_intersect($needles, $candidates) !== []) {
      return $index;
    }
  }
  return null;
}

function emach_encrypt_secret(string $secret): string {
  return function_exists('encrypt') ? encrypt($secret) : $secret;
}

function emach_decrypt_secret(string $secret): string {
  if ($secret === '') {
    return '';
  }
  if (function_exists('decrypt')) {
    try {
      return (string) decrypt($secret);
    } catch (Throwable) {
    }
  }
  return $secret;
}

function emach_current_user_credentials(): array {
  if (function_exists('app')) {
    return app(\App\Support\Integrations\UserIntegrationRepository::class)->emachForSession(emach_session_user());
  }

  $users = emach_read_nova_users();
  $index = emach_find_current_user_index($users);
  if ($index === null) {
    return ['user' => '', 'password' => '', 'stored' => false];
  }
  $credentials = is_array($users[$index]['emach_credentials'] ?? null) ? $users[$index]['emach_credentials'] : [];
  $user = trim((string) ($credentials['user'] ?? ''));
  $password = emach_decrypt_secret((string) ($credentials['password'] ?? ''));
  return [
    'user' => $user,
    'password' => $password,
    'stored' => $user !== '' && $password !== '',
  ];
}

function emach_save_current_user_credentials(string $username, string $password): bool {
  if (function_exists('app')) {
    $saved = app(\App\Support\Integrations\UserIntegrationRepository::class)->saveEmachForSession(emach_session_user(), $username, $password);
    if ($saved && function_exists('request')) {
      $sessionUser = request()->session()->get('nova_user');
      if (is_array($sessionUser)) {
        $sessionUser['has_emach_credentials'] = true;
        request()->session()->put('nova_user', $sessionUser);
      }
    }
    return $saved;
  }

  $users = emach_read_nova_users();
  $index = emach_find_current_user_index($users);
  if ($index === null || $username === '' || $password === '') {
    return false;
  }
  $users[$index]['emach_credentials'] = [
    'user' => $username,
    'password' => emach_encrypt_secret($password),
    'updated_at' => date(DATE_ATOM),
  ];
  $saved = emach_write_nova_users($users);
  if ($saved && function_exists('request')) {
    $sessionUser = request()->session()->get('nova_user');
    if (is_array($sessionUser)) {
      $sessionUser['has_emach_credentials'] = true;
      request()->session()->put('nova_user', $sessionUser);
    }
  }
  return $saved;
}

$storedEmachCredentials = emach_current_user_credentials();
if ($emachUsername === '' && (string) ($storedEmachCredentials['user'] ?? '') !== '') {
  $emachUsername = (string) $storedEmachCredentials['user'];
}
if ($submitted && $emachPassword === '' && (string) ($storedEmachCredentials['password'] ?? '') !== '') {
  $emachPassword = (string) $storedEmachCredentials['password'];
}
if ($submitted && $rememberEmachCredentials && $emachUsername !== '' && $emachPassword !== '') {
  if (emach_save_current_user_credentials($emachUsername, $emachPassword)) {
    $storedEmachCredentials = emach_current_user_credentials();
    $credentialMessage = 'Credenciales EMACH guardadas para tu usuario NOVA.';
  } else {
    $credentialMessage = 'No se pudieron guardar las credenciales EMACH para tu usuario.';
  }
}
$hasSavedEmachCredentials = (bool) ($storedEmachCredentials['stored'] ?? false);

function emach_planilla_url(int $year, int $month): string {
  return emach_planilla_urls($year, $month)[0];
}

function emach_planilla_urls(int $year, int $month): array {
  $query = http_build_query([
    'ano' => $year,
    'mes' => $month,
    '_' => (int) round(microtime(true) * 1000),
  ]);
  return [
    'http://10.6.206.19/index.php/reportes/getplanilla?' . $query,
    'http://10.6.206.19/index.php/autoconsulta/getplanilla?' . $query,
  ];
}

function emach_curl_request(string $url, string $cookieFile, string $username = '', string $password = '', array $options = []): array {
  $ch = curl_init($url);
  $headers = $options['headers'] ?? ['Accept: application/json'];
  if (!empty($options['referer'])) {
    $headers[] = 'Referer: ' . $options['referer'];
  }
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_ENCODING => '',
    CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36',
  ]);

  if ($username !== '' || $password !== '') {
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
    curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
  }

  if (($options['method'] ?? 'GET') === 'POST') {
    $fields = $options['fields'] ?? [];
    if (is_array($fields)) {
      $fields = http_build_query($fields);
      if (!array_filter($headers, static fn($header): bool => str_starts_with(strtolower($header), 'content-type:'))) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      }
    }
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
  }

  $body = curl_exec($ch);
  $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
  $curlError = (string) curl_error($ch);
  curl_close($ch);

  return [
    'body' => $body === false ? '' : (string) $body,
    'http_code' => $httpCode,
    'content_type' => $contentType,
    'effective_url' => $effectiveUrl,
    'error' => $curlError,
  ];
}

function emach_absolute_url(string $url, string $baseUrl): string {
  $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
  if ($url === '') {
    return $baseUrl;
  }
  if (preg_match('#^https?://#i', $url)) {
    return $url;
  }
  $parts = parse_url($baseUrl);
  if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
    return $url;
  }
  $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
  if (str_starts_with($url, '/')) {
    return $origin . $url;
  }
  $path = (string) ($parts['path'] ?? '/');
  $directory = rtrim(substr($path, 0, (int) strrpos($path, '/') + 1), '/');
  return $origin . $directory . '/' . ltrim($url, '/');
}

function emach_parse_attrs(string $tag): array {
  $attrs = [];
  preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(["\'])(.*?)\2/s', $tag, $matches, PREG_SET_ORDER);
  foreach ($matches as $match) {
    $attrs[strtolower($match[1])] = html_entity_decode($match[3], ENT_QUOTES, 'UTF-8');
  }
  return $attrs;
}

function emach_html_diagnostics(string $html, array $response): array {
  $title = '';
  if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $titleMatch)) {
    $title = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($titleMatch[1]), ENT_QUOTES, 'UTF-8')) ?? '');
  }

  $forms = [];
  preg_match_all('/<form\b([^>]*)>(.*?)<\/form>/is', $html, $formMatches, PREG_SET_ORDER);
  foreach ($formMatches as $formMatch) {
    $formAttrs = emach_parse_attrs($formMatch[1]);
    $inputs = [];
    preg_match_all('/<input\b([^>]*)>/is', $formMatch[2], $inputMatches, PREG_SET_ORDER);
    foreach ($inputMatches as $inputMatch) {
      $inputAttrs = emach_parse_attrs($inputMatch[1]);
      $name = (string) ($inputAttrs['name'] ?? '');
      if ($name === '') {
        continue;
      }
      $inputs[] = [
        'name' => $name,
        'type' => strtolower((string) ($inputAttrs['type'] ?? 'text')),
      ];
    }
    $forms[] = [
      'id' => (string) ($formAttrs['id'] ?? ''),
      'action' => (string) ($formAttrs['action'] ?? ''),
      'method' => strtoupper((string) ($formAttrs['method'] ?? 'GET')),
      'inputs' => $inputs,
    ];
  }

  return [
    'effective_url' => (string) ($response['effective_url'] ?? ''),
    'content_type' => (string) ($response['content_type'] ?? ''),
    'title' => $title,
    'forms' => $forms,
  ];
}

function emach_login_trabajador_from_html(string $html, string $pageUrl, string $cookieFile, string $username, string $password): bool {
  if ($html === '' || stripos($html, 'frmTrabajador') === false) {
    return false;
  }

  if (!preg_match('/<form\b([^>]*\bid\s*=\s*(["\'])frmTrabajador\2[^>]*)>(.*?)<\/form>/is', $html, $form)) {
    return false;
  }

  $formAttrs = emach_parse_attrs($form[1]);
  $formBody = $form[3];
  $fields = [];
  preg_match_all('/<input\b([^>]*)>/is', $formBody, $inputs, PREG_SET_ORDER);
  foreach ($inputs as $input) {
    $attrs = emach_parse_attrs($input[1]);
    $name = (string) ($attrs['name'] ?? '');
    if ($name === '') {
      continue;
    }
    $fields[$name] = (string) ($attrs['value'] ?? '');
  }

  $fields['rut'] = $username;
  $fields['pass'] = $password;
  $fields['url'] = $fields['url'] ?? '/index.php/autoconsulta/marcas/';

  $action = emach_absolute_url((string) ($formAttrs['action'] ?? '/index.php/cloud/doLoginTrabajador'), $pageUrl);
  $response = emach_curl_request($action, $cookieFile, '', '', [
    'method' => 'POST',
    'fields' => $fields,
    'headers' => [
      'Accept: text/html,application/xhtml+xml,application/json',
      'Origin: http://10.6.206.19',
    ],
    'referer' => $pageUrl,
  ]);

  return $response['error'] === '' && $response['http_code'] >= 200 && $response['http_code'] < 400;
}

function emach_login_from_html(string $html, string $pageUrl, string $cookieFile, string $username, string $password): bool {
  if ($html === '' || stripos($html, '<form') === false) {
    return false;
  }

  preg_match_all('/<form\b([^>]*)>(.*?)<\/form>/is', $html, $forms, PREG_SET_ORDER);
  foreach ($forms as $form) {
    $formAttrs = emach_parse_attrs($form[1]);
    $formBody = $form[2];
    if (stripos($formBody, 'password') === false && stripos($formBody, 'contras') === false) {
      continue;
    }

    $fields = [];
    $passwordName = '';
    $usernameName = '';
    preg_match_all('/<input\b([^>]*)>/is', $formBody, $inputs, PREG_SET_ORDER);
    foreach ($inputs as $input) {
      $attrs = emach_parse_attrs($input[1]);
      $name = (string) ($attrs['name'] ?? '');
      if ($name === '') {
        continue;
      }
      $type = strtolower((string) ($attrs['type'] ?? 'text'));
      $fields[$name] = (string) ($attrs['value'] ?? '');
      if ($type === 'password') {
        $passwordName = $name;
      } elseif ($usernameName === '' && in_array($type, ['text', 'email', 'number', 'tel', 'search'], true)) {
        $usernameName = $name;
      }
    }

    if ($passwordName === '') {
      continue;
    }
    if ($usernameName === '') {
      foreach (array_keys($fields) as $name) {
        $key = strtolower($name);
        if (str_contains($key, 'user') || str_contains($key, 'login') || str_contains($key, 'rut') || str_contains($key, 'run') || str_contains($key, 'codigo')) {
          $usernameName = $name;
          break;
        }
      }
    }
    if ($usernameName === '') {
      continue;
    }

    $fields[$usernameName] = $username;
    $fields[$passwordName] = $password;
    foreach (['login', 'yt0', 'submit', 'ingresar'] as $submitName) {
      if (!isset($fields[$submitName])) {
        $fields[$submitName] = 'Ingresar';
      }
    }

    $action = emach_absolute_url((string) ($formAttrs['action'] ?? ''), $pageUrl);
    $response = emach_curl_request($action, $cookieFile, $username, $password, [
      'method' => 'POST',
      'fields' => $fields,
      'headers' => ['Accept: text/html,application/xhtml+xml,application/json'],
      'referer' => $pageUrl,
    ]);

    if ($response['error'] === '' && $response['http_code'] >= 200 && $response['http_code'] < 400) {
      return true;
    }
  }

  return false;
}

function emach_prime_session(string $cookieFile, string $username, string $password): void {
  $base = 'http://10.6.206.19/index.php';
  $landingUrl = $base . '/autoconsulta/marcas/';
  $landing = emach_curl_request($landingUrl, $cookieFile, '', '', [
    'headers' => ['Accept: text/html,application/xhtml+xml,application/json'],
  ]);

  $loggedAsWorker = emach_login_trabajador_from_html($landing['body'], $landing['effective_url'] ?: $landingUrl, $cookieFile, $username, $password);
  if (!$loggedAsWorker) {
    emach_login_from_html($landing['body'], $landing['effective_url'] ?: $landingUrl, $cookieFile, $username, $password);
  }

  $loginPageUrl = $base . '/site/login';
  $loginPage = emach_curl_request($loginPageUrl, $cookieFile, '', '', [
    'headers' => ['Accept: text/html,application/xhtml+xml,application/json'],
    'referer' => $landingUrl,
  ]);
  if (!$loggedAsWorker) {
    $loggedAsWorker = emach_login_trabajador_from_html($loginPage['body'], $loginPage['effective_url'] ?: $loginPageUrl, $cookieFile, $username, $password);
  }
  if (!$loggedAsWorker) {
    emach_login_from_html($loginPage['body'], $loginPage['effective_url'] ?: $loginPageUrl, $cookieFile, $username, $password);
  }

  $loginAttempts = [
    [$base . '/cloud/doLoginTrabajador', [
      'csrf_test_name' => '',
      'url' => '/index.php/autoconsulta/marcas/',
      'rut' => $username,
      'pass' => $password,
    ]],
    [$base . '/site/login', [
      'LoginForm[username]' => $username,
      'LoginForm[password]' => $password,
      'LoginForm[rememberMe]' => '0',
      'login' => $username,
      'password' => $password,
      'usuario' => $username,
      'clave' => $password,
      'rut' => $username,
      'run' => $username,
      ]],
    [$base . '/autoconsulta/login', [
      'usuario' => $username,
      'contrasena' => $password,
      'clave' => $password,
      'username' => $username,
      'password' => $password,
      'rut' => $username,
      'run' => $username,
    ]],
    [$base . '/autoconsulta/marcas/', [
      'usuario' => $username,
      'contrasena' => $password,
      'clave' => $password,
      'username' => $username,
      'password' => $password,
      'rut' => $username,
      'run' => $username,
    ]],
    [$base . '/autoconsulta/marcas', [
      'usuario' => $username,
      'contrasena' => $password,
      'clave' => $password,
      'username' => $username,
      'password' => $password,
      'rut' => $username,
      'run' => $username,
    ]],
  ];

  if (!$loggedAsWorker) {
    foreach ($loginAttempts as [$loginUrl, $fields]) {
      emach_curl_request($loginUrl, $cookieFile, '', '', [
        'method' => 'POST',
        'fields' => $fields,
        'headers' => ['Accept: text/html,application/xhtml+xml,application/json'],
        'referer' => $landingUrl,
      ]);
    }
  }

  emach_curl_request($landingUrl, $cookieFile, '', '', [
    'headers' => ['Accept: text/html,application/xhtml+xml,application/json'],
    'referer' => $base . '/site/login',
  ]);
  emach_curl_request($base . '/reportes/planilla', $cookieFile, '', '', [
    'headers' => ['Accept: text/html,application/xhtml+xml,application/json'],
    'referer' => $landingUrl,
  ]);
}

function emach_fetch_planilla(int $year, int $month, string $username, string $password): array {
  $url = emach_planilla_url($year, $month);
  if (!function_exists('curl_init')) {
    return ['ok' => false, 'url' => $url, 'rows' => [], 'error' => 'Extension cURL no disponible.'];
  }

  if ($username === '' || $password === '') {
    return ['ok' => false, 'url' => $url, 'rows' => [], 'error' => 'Ingresa usuario y contrasena para consultar EMACH.'];
  }

  $cookieFile = tempnam(sys_get_temp_dir(), 'emach-cookie-');
  if (!is_string($cookieFile) || $cookieFile === '') {
    return ['ok' => false, 'url' => $url, 'rows' => [], 'error' => 'No se pudo crear la sesion temporal.'];
  }

  emach_prime_session($cookieFile, $username, $password);
  $responses = [];
  foreach (emach_planilla_urls($year, $month) as $candidateUrl) {
    $referer = str_contains($candidateUrl, '/reportes/')
      ? 'http://10.6.206.19/index.php/reportes/planilla'
      : 'http://10.6.206.19/index.php/autoconsulta/marcas/';
    $response = emach_curl_request($candidateUrl, $cookieFile, '', '', [
      'headers' => [
        'Accept: application/json, text/javascript, */*; q=0.01',
        'X-Requested-With: XMLHttpRequest',
      ],
      'referer' => $referer,
    ]);
    $response['requested_url'] = $candidateUrl;
    $responses[] = $response;

    $payload = json_decode((string) $response['body'], true);
    if (is_array($payload) && is_array($payload['data'] ?? null)) {
      @unlink($cookieFile);
      $rows = [];
      foreach ($payload['data'] as $row) {
        if (!is_array($row)) {
          continue;
        }
        $rows[] = array_pad(array_values($row), 10, '');
      }
      return ['ok' => true, 'url' => $candidateUrl, 'rows' => $rows, 'error' => ''];
    }
  }
  @unlink($cookieFile);

  $response = end($responses);
  if (!is_array($response)) {
    return ['ok' => false, 'url' => $url, 'rows' => [], 'error' => 'No se recibio respuesta desde EMACH.'];
  }
  $url = (string) ($response['requested_url'] ?? $url);
  $body = $response['body'];
  $httpCode = $response['http_code'];
  $curlError = $response['error'];

  if ($body === false || $curlError !== '') {
    return ['ok' => false, 'url' => $url, 'rows' => [], 'error' => $curlError !== '' ? $curlError : 'No se pudo leer la respuesta.'];
  }

  if ($httpCode < 200 || $httpCode >= 300) {
    return ['ok' => false, 'url' => $url, 'rows' => [], 'error' => 'HTTP ' . $httpCode];
  }

  $bodyStart = strtolower(substr(trim((string) $body), 0, 200));
  $contentType = (string) ($response['content_type'] ?? '');
  if (str_contains($bodyStart, '<html') || str_contains($bodyStart, '<!doctype') || str_contains(strtolower($contentType), 'text/html')) {
    return [
      'ok' => false,
      'url' => $url,
      'rows' => [],
      'error' => 'EMACH devolvio HTML en vez de JSON. Probablemente falta completar el login del servidor o existe un token/captcha adicional. HTTP ' . $httpCode,
      'diagnostics' => emach_html_diagnostics((string) $body, $response),
    ];
  }
  return [
    'ok' => false,
    'url' => $url,
    'rows' => [],
    'error' => 'Respuesta JSON invalida desde EMACH. HTTP ' . $httpCode,
    'diagnostics' => [
      'effective_url' => (string) ($response['effective_url'] ?? ''),
      'content_type' => $contentType,
    ],
  ];
}

$planilla = $submitted
  ? emach_fetch_planilla($selectedYear, $selectedMonth, $emachUsername, $emachPassword)
  : ['ok' => false, 'url' => emach_planilla_url($selectedYear, $selectedMonth), 'rows' => [], 'error' => ''];
$rows = $planilla['rows'];
$employeeCount = count(array_unique(array_filter(array_map(static fn($row): string => (string) ($row[1] ?? ''), $rows))));
$entryCount = count(array_filter($rows, static fn($row): bool => strtoupper((string) ($row[5] ?? '')) === 'ENTRADA'));
$exitCount = count(array_filter($rows, static fn($row): bool => strtoupper((string) ($row[5] ?? '')) === 'SALIDA'));
$clockCount = count(array_unique(array_filter(array_map(static fn($row): string => (string) ($row[6] ?? ''), $rows))));

?>
<!doctype html>
<html lang="es">
<head>
  <?php include __DIR__ . '/views/partials/bootstrap-head.php'; ?>
</head>
<body class="emach-page">
  <?php include __DIR__ . '/views/partials/navbar.php'; ?>

  <main class="container-fluid py-4">
    <section class="card card-hero sb-page-hero emach-hero mb-4">
      <div class="card-body p-4 d-flex align-items-center justify-content-between gap-3 flex-wrap">
        <div class="d-flex align-items-center gap-3">
          <span class="emach-hero-icon"><i class="bi bi-heart-pulse"></i></span>
          <div>
            <h1 class="h3 mb-1 text-white fw-black">EMACH</h1>
            <p class="mb-0 text-white-50 fw-semibold">Marcaciones del reloj control integradas a NOVA.</p>
          </div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <span class="emach-status-pill"><i class="bi bi-calendar3"></i><?= $h($selectedMonthName) ?> <?= $h($selectedYear) ?></span>
          <span class="emach-status-pill <?= $hasSavedEmachCredentials ? 'is-emach-credentials-ok' : 'is-emach-credentials-missing' ?>">
            <i class="bi <?= $hasSavedEmachCredentials ? 'bi-key-fill' : 'bi-key' ?>"></i><?= $hasSavedEmachCredentials ? 'Credenciales guardadas' : 'Sin credenciales guardadas' ?>
          </span>
          <span class="emach-status-pill"><i class="bi bi-router"></i>Servidor 10.6.206.19</span>
        </div>
      </div>
    </section>

    <section class="card emach-card mb-4">
      <div class="card-body p-4">
        <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
          <div>
            <h2 class="h5 fw-black mb-1">Consulta de marcaciones</h2>
            <p class="text-muted fw-semibold mb-0">Datos obtenidos desde autoconsulta/getplanilla.</p>
          </div>
          <a class="btn btn-outline-primary" href="http://10.6.206.19/index.php/autoconsulta/marcas/" target="_blank" rel="noopener">
            <i class="bi bi-box-arrow-up-right"></i>Abrir EMACH
          </a>
        </div>
        <?php if ($credentialMessage !== ''): ?>
          <div class="alert <?= str_starts_with($credentialMessage, 'No se') ? 'alert-warning' : 'emach-success-alert' ?> fw-semibold" role="status">
            <i class="bi <?= str_starts_with($credentialMessage, 'No se') ? 'bi-exclamation-triangle' : 'bi-check-circle' ?>"></i>
            <?= $h($credentialMessage) ?>
          </div>
        <?php endif; ?>
        <form class="row g-3 align-items-end" method="post" action="<?= $h(function_exists('url') ? url('/emach/index.php') : '/emach/index.php') ?>" data-emach-query-form data-has-saved-credentials="<?= $hasSavedEmachCredentials ? '1' : '0' ?>">
          <?php if (function_exists('csrf_token')): ?>
            <input type="hidden" name="_token" value="<?= $h(csrf_token()) ?>">
          <?php endif; ?>
          <input type="hidden" name="emach_usuario" value="<?= $h($emachUsername) ?>" data-emach-hidden-user>
          <input type="hidden" name="emach_password" value="" data-emach-hidden-password>
          <input type="hidden" name="remember_emach_credentials" value="0" data-emach-hidden-remember>
          <div class="col-12 col-md-2">
            <label class="form-label fw-bold" for="emach-ano">Año</label>
            <input class="form-control" id="emach-ano" name="ano" type="number" min="2020" max="2100" value="<?= $h($selectedYear) ?>">
          </div>
          <div class="col-12 col-md-2">
            <label class="form-label fw-bold" for="emach-mes">Mes</label>
            <select class="form-select" id="emach-mes" name="mes">
              <?php for ($month = 1; $month <= 12; $month++): ?>
                <option value="<?= $month ?>" <?= $month === $selectedMonth ? 'selected' : '' ?>><?= $h($monthNames[$month]) ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-12 col-md-5">
            <label class="form-label fw-bold">Credenciales EMACH</label>
            <div class="emach-credential-status <?= $hasSavedEmachCredentials ? 'is-ok' : 'is-missing' ?>">
              <i class="bi <?= $hasSavedEmachCredentials ? 'bi-shield-check' : 'bi-shield-exclamation' ?>"></i>
              <span><?= $hasSavedEmachCredentials ? 'Se usaran las credenciales guardadas para tu usuario.' : 'Se solicitaran al consultar.' ?></span>
            </div>
          </div>
          <div class="col-12 col-md-3">
            <button class="btn btn-primary w-100 emach-submit-button" type="submit"><i class="bi bi-arrow-repeat"></i>Consultar</button>
          </div>
          <div class="col-12">
            <div class="emach-endpoint text-truncate" title="<?= $h($planilla['url']) ?>">
              <?= $h($planilla['url']) ?>
            </div>
            <div class="form-text fw-semibold">Puedes usar las credenciales guardadas o ingresarlas al consultar. Si marcas recordar, quedan asociadas a tu usuario NOVA.</div>
          </div>
        </form>
      </div>
    </section>

    <?php if ($submitted && !$planilla['ok']): ?>
      <div class="alert alert-warning fw-semibold" role="alert">
        <i class="bi bi-exclamation-triangle"></i>
        No se pudo consultar EMACH: <?= $h($planilla['error']) ?>
        <?php $diagnostics = is_array($planilla['diagnostics'] ?? null) ? $planilla['diagnostics'] : []; ?>
        <?php if (!empty($diagnostics)): ?>
          <details class="mt-3">
            <summary>Ver diagnostico de respuesta EMACH</summary>
            <div class="mt-2 small">
              <?php if (!empty($diagnostics['effective_url'])): ?>
                <div><strong>URL final:</strong> <?= $h($diagnostics['effective_url']) ?></div>
              <?php endif; ?>
              <?php if (!empty($diagnostics['content_type'])): ?>
                <div><strong>Contenido:</strong> <?= $h($diagnostics['content_type']) ?></div>
              <?php endif; ?>
              <?php if (!empty($diagnostics['title'])): ?>
                <div><strong>Titulo HTML:</strong> <?= $h($diagnostics['title']) ?></div>
              <?php endif; ?>
              <?php if (!empty($diagnostics['forms']) && is_array($diagnostics['forms'])): ?>
                <div class="mt-2"><strong>Formularios detectados:</strong></div>
                <?php foreach ($diagnostics['forms'] as $formIndex => $form): ?>
                  <div class="border rounded p-2 mt-2 bg-light">
                    <div><strong>#<?= $h($formIndex + 1) ?></strong> id=<?= $h($form['id'] ?? '-') ?> method=<?= $h($form['method'] ?? '-') ?></div>
                    <div>action=<?= $h($form['action'] ?? '-') ?></div>
                    <?php if (!empty($form['inputs']) && is_array($form['inputs'])): ?>
                      <div>campos:
                        <?= $h(implode(', ', array_map(static fn($input): string => (string) ($input['name'] ?? '') . ':' . (string) ($input['type'] ?? 'text'), $form['inputs']))) ?>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </details>
        <?php endif; ?>
      </div>
    <?php elseif (!$submitted): ?>
      <div class="alert alert-info fw-semibold" role="status">
        <i class="bi bi-shield-lock"></i>
        Ingresa usuario y contrasena EMACH para cargar las marcaciones.
      </div>
    <?php elseif ($submitted && $planilla['ok']): ?>
      <div class="alert emach-success-alert fw-semibold" role="status">
        <i class="bi bi-check-circle"></i>
        Consulta cargada: <?= $h(count($rows)) ?> marcacion(es) de <?= $h($selectedMonthName) ?> <?= $h($selectedYear) ?>.
      </div>
    <?php endif; ?>

    <section class="row g-3 mb-4">
      <div class="col-12 col-md-6 col-xl-3">
        <article class="emach-stat-card">
          <span class="emach-stat-icon"><i class="bi bi-list-check"></i></span>
          <div><strong><?= count($rows) ?></strong><span>Total de marcaciones</span></div>
        </article>
      </div>
      <div class="col-12 col-md-6 col-xl-3">
        <article class="emach-stat-card">
          <span class="emach-stat-icon is-success"><i class="bi bi-people"></i></span>
          <div><strong><?= $employeeCount ?></strong><span>Personas registradas</span></div>
        </article>
      </div>
      <div class="col-12 col-md-6 col-xl-3">
        <article class="emach-stat-card">
          <span class="emach-stat-icon is-entry"><i class="bi bi-box-arrow-in-right"></i></span>
          <div><strong><?= $entryCount ?></strong><span>Entradas</span></div>
        </article>
      </div>
      <div class="col-12 col-md-6 col-xl-3">
        <article class="emach-stat-card">
          <span class="emach-stat-icon is-exit"><i class="bi bi-box-arrow-right"></i></span>
          <div><strong><?= $exitCount ?></strong><span>Salidas</span></div>
        </article>
      </div>
    </section>

    <section class="card emach-card">
      <div class="emach-table-head">
        <div>
          <h2 class="h5 fw-black mb-1">Planilla de <?= $h($selectedMonthName) ?> <?= $h($selectedYear) ?></h2>
          <p class="text-muted fw-semibold mb-0"><?= $clockCount ?> reloj(es) con registros.</p>
        </div>
        <span class="emach-count-pill"><i class="bi bi-table"></i><?= $h(count($rows)) ?> filas</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle emach-table mb-0">
          <thead>
            <tr>
              <?php foreach ($columns as $column): ?>
                <th><?= $h($column) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr>
                <td colspan="<?= count($columns) ?>">
                  <div class="emach-empty-state">
                    <i class="bi bi-calendar-x"></i>
                    <strong>Sin marcaciones para <?= $h($selectedMonthName) ?> <?= $h($selectedYear) ?></strong>
                    <span>Prueba otro mes o revisa la consulta directamente en EMACH.</span>
                  </div>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <?php $rowType = strtoupper((string) ($row[5] ?? '')); ?>
                <tr class="<?= $rowType === 'ENTRADA' ? 'is-entry-row' : ($rowType === 'SALIDA' ? 'is-exit-row' : '') ?>">
                  <?php foreach ($row as $index => $value): ?>
                    <?php if ($index === 5): ?>
                      <?php $type = strtoupper((string) $value); ?>
                      <td><span class="emach-type-badge <?= $type === 'ENTRADA' ? 'is-entry' : 'is-exit' ?>"><?= $h($value) ?></span></td>
                    <?php elseif ($index === 2): ?>
                      <td><span class="emach-person-name"><?= $h($value === null || $value === '' ? '-' : $value) ?></span></td>
                    <?php elseif ($index === 4): ?>
                      <td><span class="emach-time-chip"><i class="bi bi-clock"></i><?= $h($value === null || $value === '' ? '-' : $value) ?></span></td>
                    <?php else: ?>
                      <td><?= $h($value === null || $value === '' ? '-' : $value) ?></td>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>

  <div class="modal fade" id="emachCredentialsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content emach-credential-modal">
        <div class="modal-header">
          <div>
            <div class="emach-modal-kicker">Credenciales requeridas</div>
            <h5 class="modal-title">Consultar EMACH</h5>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-bold" for="modal-emach-user">Usuario EMACH</label>
            <input class="form-control" id="modal-emach-user" type="text" autocomplete="username" value="<?= $h($emachUsername) ?>" data-emach-modal-user>
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold" for="modal-emach-password">Contrasena EMACH</label>
            <input class="form-control" id="modal-emach-password" type="password" autocomplete="current-password" data-emach-modal-password>
          </div>
          <label class="emach-remember-row">
            <input class="form-check-input" type="checkbox" value="1" data-emach-modal-remember>
            <span>
              <strong>Recordar credenciales</strong>
              <small>Se guardaran para tu usuario NOVA y se usaran en proximas consultas.</small>
            </span>
          </label>
          <div class="form-text text-danger fw-semibold mt-2" data-emach-modal-error></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-primary" data-emach-modal-submit><i class="bi bi-arrow-repeat"></i>Consultar</button>
        </div>
      </div>
    </div>
  </div>
  <script>
  window.addEventListener('load', () => {
    const form = document.querySelector('[data-emach-query-form]');
    const hiddenUser = document.querySelector('[data-emach-hidden-user]');
    const hiddenPassword = document.querySelector('[data-emach-hidden-password]');
    const hiddenRemember = document.querySelector('[data-emach-hidden-remember]');
    const modalEl = document.getElementById('emachCredentialsModal');
    const modal = window.bootstrap && modalEl ? new bootstrap.Modal(modalEl) : null;
    const modalUser = document.querySelector('[data-emach-modal-user]');
    const modalPassword = document.querySelector('[data-emach-modal-password]');
    const modalRemember = document.querySelector('[data-emach-modal-remember]');
    const modalError = document.querySelector('[data-emach-modal-error]');
    const modalSubmit = document.querySelector('[data-emach-modal-submit]');
    const hasSavedCredentials = form?.dataset.hasSavedCredentials === '1';
    let submitFromModal = false;

    form?.addEventListener('submit', (event) => {
      if (submitFromModal || hasSavedCredentials) {
        return;
      }
      event.preventDefault();
      if (modalError) modalError.textContent = '';
      if (modalUser && hiddenUser) modalUser.value = hiddenUser.value || modalUser.value || '';
      modal?.show();
      setTimeout(() => (modalUser?.value ? modalPassword : modalUser)?.focus(), 180);
    });

    const submitWithModalCredentials = () => {
      const user = (modalUser?.value || '').trim();
      const password = modalPassword?.value || '';
      if (!user || !password) {
        if (modalError) modalError.textContent = 'Completa usuario y contrasena EMACH.';
        return;
      }
      if (hiddenUser) hiddenUser.value = user;
      if (hiddenPassword) hiddenPassword.value = password;
      if (hiddenRemember) hiddenRemember.value = modalRemember?.checked ? '1' : '0';
      submitFromModal = true;
      modal?.hide();
      form?.submit();
    };

    modalSubmit?.addEventListener('click', submitWithModalCredentials);
    modalEl?.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' || event.shiftKey || event.ctrlKey || event.altKey || event.metaKey) return;
      event.preventDefault();
      submitWithModalCredentials();
    });
  });
  </script>
</body>
</html>
