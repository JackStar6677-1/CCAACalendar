<?php

function admin_send_security_headers()
{
    static $sent = false;
    if ($sent || headers_sent()) {
        return;
    }
    $sent = true;
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    $csp = "default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";
    header('Content-Security-Policy: ' . $csp);
}

function admin_client_ip()
{
    foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR') as $key) {
        if (empty($_SERVER[$key])) {
            continue;
        }
        $value = trim((string) $_SERVER[$key]);
        if ($key === 'HTTP_X_FORWARDED_FOR') {
            $parts = explode(',', $value);
            $value = trim((string) $parts[0]);
        }
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return $value;
        }
    }
    return 'unknown';
}

function admin_bootstrap_session()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        admin_send_security_headers();
        return;
    }

    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('castel_admin');
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => '/admin/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    session_start();
    admin_enforce_session_limits();
    admin_send_security_headers();
}

function admin_enforce_session_limits()
{
    if (empty($_SESSION['admin_email'])) {
        return;
    }

    $now = time();
    $idleLimit = 45 * 60;
    $absoluteLimit = 12 * 60 * 60;

    if (empty($_SESSION['admin_login_started_at'])) {
        $_SESSION['admin_login_started_at'] = $now;
    }
    if (empty($_SESSION['admin_last_seen_at'])) {
        $_SESSION['admin_last_seen_at'] = $now;
    }

    $idleAge = $now - (int) $_SESSION['admin_last_seen_at'];
    $absoluteAge = $now - (int) $_SESSION['admin_login_started_at'];
    if ($idleAge > $idleLimit || $absoluteAge > $absoluteLimit) {
        admin_logout_user();
        return;
    }

    $_SESSION['admin_last_seen_at'] = $now;
}

function admin_auth_file_path()
{
    return __DIR__ . '/../data/authorized_emails.json';
}

function admin_login_locks_path()
{
    return __DIR__ . '/../data/admin_login_locks.json';
}

function admin_security_log_path()
{
    return __DIR__ . '/../data/admin_security_events.log';
}

function admin_tools_config_path()
{
    return __DIR__ . '/../data/admin_tools.json';
}

function admin_maintenance_tools_enabled()
{
    $path = admin_tools_config_path();
    if (!is_file($path)) {
        return false;
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return false;
    }

    return !empty($decoded['maintenance_tools_enabled']);
}

function admin_require_maintenance_tools_enabled()
{
    admin_require_site_admin();
    if (!admin_maintenance_tools_enabled()) {
        http_response_code(404);
        echo 'Herramienta de mantenimiento desactivada en producción.';
        exit;
    }
}

function admin_db_config()
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = __DIR__ . '/../wp-config.php';
    if (!is_file($path)) {
        $config = false;
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        $config = false;
        return null;
    }

    $parsed = array();
    foreach (array('DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST') as $key) {
        if (preg_match('/define\s*\(\s*[\'"]' . preg_quote($key, '/') . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)\s*;/', $raw, $match) !== 1) {
            $config = false;
            return null;
        }
        $parsed[$key] = $match[1];
    }

    $host = trim((string) $parsed['DB_HOST']);
    $port = 0;
    if (preg_match('/^(.+):([0-9]+)$/', $host, $match) === 1) {
        $host = $match[1];
        $port = (int) $match[2];
    }

    $config = array(
        'name' => $parsed['DB_NAME'],
        'user' => $parsed['DB_USER'],
        'password' => $parsed['DB_PASSWORD'],
        'host' => $host,
        'port' => $port,
    );
    return $config;
}

function admin_db_connect()
{
    $config = admin_db_config();
    if (!$config || !function_exists('mysqli_init')) {
        return null;
    }

    $conn = @mysqli_init();
    if (!$conn) {
        return null;
    }

    @mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    $ok = @mysqli_real_connect(
        $conn,
        (string) $config['host'],
        (string) $config['user'],
        (string) $config['password'],
        (string) $config['name'],
        !empty($config['port']) ? (int) $config['port'] : 0
    );

    if (!$ok) {
        @mysqli_close($conn);
        return null;
    }

    @mysqli_set_charset($conn, 'utf8mb4');
    return $conn;
}

function admin_users_table_name()
{
    return 'castel_admin_users';
}

