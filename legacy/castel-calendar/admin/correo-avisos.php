<?php
require_once __DIR__ . '/auth.php';

admin_bootstrap_session();
admin_require_site_admin();

$csrf_token = admin_csrf_token();
$config = null;
if (is_file(__DIR__ . '/mail_config.php')) {
    $config = require __DIR__ . '/mail_config.php';
}
$host = is_array($config) && !empty($config['host']) ? (string) $config['host'] : '—';
$from = is_array($config) && !empty($config['from_email']) ? (string) $config['from_email'] : '—';
$reply = is_array($config) && !empty($config['reply_to']) ? (string) $config['reply_to'] : '—';
$user = is_array($config) && !empty($config['username']) ? (string) $config['username'] : '—';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Correo y avisos | RoomKeeper</title>
    <link rel="stylesheet" href="admin-responsive.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Outfit', system-ui, sans-serif; margin: 0; display: flex; min-height: 100vh; min-height: 100dvh; background: linear-gradient(180deg, #070b10 0%, #0c1520 100%); color: #e8f4ff; }
        .sidebar { width: min(260px, 100%); background: #0a1a2e; color: #fff; padding: 22px 18px; flex-shrink: 0; border-right: 1px solid rgba(123, 196, 255, 0.12); }
        .sidebar h2 { margin: 0 0 8px; font-size: 1.15rem; }
        .sidebar-user { font-size: 0.78rem; opacity: 0.75; margin: 0 0 16px; word-break: break-all; }
        .sidebar-divider { border: 0; border-top: 1px solid rgba(255,255,255,0.15); margin: 16px 0; }
        .nav-link { display: block; color: #fff; text-decoration: none; padding: 10px 12px; border-radius: 8px; margin-bottom: 4px; font-weight: 600; font-size: 0.92rem; }
        .nav-link:hover { background: rgba(255,255,255,0.08); }
        .nav-link.is-active { background: rgba(27, 130, 82, 0.35); }
        .nav-link--muted { margin-top: 28px; opacity: 0.55; font-weight: 500; }
        .nav-form { margin-top: 18px; }
        .nav-form button { width: 100%; border: 0; cursor: pointer; text-align: left; font: inherit; }
        .btn-success { background: #0f9d58; color: #fff; }
        .main { flex: 1; padding: clamp(20px, 4vw, 32px) clamp(16px, 3vw, 40px); max-width: min(60rem, 100%); width: 100%; }
        h1 { color: #fff; margin-top: 0; font-weight: 800; letter-spacing: -0.02em; }
        .panel { background: linear-gradient(165deg, #121c2c, #0c1522); border-radius: 18px; padding: 24px 26px; box-shadow: 0 22px 50px rgba(2, 6, 14, 0.45); border: 1px solid rgba(123, 196, 255, 0.16); }
        .panel p, .panel li { line-height: 1.58; color: rgba(210, 228, 248, 0.9); }
        .kv { margin: 12px 0; padding: 10px 0; border-bottom: 1px solid rgba(123, 196, 255, 0.12); font-size: 0.92rem; color: rgba(220, 235, 255, 0.88); }
        .kv strong { display: inline-block; min-width: 160px; color: #fff; }
        .actions { margin-top: 22px; display: flex; flex-wrap: wrap; gap: 12px; }
        .btn { display: inline-block; padding: 10px 18px; border-radius: 10px; font-weight: 700; text-decoration: none; }
        .btn-primary { background: linear-gradient(135deg, #1f63bb, #2a7adb); color: #fff; }
        .btn-secondary { background: rgba(255, 255, 255, 0.08); color: #e8f4ff; border: 1px solid rgba(123, 196, 255, 0.22); }
    </style>
</head>
<body>
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
    <div class="main">
        <h1>Correo y avisos institucionales</h1>
        <div class="panel">
            <p>Los avisos automáticos del calendario y del panel usan el envío SMTP configurado en el servidor. La casilla visible para los destinatarios puede ser la que definas en <strong>Reply-To</strong>, mientras la autenticación SMTP suele usar una cuenta técnica del hosting.</p>
            <ul>
                <li>Si los correos no llegan, ejecuta la <strong>prueba SMTP</strong> y revisa carpeta spam.</li>
                <li>El archivo <code>mail_config.php</code> no debe ser público: está bloqueado por <code>.htaccess</code> en <code>/admin/</code>.</li>
            </ul>
            <div class="kv"><strong>Servidor SMTP</strong> <?php echo htmlspecialchars($host, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="kv"><strong>Usuario SMTP (auth)</strong> <?php echo htmlspecialchars($user, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="kv"><strong>Remitente (From)</strong> <?php echo htmlspecialchars($from, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="kv"><strong>Responder a (Reply-To)</strong> <?php echo htmlspecialchars($reply, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="actions">
                <a class="btn btn-primary" href="mail-test-calendar.php">Ir a prueba de envío</a>
                <a class="btn btn-secondary" href="editor.php">Volver a configuración</a>
            </div>
        </div>
    </div>
</body>
</html>
