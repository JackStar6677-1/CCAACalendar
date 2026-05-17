<?php
/**
 * Script para gatillar la reconstrucción del sitio estático desde Python.
 */
require_once __DIR__ . '/auth.php';

admin_bootstrap_session();
admin_require_site_admin();

header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("Método no permitido.");
}

if (!admin_validate_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
    http_response_code(403);
    die("Acceso denegado.");
}

$py_script = '/home/colegio/public_html/py/build.py';
$command = "python3 " . escapeshellarg($py_script);

echo "Iniciando build...\n";
echo "Comando: $command\n\n";

$output = shell_exec($command . " 2>&1");

if ($output === null) {
    echo "ERROR: No se pudo ejecutar el comando (shell_exec retornó null).";
} else {
    echo "SALIDA DEL GENERADOR:\n";
    echo "--------------------\n";
    echo $output;
    echo "\n--------------------\n";
    echo "¡Build completado!";
}