function admin_users_mysql_schema_sql()
{
    return 'CREATE TABLE IF NOT EXISTS `' . admin_users_table_name() . '` (
        `email` VARCHAR(255) NOT NULL PRIMARY KEY,
        `full_name` VARCHAR(255) NOT NULL DEFAULT \'\',
        `role` VARCHAR(40) NOT NULL DEFAULT \'profesor\',
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `password_hash` VARCHAR(255) NOT NULL DEFAULT \'\',
        `password_created_at` VARCHAR(40) NULL,
        `password_setup_token_hash` VARCHAR(255) NOT NULL DEFAULT \'\',
        `password_setup_token_created_at` VARCHAR(40) NULL,
        `password_setup_token_used_at` VARCHAR(40) NULL,
        `password_reset_token_hash` VARCHAR(255) NOT NULL DEFAULT \'\',
        `password_reset_token_created_at` VARCHAR(40) NULL,
        `password_reset_token_used_at` VARCHAR(40) NULL,
        `created_at` VARCHAR(40) NULL,
        `updated_at` VARCHAR(40) NULL,
        INDEX `idx_role` (`role`),
        INDEX `idx_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
}

function admin_users_mysql_ensure($conn)
{
    if (!$conn) {
        return false;
    }

    if (!mysqli_query($conn, admin_users_mysql_schema_sql())) {
        return false;
    }

    $columns = array(
        'password_reset_token_hash' => 'ALTER TABLE `' . admin_users_table_name() . '` ADD COLUMN `password_reset_token_hash` VARCHAR(255) NOT NULL DEFAULT \'\'',
        'password_reset_token_created_at' => 'ALTER TABLE `' . admin_users_table_name() . '` ADD COLUMN `password_reset_token_created_at` VARCHAR(40) NULL',
        'password_reset_token_used_at' => 'ALTER TABLE `' . admin_users_table_name() . '` ADD COLUMN `password_reset_token_used_at` VARCHAR(40) NULL',
        'created_at' => 'ALTER TABLE `' . admin_users_table_name() . '` ADD COLUMN `created_at` VARCHAR(40) NULL',
        'updated_at' => 'ALTER TABLE `' . admin_users_table_name() . '` ADD COLUMN `updated_at` VARCHAR(40) NULL',
    );
    foreach ($columns as $column => $sql) {
        $exists = mysqli_query($conn, 'SHOW COLUMNS FROM `' . admin_users_table_name() . '` LIKE \'' . mysqli_real_escape_string($conn, $column) . '\'');
        if ($exists && mysqli_num_rows($exists) > 0) {
            mysqli_free_result($exists);
            continue;
        }
        if ($exists) {
            mysqli_free_result($exists);
        }
        @mysqli_query($conn, $sql);
    }

    return true;
}

function admin_normalize_email($email)
{
    return strtolower(trim((string) $email));
}

function admin_normalize_role($role)
{
    $role = strtolower(trim((string) $role));
    return in_array($role, array('admin', 'directivo', 'coordinacion', 'profesor'), true) ? $role : 'profesor';
}

function admin_normalize_user_record($email, $value)
{
    $email = admin_normalize_email($email);
    $value = is_array($value) ? $value : array();
    return array(
        'email' => $email,
        'full_name' => isset($value['full_name']) ? (string) $value['full_name'] : '',
        'role' => admin_normalize_role(isset($value['role']) ? $value['role'] : 'profesor'),
        'is_active' => array_key_exists('is_active', $value) ? (bool) $value['is_active'] : true,
        'password_hash' => isset($value['password_hash']) ? (string) $value['password_hash'] : '',
        'password_created_at' => isset($value['password_created_at']) ? $value['password_created_at'] : null,
        'password_setup_token_hash' => isset($value['password_setup_token_hash']) ? (string) $value['password_setup_token_hash'] : '',
        'password_setup_token_created_at' => isset($value['password_setup_token_created_at']) ? $value['password_setup_token_created_at'] : null,
        'password_setup_token_used_at' => isset($value['password_setup_token_used_at']) ? $value['password_setup_token_used_at'] : null,
        'password_reset_token_hash' => isset($value['password_reset_token_hash']) ? (string) $value['password_reset_token_hash'] : '',
        'password_reset_token_created_at' => isset($value['password_reset_token_created_at']) ? $value['password_reset_token_created_at'] : null,
        'password_reset_token_used_at' => isset($value['password_reset_token_used_at']) ? $value['password_reset_token_used_at'] : null,
        'created_at' => isset($value['created_at']) ? $value['created_at'] : null,
        'updated_at' => isset($value['updated_at']) ? $value['updated_at'] : null,
    );
}

function admin_read_authorized_users_json()
{
    $auth_file = admin_auth_file_path();
    if (!file_exists($auth_file)) {
        return array();
    }

    $raw = file_get_contents($auth_file);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return array();
    }

    $users = array();
    foreach ($decoded as $key => $value) {
        if (is_int($key) && is_string($value)) {
            $email = admin_normalize_email($value);
            $users[$email] = admin_normalize_user_record($email, array());
            continue;
        }

        if (is_string($key) && is_array($value)) {
            $email = admin_normalize_email(isset($value['email']) ? $value['email'] : $key);
            $users[$email] = admin_normalize_user_record($email, $value);
        }
    }

    ksort($users);
    return $users;
}

function admin_users_mysql_read($conn)
{
    if (!$conn || !admin_users_mysql_ensure($conn)) {
        return null;
    }

    $result = mysqli_query($conn, 'SELECT * FROM `' . admin_users_table_name() . '` ORDER BY `email` ASC');
    if (!$result) {
        return null;
    }

    $users = array();
    while ($row = mysqli_fetch_assoc($result)) {
        $email = admin_normalize_email($row['email'] ?? '');
        if ($email === '') {
            continue;
        }
        $users[$email] = admin_normalize_user_record($email, $row);
    }
    mysqli_free_result($result);
    return $users;
}

function admin_users_mysql_save($conn, $users)
{
    if (!$conn || !admin_users_mysql_ensure($conn)) {
        return false;
    }

    if (!@mysqli_begin_transaction($conn)) {
        return false;
    }

    if (!mysqli_query($conn, 'DELETE FROM `' . admin_users_table_name() . '`')) {
        @mysqli_rollback($conn);
        return false;
    }

    $sql = 'INSERT INTO `' . admin_users_table_name() . '` (`email`, `full_name`, `role`, `is_active`, `password_hash`, `password_created_at`, `password_setup_token_hash`, `password_setup_token_created_at`, `password_setup_token_used_at`, `password_reset_token_hash`, `password_reset_token_created_at`, `password_reset_token_used_at`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        @mysqli_rollback($conn);
        return false;
    }

    foreach ($users as $email => $user) {
        $record = admin_normalize_user_record($email, $user);
        $recordEmail = $record['email'];
        $fullName = $record['full_name'];
        $role = $record['role'];
        $active = !empty($record['is_active']) ? 1 : 0;
        $passwordHash = $record['password_hash'];
        $passwordCreatedAt = $record['password_created_at'];
        $setupTokenHash = $record['password_setup_token_hash'];
        $setupTokenCreatedAt = $record['password_setup_token_created_at'];
        $setupTokenUsedAt = $record['password_setup_token_used_at'];
        $resetTokenHash = $record['password_reset_token_hash'];
        $resetTokenCreatedAt = $record['password_reset_token_created_at'];
        $resetTokenUsedAt = $record['password_reset_token_used_at'];
        $createdAt = $record['created_at'] ?: date('c');
        $updatedAt = date('c');
        mysqli_stmt_bind_param(
            $stmt,
            'sssissssssssss',
            $recordEmail,
            $fullName,
            $role,
            $active,
            $passwordHash,
            $passwordCreatedAt,
            $setupTokenHash,
            $setupTokenCreatedAt,
            $setupTokenUsedAt,
            $resetTokenHash,
            $resetTokenCreatedAt,
            $resetTokenUsedAt,
            $createdAt,
            $updatedAt
        );
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            @mysqli_rollback($conn);
            return false;
        }
    }

    mysqli_stmt_close($stmt);
    return @mysqli_commit($conn);
}

function admin_maybe_migrate_users_to_mysql($conn)
{
    $users = admin_users_mysql_read($conn);
    if (!is_array($users) || count($users) > 0) {
        return $users;
    }

    $jsonUsers = admin_read_authorized_users_json();
    if (!$jsonUsers) {
        return $users;
    }

    if (admin_users_mysql_save($conn, $jsonUsers)) {
        admin_log_security_event('users_migrated_to_mysql', '');
        return admin_users_mysql_read($conn);
    }

    return $users;
}

function admin_read_authorized_users()
{
    $conn = admin_db_connect();
    if ($conn) {
        $users = admin_maybe_migrate_users_to_mysql($conn);
        @mysqli_close($conn);
        if (is_array($users)) {
            ksort($users);
            return $users;
        }
    }

    return admin_read_authorized_users_json();
}

function admin_save_authorized_users($users)
{
    $payload = array();
    foreach ($users as $email => $user) {
        $normalized = admin_normalize_email($email);
        $payload[$normalized] = admin_normalize_user_record($normalized, $user);
    }

    ksort($payload);

    $conn = admin_db_connect();
    if ($conn) {
        $saved = admin_users_mysql_save($conn, $payload);
        @mysqli_close($conn);
        if ($saved) {
            return true;
        }
    }

    file_put_contents(
        admin_auth_file_path(),
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    return true;
}

function admin_find_user($email, $users)
{
    $email = admin_normalize_email($email);
    return isset($users[$email]) ? $users[$email] : null;
}

function admin_login_user($email)
{
    session_regenerate_id(true);
    $_SESSION['admin_login_started_at'] = time();
    $_SESSION['admin_last_seen_at'] = time();
    $_SESSION['admin_email'] = admin_normalize_email($email);
    unset($_SESSION['pending_admin_email']);
}

function admin_logout_user()
{
    $_SESSION = array();

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}

function admin_require_login()
{
    if (empty($_SESSION['admin_email'])) {
        header('Location: index.php');
        exit;
    }

    $user = admin_current_user();
    if (!$user || (array_key_exists('is_active', $user) && !$user['is_active'])) {
        admin_logout_user();
        header('Location: index.php');
        exit;
    }
}

function admin_current_user()
{
    if (empty($_SESSION['admin_email'])) {
        return null;
    }

    $users = admin_read_authorized_users();
    $email = admin_normalize_email($_SESSION['admin_email']);
    return isset($users[$email]) ? $users[$email] : null;
}

function admin_current_user_email()
{
    $user = admin_current_user();
    return $user ? $user['email'] : null;
}

function admin_user_role($user)
{
    if (!is_array($user) || empty($user['role'])) {
        return 'profesor';
    }

    return (string) $user['role'];
}

function admin_user_display_name($user)
{
    if (!is_array($user)) {
        return '';
    }

    if (!empty($user['full_name'])) {
        return (string) $user['full_name'];
    }

    if (!empty($user['email'])) {
        return (string) $user['email'];
    }

    return '';
}

function admin_user_has_calendar_override($user)
{
    return in_array(admin_user_role($user), array('admin', 'directivo', 'coordinacion'), true);
}

function admin_user_can_manage_holidays($user)
{
    return in_array(admin_user_role($user), array('admin', 'directivo', 'coordinacion'), true);
}

function admin_user_can_manage_site($user)
{
    return in_array(admin_user_role($user), array('admin', 'directivo', 'coordinacion'), true);
}

function admin_require_site_admin()
{
    admin_require_login();
    if (!admin_user_can_manage_site(admin_current_user())) {
        header('Location: calendar.php');
        exit;
    }
}

function admin_csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function admin_validate_csrf($token)
{
    if (empty($_SESSION['csrf_token']) || !is_string($token)) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

function admin_login_lock_key($email, $ip = null)
{
    $email = admin_normalize_email($email);
    $ip = $ip === null ? admin_client_ip() : (string) $ip;
    return hash('sha256', $email . '|' . $ip);
}

function admin_read_login_locks()
{
    $path = admin_login_locks_path();
    if (!is_file($path)) {
        return array();
    }
    $raw = file_get_contents($path);
    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : array();
}

function admin_save_login_locks($locks)
{
    file_put_contents(
        admin_login_locks_path(),
        json_encode($locks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function admin_login_lock_state($email)
{
    $locks = admin_read_login_locks();
    $key = admin_login_lock_key($email);
    $now = time();
    $record = isset($locks[$key]) && is_array($locks[$key]) ? $locks[$key] : array();
    $until = (int) ($record['lock_until'] ?? 0);
    if ($until > $now) {
        return array('locked' => true, 'seconds_left' => $until - $now);
    }
    return array('locked' => false, 'seconds_left' => 0);
}

function admin_record_login_failure($email)
{
    $locks = admin_read_login_locks();
    $key = admin_login_lock_key($email);
    $now = time();
    $record = isset($locks[$key]) && is_array($locks[$key]) ? $locks[$key] : array();
    if (!empty($record['lock_until']) && (int) $record['lock_until'] > $now) {
        return $record;
    }

    $windowStarted = (int) ($record['window_started_at'] ?? 0);
    if ($windowStarted <= 0 || ($now - $windowStarted) > 15 * 60) {
        $record = array(
            'email_hash' => hash('sha256', admin_normalize_email($email)),
            'ip_hash' => hash('sha256', admin_client_ip()),
            'window_started_at' => $now,
            'fail_count' => 0,
            'lock_until' => 0,
        );
    }

    $record['fail_count'] = (int) ($record['fail_count'] ?? 0) + 1;
    $record['last_failed_at'] = $now;
    if ($record['fail_count'] >= 8) {
        $record['lock_until'] = $now + 15 * 60;
        $record['fail_count'] = 0;
        admin_log_security_event('login_lock', admin_normalize_email($email));
    }

    $locks[$key] = $record;
    admin_save_login_locks($locks);
    return $record;
}

function admin_clear_login_failures($email)
{
    $locks = admin_read_login_locks();
    $key = admin_login_lock_key($email);
    if (isset($locks[$key])) {
        unset($locks[$key]);
        admin_save_login_locks($locks);
    }
}

function admin_generate_setup_token()
{
    $raw = strtoupper(bin2hex(random_bytes(6)));
    return substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4);
}

function admin_setup_token_is_valid($user, $token)
{
    if (!is_array($user) || empty($user['password_setup_token_hash'])) {
        return false;
    }
    if (!empty($user['password_setup_token_used_at'])) {
        return false;
    }
    $created = strtotime((string) ($user['password_setup_token_created_at'] ?? ''));
    if ($created && (time() - $created) > 14 * 24 * 60 * 60) {
        return false;
    }
    return password_verify(strtoupper(trim((string) $token)), (string) $user['password_setup_token_hash']);
}

function admin_password_reset_token_is_valid($user, $token)
{
    if (!is_array($user) || empty($user['password_reset_token_hash'])) {
        return false;
    }
    if (!empty($user['password_reset_token_used_at'])) {
        return false;
    }
    $created = strtotime((string) ($user['password_reset_token_created_at'] ?? ''));
    if (!$created || (time() - $created) > 60 * 60) {
        return false;
    }
    return password_verify(strtoupper(trim((string) $token)), (string) $user['password_reset_token_hash']);
}

function admin_send_password_reset_email($user, $token, &$error = null)
{
    if (!is_array($user) || empty($user['email'])) {
        $error = 'Usuario inválido.';
        return false;
    }

    require_once __DIR__ . '/mailer.php';

    $email = admin_normalize_email($user['email']);
    $name = admin_user_display_name($user);
    $subject = 'Código para recuperar contraseña del calendario';
    $body = "Hola " . ($name !== '' ? $name : $email) . ",\n\n"
        . "Recibimos una solicitud para recuperar la contraseña del calendario privado.\n\n"
        . "Tu código de recuperación es: " . $token . "\n\n"
        . "Este código vence en 60 minutos y solo sirve para el calendario en /admin/. "
        . "No corresponde a Webmail, Sofia ni Gmail.\n\n"
        . "Si no solicitaste este cambio, avisa a administración.";

    $html = '<p>Hola ' . htmlspecialchars($name !== '' ? $name : $email, ENT_QUOTES, 'UTF-8') . ',</p>'
        . '<p>Recibimos una solicitud para recuperar la contraseña del calendario privado.</p>'
        . '<p style="font-size:22px;font-weight:700;letter-spacing:.08em">' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p>Este código vence en 60 minutos y solo sirve para el calendario en <strong>/admin/</strong>. No corresponde a Webmail, Sofia ni Gmail.</p>'
        . '<p>Si no solicitaste este cambio, avisa a administración.</p>';

    return castel_mailer_send($email, $subject, $body, $error, $html);
}

function admin_log_security_event($event, $email = '')
{
    $line = json_encode(array(
        'at' => date('c'),
        'event' => (string) $event,
        'email' => admin_normalize_email($email),
        'ip_hash' => hash('sha256', admin_client_ip()),
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents(admin_security_log_path(), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}
