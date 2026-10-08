<?php
declare(strict_types=1);

session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const DATA_FILE = __DIR__ . '/data/users.json';
const JOBS_FILE = __DIR__ . '/data/jobs.json';
require_once __DIR__ . '/lib/email-notifications.php';

function respond(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function inputJson(): array {
    $raw = file_get_contents('php://input');
    if (strlen($raw) > 100000) respond(413, ['error' => 'La solicitud es demasiado grande.']);
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) respond(400, ['error' => 'JSON no válido.']);
    return $data;
}
function loadUsers(): array {
    if (!is_file(DATA_FILE)) return [];
    $data = json_decode((string)file_get_contents(DATA_FILE), true);
    return is_array($data) ? $data : [];
}
function saveUsers(array $users): void {
    $folder = dirname(DATA_FILE);
    if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) respond(500, ['error' => 'No se pudo preparar el almacenamiento.']);
    $handle = fopen(DATA_FILE, 'c+');
    if (!$handle) respond(500, ['error' => 'No se pudieron guardar los datos.']);
    flock($handle, LOCK_EX);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    @chmod(DATA_FILE, 0600);
}
function publicUser(string $id, array $user): array {
    return ['id' => $id, 'name' => $user['name'], 'email' => $user['email'], 'savedJobs' => $user['savedJobs'] ?? [], 'alerts' => $user['alerts'] ?? []];
}
function currentAccount(): ?array {
    $id = $_SESSION['user_id'] ?? null;
    $users = loadUsers();
    return is_string($id) && isset($users[$id]) ? [$id, $users[$id]] : null;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET' && $path === '/api/session') {
    $account = currentAccount();
    respond(200, ['user' => $account ? publicUser($account[0], $account[1]) : null, 'emailNotificationsReady' => jobMailConfigured()]);
}
if ($method === 'POST' && ($path === '/api/register' || $path === '/api/login')) {
    $input = inputJson();
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $password = (string)($input['password'] ?? '');
    $users = loadUsers();
    if ($path === '/api/register') {
$name = trim(substr((string)($input['name'] ?? ''), 0, 240));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) respond(400, ['error' => 'Indica tu nombre, un correo válido y una contraseña de al menos 8 caracteres.']);
        foreach ($users as $user) if (($user['email'] ?? '') === $email) respond(409, ['error' => 'Ya existe una cuenta con ese correo.']);
        $id = bin2hex(random_bytes(16));
        $users[$id] = ['name' => $name, 'email' => $email, 'passwordHash' => password_hash($password, PASSWORD_DEFAULT), 'savedJobs' => [], 'alerts' => []];
        saveUsers($users);
        session_regenerate_id(true);
        $_SESSION['user_id'] = $id;
        respond(201, ['user' => publicUser($id, $users[$id])]);
    }
    $matchId = null;
    foreach ($users as $id => $user) if (($user['email'] ?? '') === $email) { $matchId = (string)$id; break; }
    if ($matchId === null || !password_verify($password, $users[$matchId]['passwordHash'] ?? '')) respond(401, ['error' => 'Correo o contraseña incorrectos.']);
    session_regenerate_id(true);
    $_SESSION['user_id'] = $matchId;
    respond(200, ['user' => publicUser($matchId, $users[$matchId])]);
}
$account = currentAccount();
if (!$account) respond(401, ['error' => 'Inicia sesión para continuar.']);
if ($method === 'POST' && $path === '/api/jobs/sync') {
    $input = inputJson();
    $rawJobs = is_array($input['jobs'] ?? null) ? array_slice($input['jobs'], 0, 500) : [];
    $jobs = [];
    $cutoff = new DateTimeImmutable('-60 days');
    $today = new DateTimeImmutable('today');
    foreach ($rawJobs as $job) {
        if (!is_array($job)) continue;
        $url = trim((string)($job['url'] ?? ''));
        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        $trustedHost = $host === 'infojobs.net' || str_ends_with($host, '.infojobs.net') || $host === 'talentclue.com' || str_ends_with($host, '.talentclue.com');
        if (($parts['scheme'] ?? '') !== 'https' || !$trustedHost) continue;
        $date = DateTimeImmutable::createFromFormat('!d/m/Y', (string)($job['when'] ?? ''));
        if (!$date || $date < $cutoff || $date > $today) continue;
        $jobs[$url] = [
            'title' => trim(substr(strip_tags((string)($job['title'] ?? '')), 0, 180)),
            'company' => trim(substr(strip_tags((string)($job['company'] ?? '')), 0, 180)),
            'area' => trim(substr(strip_tags((string)($job['area'] ?? '')), 0, 100)),
            'distance' => max(0, (float)($job['distance'] ?? 0)),
            'kind' => trim(substr((string)($job['kind'] ?? ''), 0, 40)),
            'mode' => trim(substr((string)($job['mode'] ?? ''), 0, 40)),
            'salary' => max(0, (float)($job['salary'] ?? 0)),
            'when' => (string)$job['when'],
            'url' => $url,
            'desc' => trim(substr(strip_tags((string)($job['desc'] ?? '')), 0, 1000)),
            'lat' => (float)($job['lat'] ?? 0),
            'lng' => (float)($job['lng'] ?? 0),
        ];
    }
    $folder = dirname(JOBS_FILE);
    if (!is_dir($folder)) mkdir($folder, 0700, true);
    file_put_contents(JOBS_FILE, json_encode(array_values($jobs), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod(JOBS_FILE, 0600);
    $users = loadUsers();
    $delivery = processJobAlertNotifications($users, array_values($jobs));
    if ($delivery['sent'] > 0) saveUsers($users);
    respond(200, ['ok' => true, 'jobs' => count($jobs), 'emailNotificationsReady' => $delivery['configured'], 'sent' => $delivery['sent'], 'pending' => $delivery['pending']]);
}
if ($method === 'PUT' && $path === '/api/account/data') {
    $input = inputJson();
    $users = loadUsers();
    $id = $account[0];
$savedInput = is_array($input['savedJobs'] ?? null) ? $input['savedJobs'] : [];
$alertsInput = is_array($input['alerts'] ?? null) ? $input['alerts'] : [];
$users[$id]['savedJobs'] = array_values(array_slice(array_filter($savedInput, 'is_string'), 0, 500));
$existingAlerts = [];
foreach (($users[$id]['alerts'] ?? []) as $oldAlert) if (is_array($oldAlert) && isset($oldAlert['id'])) $existingAlerts[(string)$oldAlert['id']] = $oldAlert;
$cleanAlerts = [];
foreach (array_slice($alertsInput, 0, 200) as $alert) {
    if (!is_array($alert)) continue;
    $alertId = trim(substr((string)($alert['id'] ?? ''), 0, 80));
    if ($alertId === '') continue;
    $old = $existingAlerts[$alertId] ?? [];
    $enabled = !empty($alert['emailEnabled']);
    $knownIds = !empty($old['emailEnabled']) && $enabled
        ? ($old['notifiedJobIds'] ?? [])
        : (is_array($alert['notifiedJobIds'] ?? null) ? $alert['notifiedJobIds'] : []);
    $alert['id'] = $alertId;
    $alert['name'] = trim(substr((string)($alert['name'] ?? 'Alerta de empleo'), 0, 80));
    $alert['emailEnabled'] = $enabled;
    $alert['notifiedJobIds'] = array_values(array_slice(array_filter($knownIds, 'is_string'), 0, 500));
    $cleanAlerts[] = $alert;
}
$users[$id]['alerts'] = $cleanAlerts;
    saveUsers($users);
    respond(200, ['ok' => true]);
}
if ($method === 'POST' && $path === '/api/logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $params['path'], 'domain' => $params['domain'], 'secure' => $params['secure'], 'httponly' => $params['httponly'], 'samesite' => 'Lax']);
    }
    session_destroy();
    respond(200, ['ok' => true]);
}
respond(404, ['error' => 'Ruta no encontrada.']);


