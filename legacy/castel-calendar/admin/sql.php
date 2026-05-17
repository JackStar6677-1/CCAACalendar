<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/calendar_store.php';

admin_bootstrap_session();
admin_require_maintenance_tools_enabled();

$message = '';
$error = '';
$backend = calendar_store_backend_mode();
$config = calendar_store_db_config();
$conn = calendar_store_db_connect();
$dbOk = $conn !== null;
$tables = calendar_store_mysql_full_tables();
$tableStatus = array();
$status = calendar_store_default();

if ($conn) {
    $probe = mysqli_query($conn, 'SELECT 1 AS ok');
    $dbOk = (bool) $probe;
    if ($probe) {
        mysqli_free_result($probe);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_validate_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        $error = 'La sesión expiró. Recarga la página e inténtalo otra vez.';
    } else {
        $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
        if ($action === 'prepare_mysql_full' || $action === 'seed_mysql_full' || $action === 'use_mysql_full') {
            if (!$conn) {
                $error = 'No se pudo conectar a MySQL con las credenciales del sitio.';
            } elseif (!calendar_store_mysql_full_ensure($conn)) {
                $error = 'No se pudieron crear o verificar las tablas MySQL.';
            } else {
                if ($action === 'seed_mysql_full') {
                    $store = calendar_store_read_all();
                    if (!calendar_store_mysql_full_write($conn, $store)) {
                        $error = 'No se pudo copiar el calendario actual a las tablas MySQL.';
                    } else {
                        $message = 'El calendario actual fue copiado a tablas MySQL.';
                    }
                } elseif ($action === 'use_mysql_full') {
                    $store = calendar_store_read_all();
                    if (!calendar_store_mysql_full_write($conn, $store)) {
                        $error = 'No se pudo copiar el calendario actual a las tablas MySQL.';
                    } else {
                        calendar_store_set_backend_mode('mysql_full');
                        $backend = 'mysql_full';
                        $message = 'Calendario activado en MySQL completo.';
                    }
                } else {
                    $message = 'Las tablas MySQL del calendario quedaron verificadas.';
                }
            }
        } elseif ($action === 'use_json') {
            calendar_store_set_backend_mode('json');
            $backend = 'json';
            $message = 'Calendario vuelto a backend JSON.';
        }
    }
}

if ($conn) {
    if (calendar_store_mysql_full_ensure($conn)) {
        $probe = calendar_store_mysql_full_read($conn);
        if (is_array($probe)) {
            $status = $probe;
        }
    }

    foreach ($tables as $key => $table) {
        $escapedTable = mysqli_real_escape_string($conn, $table);
        $result = mysqli_query($conn, "SHOW TABLES LIKE '" . $escapedTable . "'");
        $exists = $result && mysqli_num_rows($result) > 0;
        if ($result) {
            mysqli_free_result($result);
        }
        $count = null;
        if ($exists) {
            $countResult = mysqli_query($conn, 'SELECT COUNT(*) AS total FROM `' . $table . '`');
            if ($countResult) {
                $row = mysqli_fetch_assoc($countResult);
                $count = (int) ($row['total'] ?? 0);
                mysqli_free_result($countResult);
            }
        }
        $tableStatus[$key] = array('name' => $table, 'exists' => $exists, 'count' => $count);
    }

    @mysqli_close($conn);
}

$activeStore = calendar_store_read_all();
if (is_array($activeStore)) {
    $status = $activeStore;
}

