<?php
require_once __DIR__ . '/auth.php';

admin_bootstrap_session();
admin_require_site_admin();

$csrf_token = admin_csrf_token();
$current_user = admin_current_user();
$current_email = admin_current_user_email();
$users = admin_read_authorized_users();
$message = '';
$error = '';
$generated = null;

function security_user_status_label($user)
{
    if (empty($user['is_active'])) {
        return 'desactivado';
    }
    if (empty($user['password_hash'])) {
        return empty($user['password_setup_token_hash']) ? 'pendiente sin código' : 'pendiente con código';
    }
    return 'activo';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_validate_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        $error = 'La sesión expiró. Recarga la página e inténtalo otra vez.';
    } else {
        $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
        $email = admin_normalize_email(isset($_POST['email']) ? $_POST['email'] : '');

        if ($action === 'add_user') {
            $email = admin_normalize_email(isset($_POST['new_email']) ? $_POST['new_email'] : '');
            $full_name = trim((string) (isset($_POST['new_full_name']) ? $_POST['new_full_name'] : ''));
            $role = admin_normalize_role(isset($_POST['new_role']) ? $_POST['new_role'] : 'profesor');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Ingresa un correo válido.';
            } elseif (isset($users[$email])) {
                $error = 'Ese correo ya existe en el panel.';
            } else {
                $users[$email] = admin_normalize_user_record($email, array(
                    'full_name' => $full_name,
                    'role' => $role,
                    'is_active' => true,
                    'created_at' => date('c'),
                ));
                admin_save_authorized_users($users);
                admin_log_security_event('user_added', $email);
                $message = 'Usuario agregado. Ahora puedes generar su código de activación.';
            }
        } elseif ($email === '' || !isset($users[$email])) {
            $error = 'Usuario no encontrado.';
        } elseif ($action === 'update_user') {
            $role = admin_normalize_role(isset($_POST['role']) ? $_POST['role'] : 'profesor');
            $is_active = !empty($_POST['is_active']);

            if ($email === $current_email && !$is_active) {
                $error = 'No puedes desactivar tu propia cuenta mientras estás dentro.';
            } else {
                $users[$email]['full_name'] = trim((string) (isset($_POST['full_name']) ? $_POST['full_name'] : ''));
                $users[$email]['role'] = $role;
                $users[$email]['is_active'] = $is_active;
                $users[$email]['updated_at'] = date('c');
                admin_save_authorized_users($users);
                admin_log_security_event('user_updated', $email);
                $message = 'Usuario actualizado.';
            }
        } elseif ($action === 'generate_setup_token') {
            if (!empty($users[$email]['password_hash'])) {
                $error = 'Ese usuario ya tiene contraseña. Usa recuperación si necesita cambiarla.';
            } else {
                $token = admin_generate_setup_token();
                $users[$email]['password_setup_token_hash'] = password_hash($token, PASSWORD_DEFAULT);
                $users[$email]['password_setup_token_created_at'] = date('c');
                $users[$email]['password_setup_token_used_at'] = null;
                $users[$email]['updated_at'] = date('c');
                admin_save_authorized_users($users);
                admin_log_security_event('setup_token_generated', $email);
                $generated = array('email' => $email, 'token' => $token, 'kind' => 'activación inicial');
                $message = 'Código de activación generado. Se muestra solo ahora.';
            }
        } elseif ($action === 'revoke_setup_token') {
            $users[$email]['password_setup_token_hash'] = '';
            $users[$email]['password_setup_token_created_at'] = null;
            $users[$email]['password_setup_token_used_at'] = null;
            $users[$email]['updated_at'] = date('c');
            admin_save_authorized_users($users);
            admin_log_security_event('setup_token_revoked', $email);
            $message = 'Código de activación revocado.';
        } elseif ($action === 'force_password_reset') {
            if (empty($users[$email]['password_hash'])) {
                $error = 'Ese usuario todavía no tiene contraseña. Genera un código de activación inicial.';
            } else {
                $token = admin_generate_setup_token();
                $users[$email]['password_reset_token_hash'] = password_hash($token, PASSWORD_DEFAULT);
                $users[$email]['password_reset_token_created_at'] = date('c');
                $users[$email]['password_reset_token_used_at'] = null;
                $users[$email]['updated_at'] = date('c');
                admin_save_authorized_users($users);
                $mail_error = null;
                if (admin_send_password_reset_email($users[$email], $token, $mail_error)) {
                    admin_log_security_event('password_reset_sent_by_admin', $email);
                    $message = 'Código de recuperación enviado al correo institucional.';
                } else {
                    admin_log_security_event('password_reset_mail_failed', $email);
                    $error = 'No se pudo enviar el correo de recuperación. Detalle: ' . ($mail_error ?: 'sin detalle');
                }
            }
        } elseif ($action === 'delete_user') {
            if ($email === $current_email) {
                $error = 'No puedes eliminar tu propia cuenta.';
            } else {
                unset($users[$email]);
                admin_save_authorized_users($users);
                admin_log_security_event('user_deleted', $email);
                $message = 'Usuario eliminado del acceso al calendario.';
            }
        }

        $users = admin_read_authorized_users();
    }
}

