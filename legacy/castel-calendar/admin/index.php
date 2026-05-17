<?php
require_once __DIR__ . '/auth.php';

admin_bootstrap_session();

if (isset($_GET['logout'])) {
    admin_logout_user();
    header('Location: index.php');
    exit;
}

if (!empty($_GET['force_login']) && !empty($_SESSION['admin_email'])) {
    admin_logout_user();
    header('Location: index.php?pwa=1');
    exit;
}

if (!empty($_SESSION['admin_email'])) {
    header('Location: calendar.php');
    exit;
}

$authorized_users = admin_read_authorized_users();
$step = 'email';
$email_value = '';
$info = '';
$error = '';

if (!empty($_SESSION['pending_admin_email'])) {
    $pending_email = admin_normalize_email($_SESSION['pending_admin_email']);
    $pending_user = admin_find_user($pending_email, $authorized_users);
    if ($pending_user) {
        $email_value = $pending_email;
        $step = empty($pending_user['password_hash']) ? 'setup' : 'password';
    } else {
        unset($_SESSION['pending_admin_email']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if (!admin_validate_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        $error = 'La sesión expiró. Recarga la página e inténtalo otra vez.';
        $step = 'email';
    } elseif ($action === 'lookup_email') {
        $email_value = admin_normalize_email(isset($_POST['email']) ? $_POST['email'] : '');
        $user = admin_find_user($email_value, $authorized_users);

        if (!$user) {
            $error = 'El correo no está autorizado.';
            $step = 'email';
        } elseif (array_key_exists('is_active', $user) && !$user['is_active']) {
            $error = 'Este correo está desactivado para el panel.';
            $step = 'email';
        } else {
            $_SESSION['pending_admin_email'] = $email_value;
            $step = empty($user['password_hash']) ? 'setup' : 'password';
            if ($step === 'setup') {
                $info = 'Primer ingreso detectado. Necesitas el código de activación entregado por administración.';
            }
        }
    } elseif ($action === 'set_password') {
        $email_value = admin_normalize_email(isset($_SESSION['pending_admin_email']) ? $_SESSION['pending_admin_email'] : '');
        $user = admin_find_user($email_value, $authorized_users);
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $confirm_password = isset($_POST['confirm_password']) ? (string) $_POST['confirm_password'] : '';
        $setup_token = isset($_POST['setup_token']) ? (string) $_POST['setup_token'] : '';

        if (!$user) {
            $error = 'Tu sesión de acceso expiró. Vuelve a ingresar tu correo.';
            $step = 'email';
            unset($_SESSION['pending_admin_email']);
        } elseif (array_key_exists('is_active', $user) && !$user['is_active']) {
            $error = 'Este correo está desactivado para el panel.';
            $step = 'email';
            unset($_SESSION['pending_admin_email']);
        } elseif (!empty($user['password_hash'])) {
            $error = 'Este correo ya tiene contraseña. Inicia sesión normalmente.';
            $step = 'password';
        } elseif (!admin_setup_token_is_valid($user, $setup_token)) {
            admin_record_login_failure($email_value);
            $error = 'El código de activación no es válido o ya expiró. Solicítalo a administración.';
            $step = 'setup';
        } elseif (strlen($password) < 10) {
            $error = 'La contraseña debe tener al menos 10 caracteres.';
            $step = 'setup';
        } elseif (!hash_equals($password, $confirm_password)) {
            $error = 'Las contraseñas no coinciden.';
            $step = 'setup';
        } else {
            $authorized_users[$email_value]['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $authorized_users[$email_value]['password_created_at'] = date('c');
            $authorized_users[$email_value]['password_setup_token_used_at'] = date('c');
            $authorized_users[$email_value]['password_setup_token_hash'] = '';
            admin_save_authorized_users($authorized_users);
            admin_clear_login_failures($email_value);
            admin_log_security_event('password_setup', $email_value);
            admin_login_user($email_value);
            header('Location: calendar.php');
            exit;
        }
    } elseif ($action === 'login_password') {
        $email_value = admin_normalize_email(isset($_SESSION['pending_admin_email']) ? $_SESSION['pending_admin_email'] : '');
        $user = admin_find_user($email_value, $authorized_users);
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $lock = admin_login_lock_state($email_value);

        if (!empty($lock['locked'])) {
            $error = 'Acceso temporalmente restringido por varios intentos fallidos. Espera unos minutos e inténtalo de nuevo.';
            $step = 'password';
        } elseif (!$user) {
            $error = 'Tu sesión de acceso expiró. Vuelve a ingresar tu correo.';
            $step = 'email';
            unset($_SESSION['pending_admin_email']);
        } elseif (array_key_exists('is_active', $user) && !$user['is_active']) {
            $error = 'Este correo está desactivado para el panel.';
            $step = 'email';
            unset($_SESSION['pending_admin_email']);
        } elseif (empty($user['password_hash'])) {
            $error = 'Este correo aún no tiene contraseña configurada. Debes crearla primero.';
            $step = 'setup';
        } elseif (!password_verify($password, $user['password_hash'])) {
            $record = admin_record_login_failure($email_value);
            if (!empty($record['lock_until']) && (int) $record['lock_until'] > time()) {
                $error = 'Demasiados intentos fallidos. El acceso queda bloqueado 15 minutos.';
            } else {
                $error = 'La contraseña es incorrecta.';
            }
            $step = 'password';
        } else {
            admin_clear_login_failures($email_value);
            admin_log_security_event('login_success', $email_value);
            admin_login_user($email_value);
            header('Location: calendar.php');
            exit;
        }
    } elseif ($action === 'request_password_reset') {
        $email_value = admin_normalize_email(isset($_SESSION['pending_admin_email']) ? $_SESSION['pending_admin_email'] : (isset($_POST['email']) ? $_POST['email'] : ''));
        $user = admin_find_user($email_value, $authorized_users);
        $lock = admin_login_lock_state($email_value);

        if (!empty($lock['locked'])) {
            $error = 'Acceso temporalmente restringido por varios intentos. Espera unos minutos e inténtalo de nuevo.';
            $step = 'password';
        } elseif (!$user || (array_key_exists('is_active', $user) && !$user['is_active'])) {
            $error = 'No se pudo iniciar la recuperación para ese correo.';
            $step = 'email';
            unset($_SESSION['pending_admin_email']);
        } elseif (empty($user['password_hash'])) {
            $error = 'Este correo aún no tiene contraseña. Solicita a administración un código de activación inicial.';
            $step = 'setup';
        } else {
            $token = admin_generate_setup_token();
            $authorized_users[$email_value]['password_reset_token_hash'] = password_hash($token, PASSWORD_DEFAULT);
            $authorized_users[$email_value]['password_reset_token_created_at'] = date('c');
            $authorized_users[$email_value]['password_reset_token_used_at'] = null;
            admin_save_authorized_users($authorized_users);
            $mail_error = null;
            if (admin_send_password_reset_email($authorized_users[$email_value], $token, $mail_error)) {
                admin_log_security_event('password_reset_requested', $email_value);
                $_SESSION['pending_admin_email'] = $email_value;
                $info = 'Te enviamos un código de recuperación al correo institucional. Revisa tu bandeja de entrada.';
                $step = 'reset';
            } else {
                admin_log_security_event('password_reset_mail_failed', $email_value);
                $error = 'No se pudo enviar el correo de recuperación. Detalle: ' . ($mail_error ?: 'sin detalle');
                $step = 'password';
            }
        }
    } elseif ($action === 'reset_password') {
        $email_value = admin_normalize_email(isset($_SESSION['pending_admin_email']) ? $_SESSION['pending_admin_email'] : '');
        $user = admin_find_user($email_value, $authorized_users);
        $reset_token = isset($_POST['reset_token']) ? (string) $_POST['reset_token'] : '';
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $confirm_password = isset($_POST['confirm_password']) ? (string) $_POST['confirm_password'] : '';

        if (!$user) {
            $error = 'Tu sesión de recuperación expiró. Vuelve a ingresar tu correo.';
            $step = 'email';
            unset($_SESSION['pending_admin_email']);
        } elseif (!admin_password_reset_token_is_valid($user, $reset_token)) {
            admin_record_login_failure($email_value);
            $error = 'El código de recuperación no es válido o ya expiró.';
            $step = 'reset';
        } elseif (strlen($password) < 10) {
            $error = 'La contraseña debe tener al menos 10 caracteres.';
            $step = 'reset';
        } elseif (!hash_equals($password, $confirm_password)) {
            $error = 'Las contraseñas no coinciden.';
            $step = 'reset';
        } else {
            $authorized_users[$email_value]['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $authorized_users[$email_value]['password_created_at'] = date('c');
            $authorized_users[$email_value]['password_reset_token_used_at'] = date('c');
            $authorized_users[$email_value]['password_reset_token_hash'] = '';
            admin_save_authorized_users($authorized_users);
            admin_clear_login_failures($email_value);
            admin_log_security_event('password_reset_completed', $email_value);
            admin_login_user($email_value);
            header('Location: calendar.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Login - RoomKeeper</title>
    <meta name="theme-color" content="#2C4C74">
    <meta name="application-name" content="RoomKeeper">
    <meta name="apple-mobile-web-app-title" content="RoomKeeper">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="manifest" href="/admin/manifest.webmanifest">
    <link rel="icon" href="/admin/calendar-icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/admin/calendar-icon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(180deg, #eef4f9 0%, #f8fafc 45%, #ffffff 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 24px 16px;
            color: #1a2d45;
        }
        .login-card {
            background: #ffffff;
            padding: 40px 36px;
            border-radius: 20px;
            border: 1px solid #d8e4ef;
            box-shadow: 0 12px 40px rgba(44, 76, 116, 0.1);
            width: 100%;
            max-width: 420px;
            text-align: center;
        }
        img { max-width: 150px; margin-bottom: 20px; }
        h1 { font-size: 1.45rem; color: #0f264f; margin: 0 0 20px; font-weight: 800; letter-spacing: -0.02em; }
        input[type="email"], input[type="password"], input[type="text"] {
            width: 100%;
            padding: 13px 14px;
            margin-bottom: 18px;
            border: 1px solid #c5d4e3;
            border-radius: 12px;
            box-sizing: border-box;
            background: #fbfdff;
            color: #142a44;
            font: inherit;
        }
        input::placeholder { color: #7a8fa8; }
        input:focus { outline: 2px solid rgba(31, 99, 187, 0.35); outline-offset: 1px; border-color: #7aa3d6; }
        button {
            background: linear-gradient(135deg, #1b8252, #1c9a8a);
            color: white;
            border: none;
            padding: 14px 20px;
            border-radius: 999px;
            cursor: pointer;
            width: 100%;
            font-size: 1rem;
            font-weight: 700;
        }
        button:hover { filter: brightness(1.03); }
        .error {
            color: #8b1c1c;
            background: #fdecec;
            border: 1px solid #f0b4b4;
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 15px;
            font-size: 0.92rem;
            text-align: left;
        }
        .info {
            color: #14532d;
            background: #ecfdf3;
            border: 1px solid #a7f3d0;
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 15px;
            font-size: 0.9rem;
            text-align: left;
        }
        .hint { color: #3d5166; font-size: 0.85rem; margin-top: -8px; margin-bottom: 16px; text-align: left; }
        .secondary-link { display: inline-block; margin-top: 12px; color: #1f63bb; text-decoration: none; font-size: 0.9rem; font-weight: 600; }
        .secondary-link:hover { text-decoration: underline; }
        .panel-password-note {
            margin: 0 0 18px;
            padding: 12px 14px;
            border-radius: 12px;
            text-align: left;
            font-size: 0.84rem;
            line-height: 1.45;
            color: #1e3a52;
            background: #f0f7ff;
            border: 1px solid #c5daf0;
        }
        .panel-password-note strong { color: #0f264f; }
        .footer-note {
            margin-top: 20px;
            font-size: 0.78rem;
            line-height: 1.45;
            color: #5a6e82;
            text-align: left;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <img src="/admin/calendar-icon.svg" alt="RoomKeeper" style="max-width:128px;width:100%;height:auto;margin-bottom:12px;">
        <h1>Panel privado</h1>
        <?php if (isset($error)): ?><div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <?php if ($info): ?><div class="info"><?php echo htmlspecialchars($info, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

        <?php if ($step === 'email'): ?>
        <p class="panel-password-note">Si más adelante te pide una contraseña, será <strong>exclusiva de este acceso al calendario</strong> en <code>/admin/</code>: <strong>no</strong> es la misma que usas en correo personal ni en otros sistemas.</p>
        <form method="POST">
            <input type="hidden" name="action" value="lookup_email">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="email" name="email" placeholder="Tu correo electrónico institucional" value="<?php echo htmlspecialchars($email_value); ?>" required>
            <button type="submit">Continuar</button>
        </form>
        <?php elseif ($step === 'setup'): ?>
        <p class="panel-password-note">Crea una clave <strong>solo para entrar al calendario</strong> en este sitio. <strong>No</strong> tiene relación con la contraseña de tu correo personal ni con otros servicios externos.</p>
        <form method="POST">
            <p class="hint">Correo autorizado: <strong><?php echo htmlspecialchars($email_value); ?></strong></p>
            <input type="hidden" name="action" value="set_password">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="text" name="setup_token" placeholder="Código de activación" autocomplete="one-time-code" required>
            <input type="password" name="password" placeholder="Crea una contraseña segura" required>
            <input type="password" name="confirm_password" placeholder="Repite la contraseña" required>
            <button type="submit">Crear contraseña y entrar</button>
        </form>
        <a class="secondary-link" href="index.php?logout=1">Cambiar de correo</a>
        <?php elseif ($step === 'reset'): ?>
        <p class="panel-password-note">Ingresa el código que llegó a tu correo y crea una nueva contraseña <strong>solo para el calendario</strong>.</p>
        <form method="POST">
            <p class="hint">Recuperación para: <strong><?php echo htmlspecialchars($email_value); ?></strong></p>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="text" name="reset_token" placeholder="Código recibido por correo" autocomplete="one-time-code" required>
            <input type="password" name="password" placeholder="Nueva contraseña del calendario" required>
            <input type="password" name="confirm_password" placeholder="Repite la nueva contraseña" required>
            <button type="submit">Actualizar contraseña y entrar</button>
        </form>
        <a class="secondary-link" href="index.php?logout=1">Usar otro correo</a>
        <?php else: ?>
        <p class="panel-password-note">Esta es la contraseña <strong>del panel del calendario</strong> (la que creaste aquí). <strong>No</strong> es la de otros servicios externos.</p>
        <form method="POST">
            <p class="hint">Ingresa la contraseña de <strong><?php echo htmlspecialchars($email_value); ?></strong></p>
            <input type="hidden" name="action" value="login_password">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="password" name="password" placeholder="Contraseña del panel /admin/" required>
            <button type="submit">Entrar</button>
        </form>
        <form method="POST" style="margin-top:10px">
            <input type="hidden" name="action" value="request_password_reset">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" style="background:#64748b">Olvidé mi contraseña</button>
        </form>
        <a class="secondary-link" href="index.php?logout=1">Usar otro correo</a>
        <?php endif; ?>
        <p class="footer-note">Solo personal autorizado puede acceder. La contraseña de este panel sirve únicamente para este calendario privado.</p>
    </div>
    <script src="/admin/pwa.js" defer></script>
</body>
</html>
