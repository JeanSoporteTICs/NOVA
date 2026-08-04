<?php

namespace App\Modulos\RedmineMantencion\Services;

use App\Modulos\RedmineMantencion\Repositories\MantencionCatalogRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionConfigRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionReportRepository;

final class MantencionCoreImportService
{
    public function __construct(
        private readonly MantencionConfigRepository $config,
        private readonly MantencionCatalogRepository $catalogs,
        private readonly MantencionReportRepository $reports,
        private readonly CorePendingReportSyncService $pendingSync,
    ) {}

    /** @param array<string,mixed> $context @return array{ok:bool,imported:int,updated:int,error:string} */
    public function import(string $user, string $password, string $from, string $to, array $context): array
    {
        $config = $this->config->loadAll() ?? [];
        if (empty($config['core_enabled'])) {
            return $this->failure('La integración CORE está deshabilitada.');
        }
        if (trim($user) === '' || trim($password) === '') {
            return $this->failure('Debes ingresar tus credenciales CORE.');
        }
        $loginUrl = trim((string) ($config['core_admin_url'] ?? ''));
        $sourceUrl = trim((string) ($config['core_historico_url'] ?? '')) ?: $loginUrl;
        if ($loginUrl === '' || $sourceUrl === '') {
            return $this->failure('Falta configurar la URL de CORE.');
        }

        $cookie = tempnam(sys_get_temp_dir(), 'nova_core_');
        if ($cookie === false) {
            return $this->failure('No se pudo iniciar una sesión temporal CORE.');
        }
        try {
            $loginPage = $this->request($loginUrl, $cookie);
            if (! $loginPage['ok']) {
                return $this->failure('No se pudo abrir CORE: '.$loginPage['error']);
            }
            $form = $this->loginForm($loginPage['body'], $loginPage['url'] ?: $loginUrl);
            if ($form === null) {
                return $this->failure('No se encontró el formulario de acceso de CORE.');
            }
            $fields = $form['fields'] + ['login_string' => $user, 'login_pass' => $password, 'submit' => 'Ingresar'];
            $fields['login_string'] = $user;
            $fields['login_pass'] = $password;
            $login = $this->request($form['action'], $cookie, $fields);
            if (! $login['ok'] || $this->looksLikeLogin($login['body'])) {
                return $this->failure('CORE rechazó las credenciales ingresadas.');
            }

            $filters = array_filter(['desde' => trim($from), 'hasta' => trim($to), 'fecha_desde' => trim($from), 'fecha_hasta' => trim($to)]);
            $separator = str_contains($sourceUrl, '?') ? '&' : '?';
            $response = $this->request($sourceUrl.($filters ? $separator.http_build_query($filters) : ''), $cookie);
            if (! $response['ok']) {
                return $this->failure('No se pudo consultar CORE: '.$response['error']);
            }
            $rows = $this->rows($response['body']);
            if ($rows === []) {
                return $this->failure('CORE no devolvió solicitudes para los filtros indicados.');
            }

            $active = $this->reports->activeMessages();
            $indexes = $this->pendingSync->indexes($active);
            $existingCore = $this->reports->getExistingCoreIds();
            $imported = $updated = 0;
            foreach ($rows as $row) {
                $message = $this->message($row, $context);
                if ($message === null || ! $this->inRange($message, $from, $to) || ! $this->assignedToViewer($message, $context)) {
                    continue;
                }
                $match = $this->pendingSync->matchIndex($indexes, $message);
                if ($match !== null) {
                    $merge = $this->pendingSync->mergePending($active[$match], $message);
                    if ($merge['eligible'] && $merge['changed'] && $this->reports->upsertMessage($merge['message'], $config)) {
                        $active[$match] = $merge['message'];
                        $updated++;
                    }

                    continue;
                }
                $coreId = $this->pendingSync->coreId($message);
                if ($coreId !== '' && isset($existingCore[$coreId])) {
                    continue;
                }
                if ($this->reports->upsertMessage($message, $config)) {
                    $active[] = $message;
                    $indexes = $this->pendingSync->indexes($active);
                    $existingCore[$coreId] = true;
                    $imported++;
                }
            }
            $config['core_last_sync'] = now()->toAtomString();
            $config['core_last_error'] = '';
            $this->config->saveAll($config);

            return ['ok' => true, 'imported' => $imported, 'updated' => $updated, 'error' => ''];
        } finally {
            @unlink($cookie);
        }
    }