$mysql_status = 'fallback JSON';
$conn = admin_db_connect();
if ($conn) {
    $mysql_status = admin_users_mysql_ensure($conn) ? 'MySQL activo' : 'MySQL conectado, tabla no preparada';
    @mysqli_close($conn);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Seguridad y accesos | RoomKeeper</title>
    <style>
        body { margin:0; font-family: Arial, sans-serif; background:#eef4f9; color:#17304b; }
        .layout { display:flex; min-height:100vh; }
        .sidebar { width:260px; background:#16395f; color:#fff; padding:22px; box-sizing:border-box; }
        .sidebar a, .sidebar button { display:block; color:#fff; text-decoration:none; margin:8px 0; padding:10px 12px; border-radius:10px; background:rgba(255,255,255,.08); border:0; width:100%; text-align:left; font:inherit; cursor:pointer; box-sizing:border-box; }
        .sidebar .is-active { background:#1b8252; }
        .content { flex:1; padding:28px; overflow:auto; }
        .card { background:#fff; border:1px solid #d7e2ec; border-radius:18px; padding:20px; margin-bottom:18px; box-shadow:0 10px 26px rgba(44,76,116,.08); }
        .ok { background:#ecfdf3; border:1px solid #a7f3d0; color:#14532d; padding:12px 14px; border-radius:12px; margin-bottom:12px; }
        .error { background:#fdecec; border:1px solid #f0b4b4; color:#8b1c1c; padding:12px 14px; border-radius:12px; margin-bottom:12px; }
        table { width:100%; border-collapse:collapse; min-width:980px; }
        th, td { padding:10px 8px; border-bottom:1px solid #e1e9f1; text-align:left; vertical-align:middle; }
        input, select { border:1px solid #cbd8e6; border-radius:10px; padding:9px 10px; box-sizing:border-box; max-width:100%; }
        button { border:0; border-radius:999px; padding:9px 12px; background:#1b8252; color:#fff; font-weight:700; cursor:pointer; white-space:nowrap; }
        button.secondary { background:#64748b; }
        button.danger { background:#b42318; }
        code { background:#edf3f8; padding:3px 7px; border-radius:7px; }
        .token { font-size:1.35rem; letter-spacing:.08em; font-weight:800; color:#0f264f; }
        .muted { color:#60748b; }
        .actions form { display:inline-block; margin:2px; }
        .grid { display:grid; grid-template-columns: minmax(220px, 1.2fr) minmax(220px, 1fr) 180px auto; gap:10px; align-items:end; }
        .table-wrap { overflow:auto; }
        .pill { display:inline-block; padding:4px 8px; border-radius:999px; background:#e8f2fb; color:#17304b; font-size:.82rem; font-weight:700; }
        @media (max-width: 900px) { .layout { display:block; } .sidebar { width:auto; } .grid { grid-template-columns:1fr; } .content { padding:18px; } }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
    <main class="content">
        <h1>Seguridad y accesos</h1>
        <p class="muted">Origen de usuarios: <strong><?php echo htmlspecialchars($mysql_status, ENT_QUOTES, 'UTF-8'); ?></strong>. Los códigos se guardan hasheados y vencen: activación inicial en 14 días, recuperación en 60 minutos.</p>

        <?php if ($message): ?><div class="ok"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

        <?php if ($generated): ?>
            <section class="card">
                <h2>Código de <?php echo htmlspecialchars($generated['kind'], ENT_QUOTES, 'UTF-8'); ?></h2>
                <p>Usuario: <strong><?php echo htmlspecialchars($generated['email'], ENT_QUOTES, 'UTF-8'); ?></strong></p>
                <p class="token"><?php echo htmlspecialchars($generated['token'], ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="muted">Este código se muestra solo ahora. Entrégalo por un canal confiable.</p>
            </section>
        <?php endif; ?>

        <section class="card">
            <h2>Agregar usuario</h2>
            <form method="post" class="grid">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="add_user">
                <label>Correo<br><input type="email" name="new_email" required></label>
                <label>Nombre<br><input type="text" name="new_full_name" placeholder="Nombre y apellido"></label>
                <label>Rol<br>
                    <select name="new_role">
                        <option value="profesor">Profesor</option>
                        <option value="coordinacion">UTP / coordinación</option>
                        <option value="directivo">Dirección</option>
                        <option value="admin">Admin</option>
                    </select>
                </label>
                <button type="submit">Agregar</button>
            </form>
        </section>

        <section class="card">
            <h2>Usuarios del calendario (<?php echo count($users); ?>)</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Correo</th>
                        <th>Nombre</th>
                        <th>Rol</th>
                        <th>Activo</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $email => $user): ?>
                        <?php $form_id = 'user-form-' . md5($email); ?>
                        <tr>
                            <td><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><input form="<?php echo htmlspecialchars($form_id, ENT_QUOTES, 'UTF-8'); ?>" type="text" name="full_name" value="<?php echo htmlspecialchars((string) ($user['full_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></td>
                            <td>
                                <select form="<?php echo htmlspecialchars($form_id, ENT_QUOTES, 'UTF-8'); ?>" name="role">
                                    <?php foreach (array('profesor' => 'Profesor', 'coordinacion' => 'UTP / coordinación', 'directivo' => 'Dirección', 'admin' => 'Admin') as $roleValue => $roleLabel): ?>
                                        <option value="<?php echo htmlspecialchars($roleValue, ENT_QUOTES, 'UTF-8'); ?>" <?php echo admin_user_role($user) === $roleValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input form="<?php echo htmlspecialchars($form_id, ENT_QUOTES, 'UTF-8'); ?>" type="checkbox" name="is_active" value="1" <?php echo !empty($user['is_active']) ? 'checked' : ''; ?>></td>
                            <td><span class="pill"><?php echo htmlspecialchars(security_user_status_label($user), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td class="actions">
                                <form id="<?php echo htmlspecialchars($form_id, ENT_QUOTES, 'UTF-8'); ?>" method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" name="action" value="update_user">Guardar</button>
                                    <?php if (empty($user['password_hash'])): ?>
                                        <button type="submit" class="secondary" name="action" value="generate_setup_token">Código inicial</button>
                                        <?php if (!empty($user['password_setup_token_hash'])): ?>
                                            <button type="submit" class="secondary" name="action" value="revoke_setup_token">Revocar código</button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <button type="submit" class="secondary" name="action" value="force_password_reset">Enviar recuperación</button>
                                    <?php endif; ?>
                                    <?php if ($email !== $current_email): ?>
                                        <button type="submit" class="danger" name="action" value="delete_user" onclick="return confirm('¿Eliminar este usuario del calendario?')">Eliminar</button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>
