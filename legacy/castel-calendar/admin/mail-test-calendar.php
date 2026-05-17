<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';

admin_bootstrap_session();
admin_require_maintenance_tools_enabled();

$current_user = admin_current_user();
$csrf_token = admin_csrf_token();
$results = array();
$message = '';
$error = '';
$posted_recipient = '';

function castel_mail_test_templates()
{
    return array(
        array(
            'subject' => 'Reserva creada en calendario privado',
            'body' => "Hola Pablo Elías Avendaño Miranda,\n\nSe registró una nueva reserva en el calendario privado.\n\nFecha: 2026-04-27\nSala: Sala Básica\nEstado: Reservada\nResponsable / curso: 6°B · Prof. Pablo\nObservaciones: Clase de computación y evaluación.\nRegistrado por: Pablo Elías Avendaño Miranda\n\nPuedes revisar el calendario privado en:\n/admin/calendar.php\n\nMensaje automático del panel privado del calendario."
        ),
        array(
            'subject' => 'Reserva actualizada en calendario privado',
            'body' => "Hola Pablo Elías Avendaño Miranda,\n\nTu reserva fue actualizada en el calendario privado.\n\nFecha: 2026-04-27\nSala: Sala Básica\nEstado: Mantención\nResponsable / curso: Pablo Avendaño\nObservaciones: Ajuste de horario y revisión de equipos.\nRegistrado por: Pablo Elías Avendaño Miranda\n\nPuedes revisar el calendario privado en:\n/admin/calendar.php\n\nMensaje automático del panel privado del calendario."
        ),
        array(
            'subject' => 'Reserva liberada en calendario privado',
            'body' => "Hola Pablo Elías Avendaño Miranda,\n\nTu reserva fue liberada del calendario privado.\n\nFecha: 2026-04-27\nSala: Sala Básica\nEstado: Reservada\nResponsable / curso: 6°B · Prof. Pablo\nObservaciones: Se liberó el bloque para otro uso.\nRegistrado por: Pablo Elías Avendaño Miranda\n\nPuedes revisar el calendario privado en:\n/admin/calendar.php\n\nMensaje automático del panel privado del calendario."
        ),
        array(
            'subject' => 'Solicitud de cambio en calendario privado',
            'body' => "Hola Pablo Elías Avendaño Miranda,\n\nUn docente solicitó modificar una reserva que hoy está a tu nombre en el calendario.\n\nFecha: 2026-04-28\nSala: Sala Media\nSolicitante: Docente de prueba\nEstado solicitado: Reservada\nResponsable propuesto: 2° Medio A · Prueba\nObservaciones propuestas: Necesita evaluación en laboratorio.\nMotivo: Se requiere cambio puntual por actividad académica.\n\nPara aprobar o rechazar este cambio, entra al panel privado:\n/admin/calendar.php\n\nMensaje automático del panel privado del calendario."
        ),
        array(
            'subject' => 'Solicitud aprobada en calendario privado',
            'body' => "Hola Pablo Elías Avendaño Miranda,\n\nTu solicitud de cambio fue aprobada.\n\nFecha: 2026-04-28\nSala: Sala Media\nEstado solicitado: Reservada\nResponsable propuesto: 2° Medio A · Prueba\nRespondió: Germán Cavada\n\nPuedes revisar el estado actualizado en:\n/admin/calendar.php\n\nMensaje automático del panel privado del calendario."
        ),
        array(
            'subject' => 'Solicitud rechazada en calendario privado',
            'body' => "Hola Pablo Elías Avendaño Miranda,\n\nTu solicitud de cambio fue rechazada.\n\nFecha: 2026-04-28\nSala: Sala Media\nEstado solicitado: Reservada\nResponsable propuesto: 2° Medio A · Prueba\nRespondió: René Reyes\n\nPuedes revisar el estado actualizado en:\n/admin/calendar.php\n\nMensaje automático del panel privado del calendario."
        ),
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_recipient = isset($_POST['test_recipient']) ? trim((string) $_POST['test_recipient']) : '';
    if (!admin_validate_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        $error = 'La sesión expiró. Recarga la página e inténtalo otra vez.';
    } else {
        $candidate = $posted_recipient !== '' ? $posted_recipient : (string) ($current_user['email'] ?? '');
        $candidate = admin_normalize_email($candidate);
        if ($candidate === '' || !filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            $error = 'Indica un correo electrónico válido para recibir las plantillas de prueba.';
        } elseif (strlen($candidate) > 254) {
            $error = 'El correo indicado es demasiado largo.';
        } else {
            $to = $candidate;
            foreach (castel_mail_test_templates() as $index => $template) {
                $mailError = null;
                $ok = castel_mailer_send($to, $template['subject'], $template['body'], $mailError);
                $results[] = array(
                    'n' => $index + 1,
                    'subject' => $template['subject'],
                    'ok' => $ok,
                    'error' => $mailError,
                );
            }

            $successCount = count(array_filter($results, function ($row) { return !empty($row['ok']); }));
            $message = 'Prueba ejecutada hacia ' . $to . '. Resultado SMTP: ' . $successCount . ' de ' . count($results) . ' envíos aceptados.';
        }
    }
}

$default_recipient = htmlspecialchars(
    $posted_recipient !== '' ? $posted_recipient : (string) ($current_user['email'] ?? ''),
    ENT_QUOTES,
    'UTF-8'
);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Prueba SMTP calendario | RoomKeeper</title>
    <link rel="stylesheet" href="admin-responsive.css">
    <meta name="robots" content="noindex,nofollow">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --ccg-ink: #e8f4ff;
            --ccg-muted: rgba(200, 220, 245, 0.78);
            --ccg-line: rgba(123, 196, 255, 0.2);
            --ccg-panel: linear-gradient(165deg, #121c2c, #0c1522);
            --ccg-ok: #5ee0a8;
            --ccg-bad: #ff9a9a;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Outfit', system-ui, sans-serif;
            margin: 0;
            display: flex;
            min-height: 100vh;
            min-height: 100dvh;
            background: radial-gradient(ellipse 120% 80% at 10% 0%, rgba(30, 72, 120, 0.35), transparent 50%),
                linear-gradient(180deg, #070b10 0%, #0a1018 45%, #06090e 100%);
            color: var(--ccg-ink);
        }
        .sidebar { width: min(268px, 100%); background: #0a1a2e; color: #fff; padding: 22px 18px; flex-shrink: 0; border-right: 1px solid rgba(123, 196, 255, 0.12); }
        .sidebar h2 { margin: 0 0 8px; font-size: 1.2rem; }
        .sidebar-user { font-size: 0.78rem; opacity: 0.75; margin: 0 0 16px; word-break: break-all; }
        .sidebar-divider { border: 0; border-top: 1px solid rgba(255,255,255,0.12); margin: 16px 0; }
        .nav-link { display: block; color: #fff; text-decoration: none; padding: 10px 12px; border-radius: 10px; margin-bottom: 4px; font-weight: 600; font-size: 0.92rem; }
        .nav-link:hover { background: rgba(255,255,255,0.06); }
        .nav-link.is-active { background: rgba(27, 130, 82, 0.42); }
        .nav-link--muted { margin-top: 28px; opacity: 0.55; font-weight: 500; }
        .nav-form { margin-top: 18px; }
        .nav-form button { width: 100%; border: 0; cursor: pointer; text-align: left; font: inherit; }
        .btn-success { background: #0f9d58; color: #fff; }
        .main {
            flex: 1;
            padding: 28px clamp(18px, 3vw, 40px) 48px;
            width: 100%;
            max-width: min(69rem, 100%);
        }
        h1 { margin: 0 0 12px; font-size: clamp(1.35rem, 2.4vw, 1.75rem); font-weight: 800; letter-spacing: -0.02em; }
        .lead { margin: 0 0 20px; color: var(--ccg-muted); line-height: 1.6; max-width: 75ch; }
        .panel {
            background: var(--ccg-panel);
            border: 1px solid var(--ccg-line);
            border-radius: 18px;
            padding: clamp(20px, 2.5vw, 28px);
            box-shadow: 0 22px 50px rgba(2, 6, 14, 0.45);
        }
        .note {
            background: rgba(31, 99, 187, 0.18);
            border: 1px solid rgba(123, 196, 255, 0.22);
            border-radius: 14px;
            padding: 14px 16px;
            margin: 18px 0;
            color: var(--ccg-muted);
            line-height: 1.55;
        }
        .note strong { color: var(--ccg-ink); }
        .ok { background: rgba(36, 170, 112, 0.2); border: 1px solid rgba(94, 224, 168, 0.35); color: #d4ffe8; padding: 14px 16px; border-radius: 14px; margin: 16px 0; font-weight: 600; }
        .error { background: rgba(196, 79, 79, 0.2); border: 1px solid rgba(255, 154, 154, 0.35); color: #ffe2e2; padding: 14px 16px; border-radius: 14px; margin: 16px 0; font-weight: 600; }
        label.field { display: block; font-weight: 700; margin-bottom: 8px; color: var(--ccg-ink); }
        input[type="email"] {
            width: 100%;
            max-width: 480px;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid var(--ccg-line);
            background: rgba(4, 12, 24, 0.85);
            color: var(--ccg-ink);
            font: inherit;
        }
        input[type="email"]::placeholder { color: rgba(158, 192, 229, 0.55); }
        .form-actions { margin-top: 18px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
        button[type="submit"] {
            border: 0;
            border-radius: 999px;
            padding: 13px 22px;
            font: inherit;
            font-weight: 700;
            color: #fff;
            cursor: pointer;
            background: linear-gradient(135deg, #1b8252, #1c9a8a);
            box-shadow: 0 14px 28px rgba(12, 60, 40, 0.35);
        }
        button[type="submit"]:hover { filter: brightness(1.05); }
        table { width: 100%; border-collapse: collapse; margin-top: 18px; font-size: 0.92rem; }
        th, td { text-align: left; padding: 12px 10px; border-bottom: 1px solid rgba(123, 196, 255, 0.1); }
        th { color: var(--ccg-muted); font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.06em; }
        .tag { display: inline-block; padding: 6px 10px; border-radius: 999px; font-weight: 700; font-size: 0.82rem; }
        .tag-yes { background: rgba(36, 170, 112, 0.28); color: #c8ffe4; }
        .tag-no { background: rgba(196, 79, 79, 0.28); color: #ffd6d6; }
        @media (max-width: 880px) {
            body { flex-direction: column; }
            .sidebar { width: 100%; border-right: 0; border-bottom: 1px solid rgba(123, 196, 255, 0.12); }
        }
    </style>
</head>
<body>
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
    <div class="main">
        <h1>Test de plantillas de correo del calendario</h1>
        <p class="lead">Esta ruta <strong>no es pública</strong>: solo funciona con sesión iniciada en el panel privado (redirección al login si no hay sesión). Envía las 6 plantillas de ejemplo por SMTP del panel. Puedes dirigir la prueba a <strong>cualquier correo válido</strong> (por ejemplo una casilla de prueba o la tuya).</p>

        <div class="panel">
            <div class="note">
                Usuario en sesión: <strong><?php echo htmlspecialchars(admin_user_display_name($current_user), ENT_QUOTES, 'UTF-8'); ?></strong><br>
                Correo de la cuenta: <strong><?php echo htmlspecialchars((string) ($current_user['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>

            <?php if ($message): ?>
                <div class="ok"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <label class="field" for="test_recipient">Correo destino de la prueba</label>
                <input type="email" id="test_recipient" name="test_recipient" value="<?php echo $default_recipient; ?>" placeholder="ejemplo@dominio.cl" autocomplete="email" maxlength="254">
                <div class="form-actions">
                    <button type="submit">Enviar 6 plantillas de prueba</button>
                </div>
            </form>

            <?php if ($results): ?>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Asunto</th>
                            <th>SMTP</th>
                            <th>Detalle</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $row): ?>
                            <tr>
                                <td><?php echo (int) $row['n']; ?></td>
                                <td><?php echo htmlspecialchars($row['subject'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php if (!empty($row['ok'])): ?>
                                        <span class="tag tag-yes">Aceptado</span>
                                    <?php else: ?>
                                        <span class="tag tag-no">Falló</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars(!empty($row['error']) ? (string) $row['error'] : 'OK', ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