    /** @return array{ok:bool,body:string,url:string,error:string} */
    private function request(string $url, string $cookie, ?array $post = null): array
    {
        if (! function_exists('curl_init')) {
            return ['ok' => false, 'body' => '', 'url' => $url, 'error' => 'cURL no disponible.'];
        }
        $ch = curl_init($url);
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_COOKIEJAR => $cookie, CURLOPT_COOKIEFILE => $cookie, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 35, CURLOPT_USERAGENT => 'NOVA Mantencion CORE/2.0'];
        if ($post !== null) {
            $options += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post), CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'X-Requested-With: XMLHttpRequest']];
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effective = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = trim((string) curl_error($ch));
        curl_close($ch);

        return ['ok' => $body !== false && $error === '' && $http >= 200 && $http < 400, 'body' => (string) $body, 'url' => $effective, 'error' => $error !== '' ? $error : 'HTTP '.$http];
    }

    /** @return array{action:string,fields:array<string,string>}|null */
    private function loginForm(string $html, string $base): ?array
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//form') ?: [] as $form) {
            $fields = [];
            foreach ($xpath->query('.//input[@name]', $form) ?: [] as $input) {
                $fields[$input->getAttribute('name')] = $input->getAttribute('value');
            }
            if (! array_key_exists('login_string', $fields) || ! array_key_exists('login_pass', $fields)) {
                continue;
            }
            $action = trim($form->getAttribute('action'));
            if ($action === '') {
                $action = $base;
            } elseif (! preg_match('#^https?://#i', $action)) {
                $parts = parse_url($base);
                $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
                $action = str_starts_with($action, '/') ? $origin.$action : rtrim(dirname($base), '/').'/'.ltrim($action, '/');
            }

            return ['action' => $action, 'fields' => $fields];
        }

        return null;
    }

    private function looksLikeLogin(string $body): bool
    {
        return str_contains($body, 'name="login_string"') && str_contains($body, 'name="login_pass"');
    }

    /** @return array<int,array<string,string>> */
    private function rows(string $body): array
    {
        $json = json_decode($body, true);
        if (is_array($json)) {
            $items = $this->jsonItems($json);
            $rows = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $rows[] = $this->canonicalRow($item);
            }

            return array_values(array_filter($rows, fn ($row) => $row['solicitante'] !== '' || $row['tipo'] !== ''));
        }
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($body);
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($dom);
        $rows = [];
        foreach ($xpath->query('//table') ?: [] as $table) {
            $headers = [];
            foreach ($xpath->query('.//th', $table) ?: [] as $header) {
                $headers[] = $this->normalize($header->textContent);
            }
            if (! in_array('solicitante', $headers, true)) {
                continue;
            }
            foreach ($xpath->query('.//tr[td]', $table) ?: [] as $tr) {
                $raw = [];
                foreach ($xpath->query('./td', $tr) ?: [] as $i => $cell) {
                    $raw[$headers[$i] ?? ('col_'.$i)] = trim($cell->textContent);
                }
                $links = $xpath->query('.//a[contains(@href,"solicitud") or contains(@href,"detalle")]', $tr);
                if ($links && $links->length) {
                    preg_match('/\d+/', $links->item(0)->getAttribute('href'), $m);
                    $raw['id'] = $m[0] ?? '';
                }
                $rows[] = $this->canonicalRow($raw);
            }
            break;
        }

        return $rows;
    }

    /** @return array<int,mixed> */
    private function jsonItems(array $payload): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }
        foreach (['data', 'rows', 'items', 'solicitudes', 'result', 'results', 'records', 'aaData'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $this->jsonItems($payload[$key]);
            }
        }

        return [$payload];
    }

    /** @param array<string,mixed> $raw @return array<string,string> */
    private function canonicalRow(array $raw): array
    {
        $flat = [];
        $walk = function (array $values) use (&$walk, &$flat): void {
            foreach ($values as $key => $value) {
                if (is_array($value)) {
                    $walk($value);
                } elseif (! isset($flat[$this->normalize((string) $key)])) {
                    $flat[$this->normalize((string) $key)] = trim(strip_tags((string) $value));
                }
            }
        };
        $walk($raw);
        $pick = fn (array $keys): string => array_reduce($keys, fn ($carry, $key) => $carry !== '' ? $carry : (string) ($flat[$this->normalize($key)] ?? ''), '');

        return ['id' => $pick(['id solicitud core', 'id solicitud', 'id']), 'solicitante' => $pick(['solicitante', 'nombre solicitante']), 'fecha' => $pick(['fecha de creacion', 'fecha creacion', 'fec creacion']), 'tipo' => $pick(['tipo de solicitud', 'tipo solicitud', 'tipo sol']), 'establecimiento' => $pick(['establecimiento', 'estab']), 'departamento' => $pick(['departamento']), 'telefono' => $pick(['telefono', 'fono']), 'celular' => $pick(['celular']), 'email' => $pick(['email', 'correo']), 'estado' => $pick(['estado']), 'asignado' => $pick(['usuario asignado', 'usuario asignado nombre', 'asignado'])];
    }

    /** @param array<string,string> $row @param array<string,mixed> $context @return array<string,mixed>|null */
    private function message(array $row, array $context): ?array
    {
        if ($row['solicitante'] === '' && $row['tipo'] === '') {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($row['fecha'] ?: 'now');
        } catch (\Throwable) {
            $date = new \DateTimeImmutable;
        }
        $coreId = trim($row['id']);
        $source = $coreId !== '' ? 'core-id:'.substr($coreId, 0, 152) : sha1(implode('|', $row));
        $unit = $row['departamento'] !== '' && strtoupper($row['departamento']) !== 'N/A' ? $row['departamento'] : $row['establecimiento'];
        $category = $this->resolveCategory($row['tipo']);
        $description = implode("\n", array_filter(['Tipo de solicitud: '.$row['tipo'], 'Establecimiento: '.$row['establecimiento'], 'Departamento: '.$row['departamento'], $row['telefono'] !== '' ? 'Teléfono: '.$row['telefono'] : '', $row['celular'] !== '' ? 'Celular: '.$row['celular'] : '', $row['email'] !== '' ? 'Email: '.$row['email'] : '', $row['estado'] !== '' ? 'Estado CORE: '.$row['estado'] : '']));

        return [
            'id' => 'core-'.substr($source, 0, 20),
            'fuente' => 'core',
            'fuente_id' => $source,
            'id_core' => $coreId,
            'core_solicitud_id' => $coreId,
            'asunto' => trim($row['tipo'].' / '.$unit, ' /'),
            'mensaje' => $row['tipo'],
            'descripcion' => $description,
            'estado' => 'pendiente',
            'estado_redmine' => $row['estado'],
            'core_estado' => $row['estado'],
            'tipo' => 'Soporte',
            'prioridad' => 'NORMAL',
            'categoria' => $category,
            'unidad' => $unit,
            'solicitante' => $row['solicitante'],
            'anexo' => $row['celular'] ?: $row['telefono'],
            'correo' => $row['email'],
            'core_email' => $row['email'],
            'fecha' => $date->format('Y-m-d'),
            'hora' => $date->format('H:i'),
            'fecha_inicio' => $date->format('Y-m-d'),
            'fecha_fin' => $date->format('Y-m-d'),
            'asignado_a' => (string) ($context['viewer_id'] ?? ''),
            'asignado_nombre' => (string) ($context['viewer_name'] ?? ''),
            'core_usuario_asignado' => $row['asignado'],
            'hora_extra' => '0',
        ];
    }

    public function resolveCategory(string $type): string
    {
        return $this->resolveCategoryFromCatalog($type, $this->catalogs->categoriaNames());
    }

    /** @param array<int,string> $catalog */
    public function resolveCategoryFromCatalog(string $type, array $catalog): string
    {
        $needle = $this->normalize($type);
        if ($needle === '') {
            return 'Modificar Perfil CORE';
        }
        $needle = ['modificar usuario' => 'modificar perfil core', 'creacion usuario' => 'creacion de usuario'][$needle] ?? $needle;
        $best = '';
        $bestScore = 0.0;
        foreach ($catalog as $name) {
            $candidate = $this->normalize($name);
            if ($candidate === $needle) {
                return $name;
            }
            if ($candidate === '') {
                continue;
            }
            similar_text($needle, $candidate, $percent);
            $score = str_contains($needle, $candidate) || str_contains($candidate, $needle) ? 0.9 : $percent / 100;
            if ($score > $bestScore) {
                $best = $name;
                $bestScore = $score;
            }
        }

        return $best !== '' && $bestScore >= 0.45 ? $best : 'Modificar Perfil CORE';
    }

    /** @param array<string,mixed> $message */
    private function inRange(array $message, string $from, string $to): bool
    {
        $date = (string) $message['fecha'];

        return ! ($from !== '' && $date < $from) && ! ($to !== '' && $date > $to);
    }

    /** @param array<string,mixed> $message @param array<string,mixed> $context */
    private function assignedToViewer(array $message, array $context): bool
    {
        $assigned = trim((string) ($message['core_usuario_asignado'] ?? ''));
        if ($assigned === '') {
            return true;
        }
        $a = $this->normalize($assigned);
        foreach ([(string) ($context['viewer_core_user'] ?? ''), (string) ($context['viewer_name'] ?? '')] as $identity) {
            $v = $this->normalize($identity);
            if ($v !== '' && ($a === $v || str_contains($a, $v) || str_contains($v, $a))) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($value), 'UTF-8'));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', is_string($ascii) ? $ascii : $value) ?? '');
    }

    /** @return array{ok:false,imported:int,updated:int,error:string} */
    private function failure(string $error): array
    {
        try {
            $cfg = $this->config->loadAll() ?? [];
            $cfg['core_last_error'] = $error;
            $this->config->saveAll($cfg);
        } catch (\Throwable) {
        }

        return ['ok' => false, 'imported' => 0, 'updated' => 0, 'error' => $error];
    }
}