$counts = array(
    'reservas por dia' => count($status['reservations'] ?? array()),
    'reservas por bloque' => count($status['block_reservations'] ?? array()),
    'solicitudes de cambio' => count($status['change_requests'] ?? array()),
    'solicitudes de cambio por bloque' => count($status['block_change_requests'] ?? array()),
    'cursos/listas' => count($status['course_rosters'] ?? array()),
    'incidencias' => count($status['incidences'] ?? array()),
    'auditoria' => count($status['audit_log'] ?? array()),
);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>MySQL del calendario | RoomKeeper</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 24px;
            background: #eef4f9;
            color: #17304b;
        }
        .wrap {
            max-width: 1080px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #d7e2ec;
            border-radius: 18px;
            box-shadow: 0 12px 32px rgba(44, 76, 116, 0.09);
            padding: 24px;
        }
        h1 { margin-top: 0; }
        .row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .card {
            background: #f8fbfe;
            border: 1px solid #dce6ef;
            border-radius: 14px;
            padding: 16px;
        }
        .ok {
            background: #ecfdf3;
            border: 1px solid #a7f3d0;
            color: #14532d;
            padding: 12px 14px;
            border-radius: 12px;
            margin-bottom: 12px;
        }
        .error {
            background: #fdecec;
            border: 1px solid #f0b4b4;
            color: #8b1c1c;
            padding: 12px 14px;
            border-radius: 12px;
            margin-bottom: 12px;
        }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
        button, a.btn {
            border: 0;
            border-radius: 999px;
            padding: 12px 16px;
            background: #1b8252;
            color: #fff;
            text-decoration: none;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }
        button.secondary, a.btn.secondary { background: #2c4c74; }
        button.ghost, a.btn.ghost { background: #6b7280; }
        code { background: #edf3f8; padding: 2px 6px; border-radius: 6px; }
        ul { margin: 10px 0 0 18px; }
        li { margin: 6px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border-bottom: 1px solid #dce6ef; padding: 9px 6px; text-align: left; }
        .muted { color: #5b728a; }
        .pill {
            display: inline-block;
            border-radius: 999px;
            padding: 3px 9px;
            background: #e6f6ed;
            color: #14532d;
            font-weight: 700;
            font-size: 0.9rem;
        }
        .pill.off { background: #f1f5f9; color: #475569; }
        @media (max-width: 760px) {
            .row { grid-template-columns: 1fr; }
            .actions { flex-direction: column; }
            button, a.btn { text-align: center; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>MySQL del calendario</h1>
        <p class="muted">Esta página prepara el calendario actual para trabajar con tablas MySQL reales en el mismo hosting.</p>

        <?php if ($message): ?><div class="ok"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

        <div class="row">
            <div class="card">
                <h2>Estado actual</h2>
                <ul>
                    <li>Backend activo: <strong><?php echo htmlspecialchars($backend, ENT_QUOTES, 'UTF-8'); ?></strong></li>
                    <li>MySQL disponible: <strong><?php echo $dbOk ? 'sí' : 'no'; ?></strong></li>
                    <li>Base de datos detectada: <code><?php echo htmlspecialchars((string) ($config['name'] ?? 'no detectada'), ENT_QUOTES, 'UTF-8'); ?></code></li>
                </ul>
            </div>
            <div class="card">
                <h2>Datos detectados</h2>
                <ul>
                    <?php foreach ($counts as $label => $count): ?>
                        <li><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>: <strong><?php echo (int) $count; ?></strong></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <form method="post" class="actions">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" name="action" value="prepare_mysql_full">Preparar tablas MySQL</button>
            <button type="submit" name="action" value="seed_mysql_full" class="secondary">Copiar calendario actual a MySQL</button>
            <button type="submit" name="action" value="use_mysql_full" class="secondary">Usar MySQL completo</button>
            <button type="submit" name="action" value="use_json" class="ghost">Volver a JSON</button>
            <a class="btn ghost" href="/admin/calendar.php">Volver al calendario</a>
        </form>

        <div class="card" style="margin-top:16px;">
            <h2>Tablas del calendario</h2>
            <table>
                <thead>
                    <tr>
                        <th>Tabla</th>
                        <th>Estado</th>
                        <th>Registros</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tableStatus as $row): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                            <td><?php echo $row['exists'] ? '<span class="pill">creada</span>' : '<span class="pill off">pendiente</span>'; ?></td>
                            <td><?php echo $row['count'] === null ? '-' : (int) $row['count']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card" style="margin-top:16px;">
            <h2>Orden recomendado</h2>
            <p>Primero prepara las tablas, luego copia el calendario actual, y al final activa <strong>Usar MySQL completo</strong>. Si algo no te gusta o necesitas volver atrás, <strong>Volver a JSON</strong> deja el calendario usando el archivo anterior.</p>
            <p class="muted">Si prefieres crear las tablas manualmente desde phpMyAdmin, usa el archivo <code>/sql/calendar_store.sql</code>.</p>
        </div>
    </div>
</body>
</html>
