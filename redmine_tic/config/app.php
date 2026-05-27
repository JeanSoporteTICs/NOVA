<?php

$env = static function (string $key, $default = null) {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return $value === false || $value === null || $value === '' ? $default : $value;
};

return [
    'name' => $env('APP_NAME', 'Redmine'),
    'env' => $env('APP_ENV', 'production'),
    'debug' => filter_var($env('APP_DEBUG', '0'), FILTER_VALIDATE_BOOL),
    'base_url' => $env('APP_BASE_URL', function_exists('url') ? url('/redmine_tic') : '/redmine_tic'),
    'login_path' => '/login.php',
    'dashboard_path' => '/',
    'required_extensions' => ['json', 'curl', 'mbstring', 'openssl'],
];
