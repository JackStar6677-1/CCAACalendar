<?php
require_once __DIR__ . '/auth.php';

admin_bootstrap_session();
admin_require_site_admin();

$site_file = '../data/site.json';
$site_data = json_decode(file_get_contents($site_file), true);

$message = "";
$error = "";

if (isset($_POST['save_site'])) {
    if (!admin_validate_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        $error = "La sesión de edición expiró. Recarga la página e inténtalo otra vez.";
    } else {
        $site_data['name'] = trim(isset($_POST['site_name']) ? $_POST['site_name'] : '');
        $site_data['description'] = trim(isset($_POST['site_description']) ? $_POST['site_description'] : '');
        $site_data['logo_url'] = trim(isset($_POST['logo_url']) ? $_POST['logo_url'] : '');

        file_put_contents($site_file, json_encode($site_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $message = "Configuración guardada correctamente. Ya puedes publicar los cambios.";
    }
}

$csrf_token = admin_csrf_token();
$default_logo = '/admin/calendar-icon.svg';
$logo_preview = isset($site_data['logo_url']) && trim((string) $site_data['logo_url']) !== ''
    ? trim((string) $site_data['logo_url'])
    : $default_logo;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Editor - RoomKeeper</title>
    <link rel="stylesheet" href="admin-responsive.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Outfit', 'Segoe UI', system-ui, sans-serif; margin: 0; display: flex; min-height: 100vh; min-height: 100dvh;
            background: linear-gradient(180deg, #070b10 0%, #0c1520 100%); color: #e8f4ff; }
        .sidebar { width: min(268px, 100%); background: #0a1a2e; color: white; min-height: 100vh; min-height: 100dvh; padding: 22px 18px; flex-shrink: 0; border-right: 1px solid rgba(123, 196, 255, 0.12); }
        .sidebar h2 { margin: 0 0 8px; font-size: 1.2rem; }
        .sidebar-user { font-size: 0.78rem; opacity: 0.75; margin: 0 0 16px; word-break: break-all; }
        .sidebar-divider { border: 0; border-top: 1px solid rgba(255,255,255,0.15); margin: 16px 0; }
        .main { flex: 1; padding: clamp(20px, 4vw, 36px) clamp(16px, 3vw, 40px); max-width: min(52rem, 100%); width: 100%; }
        h1 { color: #fff; margin-top: 0; font-weight: 800; letter-spacing: -0.02em; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: 700; margin-bottom: 6px; color: rgba(220, 235, 255, 0.95); }
        input[type="text"], textarea { width: 100%; padding: 11px 12px; border: 1px solid rgba(123, 196, 255, 0.2); border-radius: 10px;
            background: rgba(4, 12, 24, 0.85); color: #f2f8ff; font: inherit; box-sizing: border-box; }
        textarea { min-height: 88px; resize: vertical; }
        .btn { padding: 11px 20px; border: none; border-radius: 10px; cursor: pointer; font-weight: 700; font: inherit; }
        .btn-primary { background: #1f63bb; color: white; }
        .btn-success { background: #0f9d58; color: white; }
        .alert { background: rgba(36, 170, 112, 0.18); color: #c8ffe4; padding: 14px 16px; border-radius: 12px; margin-bottom: 18px; border: 1px solid rgba(94, 224, 168, 0.35); }
        .alert-error { background: rgba(196, 79, 79, 0.2); color: #ffe2e2; border: 1px solid rgba(255, 154, 154, 0.35); }
        .nav-link { display: block; color: white; text-decoration: none; padding: 10px 12px; border-radius: 8px; margin-bottom: 4px; font-weight: 600; font-size: 0.92rem; }
        .nav-link:hover { background: rgba(255,255,255,0.08); }
        .nav-link.is-active { background: rgba(27, 130, 82, 0.38); }
        .nav-link--muted { margin-top: 28px; opacity: 0.55; font-weight: 500; }
        .nav-form { margin-top: 18px; }
        .nav-form button { width: 100%; border: 0; cursor: pointer; text-align: left; font: inherit; }
        .logo-preview-wrap { margin-top: 10px; padding: 16px; border-radius: 12px; background: rgba(255,255,255,0.06); border: 1px dashed rgba(123, 196, 255, 0.25); text-align: center; }
        .logo-preview-wrap img { max-width: 220px; max-height: 90px; object-fit: contain; }
        .hint { font-size: 0.85rem; color: rgba(180, 205, 235, 0.82); margin-top: 6px; line-height: 1.45; }
    </style>
</head>
<body>
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>

    <div class="main">
        <h1>Configuración general del sitio</h1>

        <?php if ($message): ?><div class="alert"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-group">
                <label>Nombre del colegio</label>
                <input type="text" name="site_name" value="<?php echo htmlspecialchars(isset($site_data['name']) ? $site_data['name'] : '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <div class="form-group">
                <label>Descripción / eslogan</label>
                <textarea name="site_description" rows="3"><?php echo htmlspecialchars(isset($site_data['description']) ? $site_data['description'] : '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <div class="form-group">
                <label>URL del logo</label>
                <input type="text" name="logo_url" value="<?php echo htmlspecialchars(isset($site_data['logo_url']) ? $site_data['logo_url'] : '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php echo htmlspecialchars($default_logo, ENT_QUOTES, 'UTF-8'); ?>">
    <p class="hint">Recomendado: usar un logo simple del sistema en <code>/admin/calendar-icon.svg</code> o una imagen propia del proyecto.</p>
                <div class="logo-preview-wrap">
                    <img src="<?php echo htmlspecialchars($logo_preview, ENT_QUOTES, 'UTF-8'); ?>" alt="Vista previa del logo">
                </div>
            </div>

            <button type="submit" name="save_site" class="btn btn-primary">Guardar configuración</button>
        </form>
    </div>
</body>
</html>
