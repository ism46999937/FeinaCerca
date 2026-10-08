<?php
declare(strict_types=1);

function jobMailConfig(): array {
    return [
        'host' => trim((string)getenv('JOB_ALERT_SMTP_HOST')),
        'port' => (int)(getenv('JOB_ALERT_SMTP_PORT') ?: 587),
        'user' => (string)getenv('JOB_ALERT_SMTP_USER'),
        'pass' => (string)getenv('JOB_ALERT_SMTP_PASS'),
        'encryption' => strtolower(trim((string)(getenv('JOB_ALERT_SMTP_ENCRYPTION') ?: 'tls'))),
        'from' => trim((string)getenv('JOB_ALERT_FROM_EMAIL')),
        'fromName' => trim((string)(getenv('JOB_ALERT_FROM_NAME') ?: 'Feina Cerca')),
    ];
}

function jobMailConfigured(): bool {
    $config = jobMailConfig();
    return $config['host'] !== '' && $config['port'] > 0 && $config['from'] !== '' && filter_var($config['from'], FILTER_VALIDATE_EMAIL) !== false;
}

function smtpReadResponse($socket): string {
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) < 4 || $line[3] !== '-') break;
    }
    if ($response === '') throw new RuntimeException('Sin respuesta SMTP');
    return $response;
}

function smtpExpect($socket, array $accepted): string {
    $response = smtpReadResponse($socket);
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $accepted, true)) throw new RuntimeException('Respuesta SMTP inesperada: ' . $code);
    return $response;
}

function smtpCommand($socket, string $command, array $accepted): string {
    if (fwrite($socket, $command . "\r\n") === false) throw new RuntimeException('No se pudo escribir en SMTP');
    return smtpExpect($socket, $accepted);
}

