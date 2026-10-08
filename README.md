# Feina Cerca · Barcelona

Aplicación de búsqueda de empleo con registro e inicio de sesión en PHP.

## Ejecutar en Windows

Haz doble clic en `start-server.bat` dentro de la carpeta del proyecto. Mantén abierta la ventana de terminal mientras uses la web y abre [http://127.0.0.1:4173](http://127.0.0.1:4173).

También puedes arrancarla desde PowerShell, situado en esta carpeta:

```powershell
php -S 127.0.0.1:4173 -t . router.php
```

## Cuentas y datos

El backend usa PHP nativo: `api.php` implementa registro, login, sesión, cierre de sesión y almacenamiento de datos. Las contraseñas se guardan con `password_hash`; las alertas y ofertas se asocian a cada cuenta en `data/users.json`, creado al registrar la primera cuenta y excluido de Git. El servidor integrado de PHP está pensado para desarrollo local.

## Avisos de nuevas ofertas por email

Las alertas guardadas pueden activar «Enviarme nuevas ofertas por correo». El backend compara cada oferta nueva con los filtros de zona, radio, palabras clave, jornada, modalidad y salario, agrupa coincidencias por alerta y registra los enlaces ya avisados para no repetirlos.

Para enviar correo configura estas variables de entorno en el proceso PHP (no las guardes en archivos del proyecto):

- `JOB_ALERT_SMTP_HOST`
- `JOB_ALERT_SMTP_PORT` (normalmente `587` con STARTTLS o `465` con SSL)
- `JOB_ALERT_SMTP_USER` y `JOB_ALERT_SMTP_PASS` si el proveedor requiere autenticación
- `JOB_ALERT_SMTP_ENCRYPTION` (`tls` o `ssl`)
- `JOB_ALERT_FROM_EMAIL` y, opcionalmente, `JOB_ALERT_FROM_NAME`

El envío se procesa al sincronizar el catálogo al abrir la aplicación con una cuenta iniciada. Para revisar de nuevo el catálogo diariamente, el Programador de tareas de Windows puede ejecutar `php scripts/send-job-alerts.php` una vez al día desde esta carpeta. Este script comprueba el catálogo ya sincronizado y reintenta los avisos pendientes; no obtiene ofertas nuevas de InfoJobs ni de otros portales. La actualización real del catálogo requiere una fuente/API autorizada y sus credenciales, que todavía no están configuradas.

