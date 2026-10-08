<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/email-notifications.php';

$usersFile = __DIR__ . '/../data/users.json';
$jobsFile = __DIR__ . '/../data/jobs.json';
if (!jobMailConfigured()) {
    fwrite(STDERR, "SMTP no está configurado. Define JOB_ALERT_SMTP_* y vuelve a ejecutar.\n");
    exit(2);
}
if (!is_file($usersFile) || !is_file($jobsFile)) {
    fwrite(STDERR, "Faltan datos sincronizados de usuarios u ofertas. Abre la web con una sesión iniciada primero.\n");
    exit(3);
}
$users = json_decode((string)file_get_contents($usersFile), true);
$jobs = json_decode((string)file_get_contents($jobsFile), true);
if (!is_array($users) || !is_array($jobs)) {
    fwrite(STDERR, "No se pudieron leer los archivos de usuarios/ofertas.\n");
    exit(4);
}
$result = processJobAlertNotifications($users, $jobs);
if ($result['sent'] > 0) {
    $handle = fopen($usersFile, 'c+');
    if (!$handle) { fwrite(STDERR, "No se pudo abrir el fichero de usuarios para guardar avisos.\n"); exit(5); }
    flock($handle, LOCK_EX);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}
printf("Avisos enviados: %d; pendientes por error SMTP: %d\n", $result['sent'], $result['pending']);