function sendJobAlertMail(string $to, string $subject, string $html): bool {
    if (!jobMailConfigured() || filter_var($to, FILTER_VALIDATE_EMAIL) === false) return false;
    $config = jobMailConfig();
    $scheme = $config['encryption'] === 'ssl' ? 'ssl://' : 'tcp://';
    $errno = 0;
    $error = '';
    $socket = @stream_socket_client($scheme . $config['host'] . ':' . $config['port'], $errno, $error, 20, STREAM_CLIENT_CONNECT);
    if (!$socket) { error_log('Feina Cerca: no se pudo conectar con SMTP (' . $errno . ').'); return false; }
    stream_set_timeout($socket, 20);
    try {
        smtpExpect($socket, [220]);
        $hostName = (string)(gethostname() ?: 'localhost');
        smtpCommand($socket, 'EHLO ' . preg_replace('/[^A-Za-z0-9.-]/', '', $hostName), [250]);
        if ($config['encryption'] === 'tls') {
            smtpCommand($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('No se pudo iniciar TLS');
            smtpCommand($socket, 'EHLO ' . preg_replace('/[^A-Za-z0-9.-]/', '', $hostName), [250]);
        }
        if ($config['user'] !== '') {
            smtpCommand($socket, 'AUTH LOGIN', [334]);
            smtpCommand($socket, base64_encode($config['user']), [334]);
            smtpCommand($socket, base64_encode($config['pass']), [235]);
        }
        smtpCommand($socket, 'MAIL FROM:<' . $config['from'] . '>', [250]);
        smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        smtpCommand($socket, 'DATA', [354]);
        $encodedName = '=?UTF-8?B?' . base64_encode($config['fromName']) . '?=';
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $message = 'Date: ' . date(DATE_RFC2822) . "\r\n";
        $message .= 'From: ' . $encodedName . ' <' . $config['from'] . ">\r\n";
        $message .= 'To: <' . $to . ">\r\n";
        $message .= 'Subject: ' . $encodedSubject . "\r\n";
        $message .= "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($html), 76, "\r\n") . ".\r\n";
        if (fwrite($socket, $message) === false) throw new RuntimeException('No se pudo enviar el mensaje');
        smtpExpect($socket, [250]);
        @fwrite($socket, "QUIT\r\n");
        return true;
    } catch (Throwable $error) {
        error_log('Feina Cerca: envío SMTP fallido (' . get_class($error) . ').');
        return false;
    } finally {
        fclose($socket);
    }
}

function jobAlertCoordinates(string $area): ?array {
    $coordinates = [
        'Barcelona' => [41.3874, 2.1686], 'L\'Hospitalet de Llobregat' => [41.3597, 2.1003],
        'Badalona' => [41.45, 2.247], 'Montcada i Reixac' => [41.483, 2.189],
        'Sabadell' => [41.548, 2.108], 'Terrassa' => [41.563, 2.009], 'Sant Cugat del Vallès' => [41.472, 2.086],
        'Mataró' => [41.539, 2.445], 'Teià' => [41.498, 2.321], 'Cassà de la Selva' => [41.887, 2.875],
        'Riudellots de la Selva' => [41.893, 2.804], 'Olot' => [42.181, 2.488], 'Girona' => [41.979, 2.821],
        'Tarragona' => [41.119, 1.245], 'Alcover' => [41.263, 1.172], 'Reus' => [41.154, 1.109],
        'Maspujols' => [41.183, 1.045], 'Cambrils' => [41.067, 1.06], 'Alcoletge' => [41.647, .69],
        'Bell-Lloc d\'Urgell' => [41.63, .78], 'Tàrrega' => [41.647, 1.14], 'Lleida' => [41.617, .62],
    ];
    return $coordinates[$area] ?? null;
}

function jobAlertDistanceKm(array $origin, array $job): float {
    if (!isset($job['lat'], $job['lng'])) return INF;
    $radians = static fn(float $value): float => $value * M_PI / 180;
    $latDelta = $radians((float)$job['lat'] - $origin[0]);
    $lngDelta = $radians((float)$job['lng'] - $origin[1]);
    $a = sin($latDelta / 2) ** 2 + cos($radians($origin[0])) * cos($radians((float)$job['lat'])) * sin($lngDelta / 2) ** 2;
    return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function jobMatchesSavedAlert(array $job, array $alert): bool {
    $keyword = mb_strtolower(trim((string)($alert['keyword'] ?? '')), 'UTF-8');
    $haystack = mb_strtolower(implode(' ', [(string)($job['title'] ?? ''), (string)($job['company'] ?? ''), (string)($job['desc'] ?? '')]), 'UTF-8');
    if ($keyword !== '' && !str_contains($haystack, $keyword)) return false;
    $area = trim((string)($alert['area'] ?? ''));
    $radius = trim((string)($alert['radius'] ?? ''));
    if ($area !== '') {
        if ($radius === '') { if (($job['area'] ?? '') !== $area) return false; }
        else {
            $origin = jobAlertCoordinates($area);
            if ($origin === null || jobAlertDistanceKm($origin, $job) > (float)$radius) return false;
        }
    } elseif ($radius !== '' && (float)($job['distance'] ?? INF) > (float)$radius) return false;
    if ((float)($job['salary'] ?? 0) < (float)($alert['salary'] ?? 0)) return false;
    if (!empty($alert['kind']) && ($job['kind'] ?? '') !== $alert['kind']) return false;
    if (!empty($alert['mode']) && ($job['mode'] ?? '') !== $alert['mode']) return false;
    return true;
}

function processJobAlertNotifications(array &$users, array $jobs): array {
    $result = ['configured' => jobMailConfigured(), 'sent' => 0, 'pending' => 0];
    if (!$result['configured']) return $result;
    foreach ($users as &$user) {
        $email = (string)($user['email'] ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || !is_array($user['alerts'] ?? null)) continue;
        foreach ($user['alerts'] as &$alert) {
            if (empty($alert['emailEnabled'])) continue;
            $known = array_fill_keys(array_map('strval', is_array($alert['notifiedJobIds'] ?? null) ? $alert['notifiedJobIds'] : []), true);
            $newMatches = [];
            foreach ($jobs as $job) {
                $url = (string)($job['url'] ?? '');
                if ($url !== '' && !isset($known[$url]) && jobMatchesSavedAlert($job, $alert)) $newMatches[] = $job;
            }
            if (!$newMatches) continue;
            $name = htmlspecialchars((string)($user['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = '<!doctype html><html lang="es"><meta charset="utf-8"><body style="font-family:Arial,sans-serif;color:#262033;line-height:1.5"><h2>Nuevas ofertas para tu alerta</h2><p>Hola ' . $name . ', encontramos ' . count($newMatches) . ' oferta(s) para <strong>' . htmlspecialchars((string)($alert['name'] ?? 'tu alerta'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>.</p><ul>';
            foreach ($newMatches as $job) {
                $title = htmlspecialchars((string)($job['title'] ?? 'Oferta de empleo'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $company = htmlspecialchars((string)($job['company'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $area = htmlspecialchars((string)($job['area'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $url = htmlspecialchars((string)($job['url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $html .= '<li style="margin-bottom:14px"><a href="' . $url . '"><strong>' . $title . '</strong></a><br>' . $company . ' · ' . $area . '</li>';
            }
            $html .= '</ul><p>Gestiona tus filtros desde Mis alertas en Feina Cerca.</p></body></html>';
            if (sendJobAlertMail($email, 'Nuevas ofertas para ' . (string)($alert['name'] ?? 'tu alerta'), $html)) {
                $alert['notifiedJobIds'] = array_values(array_unique(array_merge(array_keys($known), array_map(static fn(array $job): string => (string)$job['url'], $newMatches))));
                $result['sent']++;
            } else $result['pending']++;
        }
        unset($alert);
    }
    unset($user);
    return $result;
}

