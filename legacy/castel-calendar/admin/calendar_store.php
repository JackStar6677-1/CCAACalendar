<?php
require_once __DIR__ . '/auth.php';

function calendar_store_path()
{
    return __DIR__ . '/../data/calendar_store.json';
}

function calendar_store_backend_path()
{
    return __DIR__ . '/../data/calendar_backend.json';
}

function calendar_store_default()
{
    return array(
        'version' => 2,
        'meta' => array(
            'last_reservation_id' => 0,
            'last_change_request_id' => 0,
            'last_block_id' => 0,
            'last_block_change_request_id' => 0,
            'last_incidence_id' => 0,
        ),
        'reservations' => array(),
        'custom_holidays' => array(),
        'change_requests' => array(),
        'block_reservations' => array(),
        'block_change_requests' => array(),
        'course_rosters' => array(),
        'incidences' => array(),
        'audit_log' => array(),
    );
}

function calendar_store_backend_default()
{
    return array('backend' => 'json');
}

function calendar_store_backend_mode()
{
    $path = calendar_store_backend_path();
    if (!is_file($path)) {
        return 'json';
    }

    $raw = file_get_contents($path);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return 'json';
    }

    $backend = strtolower(trim((string) ($decoded['backend'] ?? 'json')));
    return in_array($backend, array('json', 'mysql', 'mysql_full'), true) ? $backend : 'json';
}

function calendar_store_set_backend_mode($backend)
{
    $backend = strtolower(trim((string) $backend));
    if (!in_array($backend, array('json', 'mysql', 'mysql_full'), true)) {
        $backend = 'json';
    }

    file_put_contents(
        calendar_store_backend_path(),
        json_encode(array('backend' => $backend), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    return $backend;
}

function calendar_store_json_read_file($path = null)
{
    $path = $path ?: calendar_store_path();
    if (!is_file($path)) {
        return calendar_store_default();
    }

    $raw = file_get_contents($path);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return calendar_store_default();
    }

    return array_replace_recursive(calendar_store_default(), $decoded);
}

function calendar_store_ensure_file()
{
    $path = calendar_store_path();
    if (file_exists($path)) {
        return;
    }

    file_put_contents(
        $path,
        json_encode(calendar_store_default(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function calendar_store_db_config()
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = __DIR__ . '/../wp-config.php';
    if (!is_file($path)) {
        $config = null;
        return $config;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        $config = null;
        return $config;
    }

    $keys = array('DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST');
    $parsed = array();

    foreach ($keys as $key) {
        if (preg_match('/define\s*\(\s*[\'"]' . preg_quote($key, '/') . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)\s*;/', $raw, $match) !== 1) {
            $config = null;
            return $config;
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

function calendar_store_db_connect()
{
    $config = calendar_store_db_config();
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

function calendar_store_mysql_table_name()
{
    return 'castel_calendar_store_state';
}

function calendar_store_mysql_schema_sql()
{
    return 'CREATE TABLE IF NOT EXISTS `' . calendar_store_mysql_table_name() . '` (
        `id` TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        `store_json` LONGTEXT NOT NULL,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
}

function calendar_store_mysql_ensure($conn)
{
    if (!$conn) {
        return false;
    }

    $schema = calendar_store_mysql_schema_sql();
    if (!mysqli_query($conn, $schema)) {
        return false;
    }

    $table = calendar_store_mysql_table_name();
    $result = mysqli_query($conn, 'SELECT `id` FROM `' . $table . '` WHERE `id` = 1 LIMIT 1');
    if (!$result) {
        return false;
    }

    if (mysqli_num_rows($result) === 0) {
        $seed = json_encode(calendar_store_default(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = mysqli_prepare($conn, 'INSERT INTO `' . $table . '` (`id`, `store_json`) VALUES (1, ?)');
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 's', $seed);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        if (!$ok) {
            return false;
        }
    }

    mysqli_free_result($result);
    return true;
}

function calendar_store_mysql_read($conn)
{
    if (!$conn || !calendar_store_mysql_ensure($conn)) {
        return null;
    }

    $table = calendar_store_mysql_table_name();
    $result = mysqli_query($conn, 'SELECT `store_json` FROM `' . $table . '` WHERE `id` = 1 LIMIT 1');
    if (!$result) {
        return null;
    }

    $row = mysqli_fetch_assoc($result);
    mysqli_free_result($result);

    if (!$row) {
        return calendar_store_default();
    }

    $decoded = json_decode((string) ($row['store_json'] ?? ''), true);
    if (!is_array($decoded)) {
        return calendar_store_default();
    }

    return array_replace_recursive(calendar_store_default(), $decoded);
}

function calendar_store_mysql_write($conn, $store)
{
    if (!$conn || !calendar_store_mysql_ensure($conn)) {
        return false;
    }

    $table = calendar_store_mysql_table_name();
    $payload = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = mysqli_prepare($conn, 'UPDATE `' . $table . '` SET `store_json` = ? WHERE `id` = 1');
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, 's', $payload);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return (bool) $ok;
}

function calendar_store_mysql_full_tables()
{
    return array(
        'meta' => 'castel_calendar_meta',
        'reservations' => 'castel_calendar_reservations',
        'change_requests' => 'castel_calendar_change_requests',
        'block_reservations' => 'castel_calendar_block_reservations',
        'block_change_requests' => 'castel_calendar_block_change_requests',
        'custom_holidays' => 'castel_calendar_custom_holidays',
        'course_rosters' => 'castel_calendar_course_rosters',
        'incidences' => 'castel_calendar_incidences',
        'audit_log' => 'castel_calendar_audit_log',
    );
}

function calendar_store_mysql_full_schema_sql()
{
    $t = calendar_store_mysql_full_tables();
    return array(
        'CREATE TABLE IF NOT EXISTS `' . $t['meta'] . '` (
            `meta_key` VARCHAR(80) NOT NULL PRIMARY KEY,
            `meta_value` BIGINT NOT NULL DEFAULT 0,
            `payload_json` LONGTEXT NULL,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS `' . $t['reservations'] . '` (
            `reservation_key` VARCHAR(80) NOT NULL PRIMARY KEY,
            `id` INT NOT NULL DEFAULT 0,
            `room` VARCHAR(20) NOT NULL,
            `date_key` DATE NOT NULL,
            `status` VARCHAR(30) NOT NULL,
            `owner_email` VARCHAR(255) NOT NULL DEFAULT \'\',
            `owner_name` VARCHAR(255) NOT NULL DEFAULT \'\',
            `version` INT NOT NULL DEFAULT 1,
            `payload_json` LONGTEXT NOT NULL,
            `created_at` VARCHAR(40) NULL,
            `updated_at` VARCHAR(40) NULL,
            INDEX `idx_room_date` (`room`, `date_key`),
            INDEX `idx_owner` (`owner_email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS `' . $t['block_reservations'] . '` (
            `block_key` VARCHAR(100) NOT NULL PRIMARY KEY,
            `id` INT NOT NULL DEFAULT 0,
            `room` VARCHAR(20) NOT NULL,
            `date_key` DATE NOT NULL,
            `slot_id` VARCHAR(10) NOT NULL,
            `status` VARCHAR(30) NOT NULL,
            `owner_email` VARCHAR(255) NOT NULL DEFAULT \'\',
            `owner_name` VARCHAR(255) NOT NULL DEFAULT \'\',
            `curso` VARCHAR(120) NOT NULL DEFAULT \'\',
            `docente` VARCHAR(255) NOT NULL DEFAULT \'\',
            `version` INT NOT NULL DEFAULT 1,
            `payload_json` LONGTEXT NOT NULL,
            `created_at` VARCHAR(40) NULL,
            `updated_at` VARCHAR(40) NULL,
            UNIQUE KEY `uniq_room_date_slot` (`room`, `date_key`, `slot_id`),
            INDEX `idx_owner` (`owner_email`),
            INDEX `idx_date` (`date_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS `' . $t['change_requests'] . '` (
            `id` INT NOT NULL PRIMARY KEY,
            `room` VARCHAR(20) NOT NULL,
            `date_key` DATE NOT NULL,
            `owner_email` VARCHAR(255) NOT NULL DEFAULT \'\',
            `requested_by_email` VARCHAR(255) NOT NULL DEFAULT \'\',
            `approval_status` VARCHAR(30) NOT NULL DEFAULT \'pendiente\',
            `payload_json` LONGTEXT NOT NULL,
            `created_at` VARCHAR(40) NULL,
            INDEX `idx_room_date` (`room`, `date_key`),
            INDEX `idx_owner` (`owner_email`),
            INDEX `idx_requester` (`requested_by_email`),
            INDEX `idx_status` (`approval_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS `' . $t['block_change_requests'] . '` (
            `id` INT NOT NULL PRIMARY KEY,
            `room` VARCHAR(20) NOT NULL,
            `date_key` DATE NOT NULL,
            `slot_id` VARCHAR(10) NOT NULL DEFAULT \'\',
            `owner_email` VARCHAR(255) NOT NULL DEFAULT \'\',
            `requested_by_email` VARCHAR(255) NOT NULL DEFAULT \'\',
            `approval_status` VARCHAR(30) NOT NULL DEFAULT \'pendiente\',
            `payload_json` LONGTEXT NOT NULL,
            `created_at` VARCHAR(40) NULL,
            INDEX `idx_room_date_slot` (`room`, `date_key`, `slot_id`),
            INDEX `idx_owner` (`owner_email`),
            INDEX `idx_requester` (`requested_by_email`),
            INDEX `idx_status` (`approval_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS `' . $t['custom_holidays'] . '` (
            `date_key` DATE NOT NULL PRIMARY KEY,
            `year_key` INT NOT NULL,
            `label` VARCHAR(255) NOT NULL DEFAULT \'\',
            `payload_json` LONGTEXT NOT NULL,
            `updated_at` VARCHAR(40) NULL,
            INDEX `idx_year` (`year_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS `' . $t['course_rosters'] . '` (
            `course_key` VARCHAR(120) NOT NULL PRIMARY KEY,
            `payload_json` LONGTEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS `' . $t['incidences'] . '` (
            `id` INT NOT NULL PRIMARY KEY,
            `room` VARCHAR(20) NOT NULL,
            `date_key` DATE NOT NULL,
            `slot_id` VARCHAR(10) NOT NULL DEFAULT \'\',
            `reported_by_email` VARCHAR(255) NOT NULL DEFAULT \'\',
            `payload_json` LONGTEXT NOT NULL,
            `created_at` VARCHAR(40) NULL,
            INDEX `idx_room_date_slot` (`room`, `date_key`, `slot_id`),
            INDEX `idx_reporter` (`reported_by_email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS `' . $t['audit_log'] . '` (
            `id` INT NOT NULL PRIMARY KEY,
            `reservation_key` VARCHAR(120) NOT NULL DEFAULT \'\',
            `action_type` VARCHAR(80) NOT NULL DEFAULT \'\',
            `performed_by` VARCHAR(255) NOT NULL DEFAULT \'\',
            `old_payload_json` LONGTEXT NULL,
            `new_payload_json` LONGTEXT NULL,
            `payload_json` LONGTEXT NOT NULL,
            `created_at` VARCHAR(40) NULL,
            INDEX `idx_reservation_key` (`reservation_key`),
            INDEX `idx_action` (`action_type`),
            INDEX `idx_performed_by` (`performed_by`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    );
}

function calendar_store_mysql_full_ensure($conn)
{
    if (!$conn) {
        return false;
    }

    foreach (calendar_store_mysql_full_schema_sql() as $sql) {
        if (!mysqli_query($conn, $sql)) {
            return false;
        }
    }

    $t = calendar_store_mysql_full_tables();
    $stmt = mysqli_prepare($conn, 'INSERT IGNORE INTO `' . $t['meta'] . '` (`meta_key`, `meta_value`) VALUES (?, 0)');
    if (!$stmt) {
        return false;
    }
    $lockKey = '__lock';
    mysqli_stmt_bind_param($stmt, 's', $lockKey);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return (bool) $ok;
}

function calendar_store_mysql_full_payload($payload)
{
    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function calendar_store_mysql_full_decode($value)
{
    $decoded = json_decode((string) $value, true);
    return is_array($decoded) ? $decoded : array();
}

function calendar_store_mysql_full_read($conn)
{
    if (!$conn || !calendar_store_mysql_full_ensure($conn)) {
        return null;
    }

    $t = calendar_store_mysql_full_tables();
    $store = calendar_store_default();

    $result = mysqli_query($conn, 'SELECT `meta_key`, `meta_value`, `payload_json` FROM `' . $t['meta'] . '`');
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $key = (string) $row['meta_key'];
            if ($key === 'version') {
                $store['version'] = (int) $row['meta_value'];
            } elseif ($key !== '__lock') {
                $store['meta'][$key] = (int) $row['meta_value'];
            }
        }
        mysqli_free_result($result);
    }

    $mapTables = array(
        'reservations' => array($t['reservations'], 'reservation_key'),
        'block_reservations' => array($t['block_reservations'], 'block_key'),
        'course_rosters' => array($t['course_rosters'], 'course_key'),
    );
    foreach ($mapTables as $section => $info) {
        $result = mysqli_query($conn, 'SELECT `' . $info[1] . '`, `payload_json` FROM `' . $info[0] . '`');
        if (!$result) {
            continue;
        }
        while ($row = mysqli_fetch_assoc($result)) {
            $payload = calendar_store_mysql_full_decode($row['payload_json'] ?? '');
            if ($payload) {
                $store[$section][(string) $row[$info[1]]] = $payload;
            }
        }
        mysqli_free_result($result);
    }

    $result = mysqli_query($conn, 'SELECT `date_key`, `year_key`, `payload_json` FROM `' . $t['custom_holidays'] . '` ORDER BY `date_key` ASC');
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $payload = calendar_store_mysql_full_decode($row['payload_json'] ?? '');
            if ($payload) {
                $year = (string) ((int) ($row['year_key'] ?? 0));
                if ($year === '0') {
                    $year = substr((string) ($row['date_key'] ?? ''), 0, 4);
                }
                if ($year !== '') {
                    if (!isset($store['custom_holidays'][$year]) || !is_array($store['custom_holidays'][$year])) {
                        $store['custom_holidays'][$year] = array();
                    }
                    $store['custom_holidays'][$year][(string) $row['date_key']] = $payload;
                }
            }
        }
        mysqli_free_result($result);
    }

    $listTables = array(
        'change_requests' => $t['change_requests'],
        'block_change_requests' => $t['block_change_requests'],
        'incidences' => $t['incidences'],
        'audit_log' => $t['audit_log'],
    );
    foreach ($listTables as $section => $table) {
        $result = mysqli_query($conn, 'SELECT `payload_json` FROM `' . $table . '` ORDER BY `id` ASC');
        if (!$result) {
            continue;
        }
        while ($row = mysqli_fetch_assoc($result)) {
            $payload = calendar_store_mysql_full_decode($row['payload_json'] ?? '');
            if ($payload) {
                $store[$section][] = $payload;
            }
        }
        mysqli_free_result($result);
    }

    return array_replace_recursive(calendar_store_default(), $store);
}

function calendar_store_mysql_full_write($conn, $store)
{
    if (!$conn || !calendar_store_mysql_full_ensure($conn)) {
        return false;
    }

    $t = calendar_store_mysql_full_tables();
    foreach (array('reservations', 'block_reservations', 'change_requests', 'block_change_requests', 'custom_holidays', 'course_rosters', 'incidences', 'audit_log') as $section) {
        if (!mysqli_query($conn, 'DELETE FROM `' . $t[$section] . '`')) {
            return false;
        }
    }

    if (!mysqli_query($conn, 'DELETE FROM `' . $t['meta'] . '` WHERE `meta_key` <> \'__lock\'')) {
        return false;
    }

    $version = (int) ($store['version'] ?? 2);
    $payload = calendar_store_mysql_full_payload(array('version' => $version));
    $stmt = mysqli_prepare($conn, 'INSERT INTO `' . $t['meta'] . '` (`meta_key`, `meta_value`, `payload_json`) VALUES (\'version\', ?, ?)');
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'is', $version, $payload);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok) {
        return false;
    }

    $stmt = mysqli_prepare($conn, 'INSERT INTO `' . $t['meta'] . '` (`meta_key`, `meta_value`, `payload_json`) VALUES (?, ?, ?)');
    if (!$stmt) {
        return false;
    }
    foreach (($store['meta'] ?? array()) as $key => $value) {
        $key = (string) $key;
        $intValue = (int) $value;
        $payload = calendar_store_mysql_full_payload(array('key' => $key, 'value' => $intValue));
        mysqli_stmt_bind_param($stmt, 'sis', $key, $intValue, $payload);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return false;
        }
    }
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, 'INSERT INTO `' . $t['reservations'] . '` (`reservation_key`, `id`, `room`, `date_key`, `status`, `owner_email`, `owner_name`, `version`, `payload_json`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        return false;
    }
    foreach (($store['reservations'] ?? array()) as $key => $reservation) {
        if (!is_array($reservation)) {
            continue;
        }
        $key = (string) $key;
        $id = (int) ($reservation['id'] ?? 0);
        $room = calendar_normalize_room($reservation['room'] ?? '');
        $date = calendar_is_valid_date_key($reservation['date'] ?? '') ? (string) $reservation['date'] : '1970-01-01';
        $status = calendar_normalize_status($reservation['status'] ?? '');
        $ownerEmail = (string) ($reservation['owner_email'] ?? '');
        $ownerName = (string) ($reservation['owner_name'] ?? '');
        $version = (int) ($reservation['version'] ?? 1);
        $payload = calendar_store_mysql_full_payload($reservation);
        $createdAt = (string) ($reservation['created_at'] ?? '');
        $updatedAt = (string) ($reservation['updated_at'] ?? '');
        mysqli_stmt_bind_param($stmt, 'sisssssisss', $key, $id, $room, $date, $status, $ownerEmail, $ownerName, $version, $payload, $createdAt, $updatedAt);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return false;
        }
    }
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, 'INSERT INTO `' . $t['block_reservations'] . '` (`block_key`, `id`, `room`, `date_key`, `slot_id`, `status`, `owner_email`, `owner_name`, `curso`, `docente`, `version`, `payload_json`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        return false;
    }
    foreach (($store['block_reservations'] ?? array()) as $key => $block) {
        if (!is_array($block)) {
            continue;
        }
        $key = (string) $key;
        $id = (int) ($block['id'] ?? 0);
        $room = calendar_normalize_room($block['room'] ?? '');
        $date = calendar_is_valid_date_key($block['date'] ?? '') ? (string) $block['date'] : '1970-01-01';
        $slotId = calendar_normalize_slot_id($block['slot_id'] ?? '');
        $status = calendar_normalize_status($block['status'] ?? '');
        $ownerEmail = (string) ($block['owner_email'] ?? '');
        $ownerName = (string) ($block['owner_name'] ?? '');
        $curso = (string) ($block['curso'] ?? '');
        $docente = (string) ($block['docente'] ?? '');
        $version = (int) ($block['version'] ?? 1);
        $payload = calendar_store_mysql_full_payload($block);
        $createdAt = (string) ($block['created_at'] ?? '');
        $updatedAt = (string) ($block['updated_at'] ?? '');
        mysqli_stmt_bind_param($stmt, 'sissssssssisss', $key, $id, $room, $date, $slotId, $status, $ownerEmail, $ownerName, $curso, $docente, $version, $payload, $createdAt, $updatedAt);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return false;
        }
    }
    mysqli_stmt_close($stmt);

    if (!calendar_store_mysql_full_write_request_table($conn, $t['change_requests'], $store['change_requests'] ?? array(), false)) {
        return false;
    }
    if (!calendar_store_mysql_full_write_request_table($conn, $t['block_change_requests'], $store['block_change_requests'] ?? array(), true)) {
        return false;
    }
    if (!calendar_store_mysql_full_write_holidays($conn, $t['custom_holidays'], $store['custom_holidays'] ?? array())) {
        return false;
    }
    if (!calendar_store_mysql_full_write_rosters($conn, $t['course_rosters'], $store['course_rosters'] ?? array())) {
        return false;
    }
    if (!calendar_store_mysql_full_write_incidences($conn, $t['incidences'], $store['incidences'] ?? array())) {
        return false;
    }
    if (!calendar_store_mysql_full_write_audit($conn, $t['audit_log'], $store['audit_log'] ?? array())) {
        return false;
    }

    return true;
}

function calendar_store_mysql_full_write_request_table($conn, $table, $items, $hasSlot)
{
    $sql = $hasSlot
        ? 'INSERT INTO `' . $table . '` (`id`, `room`, `date_key`, `slot_id`, `owner_email`, `requested_by_email`, `approval_status`, `payload_json`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        : 'INSERT INTO `' . $table . '` (`id`, `room`, `date_key`, `owner_email`, `requested_by_email`, `approval_status`, `payload_json`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return false;
    }
    $nextId = 1;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = (int) ($item['id'] ?? 0);
        if ($id <= 0) {
            $id = $nextId;
        }
        $nextId = max($nextId, $id + 1);
        $room = calendar_normalize_room($item['room'] ?? '');
        $date = calendar_is_valid_date_key($item['date'] ?? '') ? (string) $item['date'] : '1970-01-01';
        $slotId = calendar_normalize_slot_id($item['slot_id'] ?? '');
        $ownerEmail = (string) ($item['owner_email'] ?? '');
        $requester = (string) ($item['requested_by_email'] ?? '');
        $status = (string) ($item['approval_status'] ?? 'pendiente');
        $payload = calendar_store_mysql_full_payload($item);
        $createdAt = (string) ($item['created_at'] ?? '');
        if ($hasSlot) {
            mysqli_stmt_bind_param($stmt, 'issssssss', $id, $room, $date, $slotId, $ownerEmail, $requester, $status, $payload, $createdAt);
        } else {
            mysqli_stmt_bind_param($stmt, 'isssssss', $id, $room, $date, $ownerEmail, $requester, $status, $payload, $createdAt);
        }
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return false;
        }
    }
    mysqli_stmt_close($stmt);
    return true;
}

function calendar_store_mysql_full_write_holidays($conn, $table, $items)
{
    $stmt = mysqli_prepare($conn, 'INSERT INTO `' . $table . '` (`date_key`, `year_key`, `label`, `payload_json`, `updated_at`) VALUES (?, ?, ?, ?, ?)');
    if (!$stmt) {
        return false;
    }
    foreach ($items as $yearOrDate => $holidaySet) {
        if (is_array($holidaySet) && preg_match('/^\d{4}$/', (string) $yearOrDate) === 1) {
            foreach ($holidaySet as $date => $holiday) {
                $payload = is_array($holiday) ? $holiday : array('date' => (string) $date, 'label' => (string) $holiday);
                $dateKey = calendar_is_valid_date_key($date) ? (string) $date : '1970-01-01';
                $year = (int) substr($dateKey, 0, 4);
                $label = (string) ($payload['label'] ?? '');
                $payloadJson = calendar_store_mysql_full_payload($payload);
                $updatedAt = (string) ($payload['updated_at'] ?? '');
                mysqli_stmt_bind_param($stmt, 'sisss', $dateKey, $year, $label, $payloadJson, $updatedAt);
                if (!mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    return false;
                }
            }
        } else {
            $payload = is_array($holidaySet) ? $holidaySet : array('date' => (string) $yearOrDate, 'label' => (string) $holidaySet);
            $dateKey = calendar_is_valid_date_key($yearOrDate) ? (string) $yearOrDate : '1970-01-01';
            $year = (int) substr($dateKey, 0, 4);
            $label = (string) ($payload['label'] ?? '');
            $payloadJson = calendar_store_mysql_full_payload($payload);
            $updatedAt = (string) ($payload['updated_at'] ?? '');
            mysqli_stmt_bind_param($stmt, 'sisss', $dateKey, $year, $label, $payloadJson, $updatedAt);
            if (!mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                return false;
            }
        }
    }
    mysqli_stmt_close($stmt);
    return true;
}

function calendar_store_mysql_full_write_rosters($conn, $table, $items)
{
    $stmt = mysqli_prepare($conn, 'INSERT INTO `' . $table . '` (`course_key`, `payload_json`) VALUES (?, ?)');
    if (!$stmt) {
        return false;
    }
    foreach ($items as $course => $roster) {
        $course = (string) $course;
        $payload = calendar_store_mysql_full_payload($roster);
        mysqli_stmt_bind_param($stmt, 'ss', $course, $payload);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return false;
        }
    }
    mysqli_stmt_close($stmt);
    return true;
}

function calendar_store_mysql_full_write_incidences($conn, $table, $items)
{
    $stmt = mysqli_prepare($conn, 'INSERT INTO `' . $table . '` (`id`, `room`, `date_key`, `slot_id`, `reported_by_email`, `payload_json`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        return false;
    }
    $nextId = 1;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = (int) ($item['id'] ?? 0);
        if ($id <= 0) {
            $id = $nextId;
        }
        $nextId = max($nextId, $id + 1);
        $room = calendar_normalize_room($item['room'] ?? '');
        $date = calendar_is_valid_date_key($item['date'] ?? '') ? (string) $item['date'] : '1970-01-01';
        $slotId = calendar_normalize_slot_id($item['slot_id'] ?? '');
        $reportedBy = (string) ($item['reported_by_email'] ?? '');
        $payload = calendar_store_mysql_full_payload($item);
        $createdAt = (string) ($item['created_at'] ?? '');
        mysqli_stmt_bind_param($stmt, 'issssss', $id, $room, $date, $slotId, $reportedBy, $payload, $createdAt);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return false;
        }
    }
    mysqli_stmt_close($stmt);
    return true;
}

function calendar_store_mysql_full_write_audit($conn, $table, $items)
{
    $stmt = mysqli_prepare($conn, 'INSERT INTO `' . $table . '` (`id`, `reservation_key`, `action_type`, `performed_by`, `old_payload_json`, `new_payload_json`, `payload_json`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        return false;
    }
    $nextId = 1;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = (int) ($item['id'] ?? 0);
        if ($id <= 0) {
            $id = $nextId;
        }
        $nextId = max($nextId, $id + 1);
        $reservationKey = (string) ($item['reservation_key'] ?? '');
        $actionType = (string) ($item['action_type'] ?? '');
        $performedBy = (string) ($item['performed_by'] ?? '');
        $oldPayload = array_key_exists('old_payload', $item) ? calendar_store_mysql_full_payload($item['old_payload']) : null;
        $newPayload = array_key_exists('new_payload', $item) ? calendar_store_mysql_full_payload($item['new_payload']) : null;
        $payload = calendar_store_mysql_full_payload($item);
        $createdAt = (string) ($item['created_at'] ?? '');
        mysqli_stmt_bind_param($stmt, 'isssssss', $id, $reservationKey, $actionType, $performedBy, $oldPayload, $newPayload, $payload, $createdAt);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return false;
        }
    }
    mysqli_stmt_close($stmt);
    return true;
}

function calendar_store_read_all()
{
    $backend = calendar_store_backend_mode();
    if ($backend === 'mysql_full') {
        $conn = calendar_store_db_connect();
        $store = $conn ? calendar_store_mysql_full_read($conn) : null;
        if ($conn) {
            @mysqli_close($conn);
        }
        if (is_array($store)) {
            return $store;
        }
        return calendar_store_default();
    }

    if ($backend === 'mysql') {
        $conn = calendar_store_db_connect();
        $store = $conn ? calendar_store_mysql_read($conn) : null;
        if ($conn) {
            @mysqli_close($conn);
        }
        if (is_array($store)) {
            return $store;
        }
        return calendar_store_default();
    }

    return calendar_store_json_read_file();
}

function calendar_store_mutate($callback)
{
    $backend = calendar_store_backend_mode();
    if ($backend === 'mysql_full') {
        $conn = calendar_store_db_connect();
        if (!$conn) {
            return array(false, calendar_store_default(), array('ok' => false, 'message' => 'No se pudo conectar a MySQL.'));
        }

        if (!calendar_store_mysql_full_ensure($conn)) {
            @mysqli_close($conn);
            return array(false, calendar_store_default(), array('ok' => false, 'message' => 'No se pudo preparar las tablas MySQL.'));
        }

        if (!@mysqli_begin_transaction($conn)) {
            @mysqli_close($conn);
            return array(false, calendar_store_default(), array('ok' => false, 'message' => 'No se pudo iniciar la transacción.'));
        }

        $t = calendar_store_mysql_full_tables();
        $lock = mysqli_query($conn, 'SELECT `meta_value` FROM `' . $t['meta'] . '` WHERE `meta_key` = "__lock" LIMIT 1 FOR UPDATE');
        if (!$lock) {
            @mysqli_rollback($conn);
            @mysqli_close($conn);
            return array(false, calendar_store_default(), array('ok' => false, 'message' => 'No se pudo bloquear el calendario.'));
        }
        mysqli_free_result($lock);

        $store = calendar_store_mysql_full_read($conn);
        if (!is_array($store)) {
            @mysqli_rollback($conn);
            @mysqli_close($conn);
            return array(false, calendar_store_default(), array('ok' => false, 'message' => 'No se pudo leer el calendario desde MySQL.'));
        }

        $result = call_user_func_array($callback, array(&$store));
        if (!is_array($result)) {
            $result = array('ok' => true);
        }

        if (!array_key_exists('persist', $result) || $result['persist'] !== false) {
            if (!calendar_store_mysql_full_write($conn, $store)) {
                @mysqli_rollback($conn);
                @mysqli_close($conn);
                return array(false, calendar_store_default(), array('ok' => false, 'message' => 'No se pudo guardar en MySQL.'));
            }
        }

        if (!@mysqli_commit($conn)) {
            @mysqli_rollback($conn);
            @mysqli_close($conn);
            return array(false, calendar_store_default(), array('ok' => false, 'message' => 'No se pudo confirmar la transacción.'));
        }

        @mysqli_close($conn);
        return array(true, $store, $result);
    }

    if ($backend === 'mysql') {
        $conn = calendar_store_db_connect();
        if (!$conn) {
            return array(false, calendar_store_default(), array('ok' => false, 'message' => 'No se pudo conectar a MySQL.'));
        }

        if (!calendar_store_mysql_ensure($conn)) {
            @mysqli_close($conn);
            return array(false, calendar_store_default(), array('ok' => false, 'message' => 'No se pudo preparar la tabla MySQL.'));
        }

        if (!@mysqli_begin_transaction($conn)) {
            @mysqli_close($conn);
            return array(false, calendar_store_default(), array('ok' => false, 'message' => 'No se pudo iniciar la transacción.'));
        }

        $table = calendar_store_mysql_table_name();
        $result = mysqli_query($conn, 'SELECT `store_json` FROM `' . $table . '` WHERE `id` = 1 LIMIT 1 FOR UPDATE');
        if (!$result) {
            @mysqli_rollback($conn);
            @mysqli_close($conn);
            return array(false, calendar_store_default(), array('ok' => false, 'message' => 'No se pudo leer el estado del calendario.'));
        }

        $row = mysqli_fetch_assoc($result);
        mysqli_free_result($result);
        $store = calendar_store_default();
        if ($row && !empty($row['store_json'])) {
            $decoded = json_decode((string) $row['store_json'], true);
            if (is_array($decoded)) {
                $store = array_replace_recursive(calendar_store_default(), $decoded);
            }
        }

        $result = call_user_func_array($callback, array(&$store));
        if (!is_array($result)) {
            $result = array('ok' => true);
        }

        if (!array_key_exists('persist', $result) || $result['persist'] !== false) {
            if (!calendar_store_mysql_write($conn, $store)) {
                @mysqli_rollback($conn);
                @mysqli_close($conn);
                return array(false, calendar_store_default(), array('ok' => false, 'message' => 'No se pudo guardar en MySQL.'));
            }
        }

        if (!@mysqli_commit($conn)) {
            @mysqli_rollback($conn);
            @mysqli_close($conn);
            return array(false, calendar_store_default(), array('ok' => false, 'message' => 'No se pudo confirmar la transacción.'));
        }

        @mysqli_close($conn);
        return array(true, $store, $result);
    }

    calendar_store_ensure_file();
    $path = calendar_store_path();
    $handle = fopen($path, 'c+');
    if (!$handle) {
        return array(false, calendar_store_default(), array('ok' => false, 'message' => 'No se pudo abrir el almacenamiento.'));
    }

    flock($handle, LOCK_EX);
    rewind($handle);
    $raw = stream_get_contents($handle);
    $store = json_decode($raw, true);
    if (!is_array($store)) {
        $store = calendar_store_default();
    } else {
        $store = array_replace_recursive(calendar_store_default(), $store);
    }

    $result = call_user_func_array($callback, array(&$store));
    if (!is_array($result)) {
        $result = array('ok' => true);
    }

    if (!array_key_exists('persist', $result) || $result['persist'] !== false) {
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($handle);
    }

    flock($handle, LOCK_UN);
    fclose($handle);

    return array(true, $store, $result);
}

function calendar_normalize_room($room)
{
    $room = strtolower(trim((string) $room));
    return in_array($room, array('basica', 'media'), true) ? $room : 'basica';
}

function calendar_normalize_semester($semester)
{
    $semester = strtolower(trim((string) $semester));
    return in_array($semester, array('s1', 's2'), true) ? $semester : 's1';
}

function calendar_normalize_status($status)
{
    $status = strtolower(trim((string) $status));
    return in_array($status, array('disponible', 'reservada', 'mantenimiento', 'bloqueada', 'liberar'), true) ? $status : 'disponible';
}

function calendar_is_valid_date_key($date)
{
    return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1;
}

function calendar_get_semester_bounds($year, $semester)
{
    $year = (int) $year;
    $semester = calendar_normalize_semester($semester);

    if ($semester === 's1') {
        return array(
            'start' => sprintf('%04d-03-01', $year),
            'end' => sprintf('%04d-07-31', $year),
        );
    }

    return array(
        'start' => sprintf('%04d-08-01', $year),
        'end' => sprintf('%04d-12-31', $year),
    );
}

function calendar_date_in_semester($date, $year, $semester)
{
    if (!calendar_is_valid_date_key($date)) {
        return false;
    }

    $bounds = calendar_get_semester_bounds($year, $semester);
    return $date >= $bounds['start'] && $date <= $bounds['end'];
}

function calendar_reservation_key($room, $date)
{
    return calendar_normalize_room($room) . ':' . $date;
}

function calendar_get_reservation($store, $room, $date)
{
    $key = calendar_reservation_key($room, $date);
    return isset($store['reservations'][$key]) && is_array($store['reservations'][$key]) ? $store['reservations'][$key] : null;
}

function calendar_set_reservation(&$store, $room, $date, $payload)
{
    $store['reservations'][calendar_reservation_key($room, $date)] = $payload;
}

function calendar_remove_reservation(&$store, $room, $date)
{
    unset($store['reservations'][calendar_reservation_key($room, $date)]);
}

function calendar_append_audit(&$store, $actionType, $performedBy, $reservationKey, $oldPayload, $newPayload)
{
    $store['audit_log'][] = array(
        'id' => count($store['audit_log']) + 1,
        'reservation_key' => $reservationKey,
        'action_type' => $actionType,
        'performed_by' => $performedBy,
        'old_payload' => $oldPayload,
        'new_payload' => $newPayload,
        'created_at' => date('c'),
    );
}

function calendar_user_can_override($user)
{
    return admin_user_has_calendar_override($user);
}

function calendar_user_can_manage_holidays($user)
{
    return admin_user_can_manage_holidays($user);
}

function calendar_store_export_period($store, $year, $room, $semester)
{
    $room = calendar_normalize_room($room);
    $semester = calendar_normalize_semester($semester);
    $reservations = array();

    foreach ($store['reservations'] as $reservation) {
        if (!is_array($reservation)) {
            continue;
        }
        if (($reservation['room'] ?? '') !== $room) {
            continue;
        }
        if (!calendar_date_in_semester($reservation['date'] ?? '', $year, $semester)) {
            continue;
        }
        $reservations[$reservation['date']] = $reservation;
    }

    $customHolidays = array();
    if (isset($store['custom_holidays'][(string) $year]) && is_array($store['custom_holidays'][(string) $year])) {
        foreach ($store['custom_holidays'][(string) $year] as $date => $holiday) {
            if (calendar_date_in_semester($date, $year, $semester)) {
                $customHolidays[$date] = $holiday;
            }
        }
    }

    return array(
        'version' => 2,
        'exported_at' => date('c'),
        'year' => (int) $year,
        'room' => $room,
        'semester' => $semester,
        'reservations' => $reservations,
        'custom_holidays' => $customHolidays,
    );
}

// =========================================================
// === Funciones de Bloques Horarios =======================
// =========================================================

function calendar_normalize_slot_id($slotId)
{
    $slotId = strtolower(trim((string) $slotId));
    return in_array($slotId, array('b1', 'b2', 'b3', 'b4', 'b5', 'r1', 'r2', 'r3', 'a1'), true) ? $slotId : '';
}

function calendar_block_catalog()
{
    return array(
        array('slot_id' => 'b1', 'id' => 1, 'nombre' => 'Bloque 1', 'hora_inicio' => '08:00', 'hora_fin' => '09:30', 'tipo' => 'clase', 'es_bloqueado' => false),
        array('slot_id' => 'r1', 'id' => 4, 'nombre' => 'Recreo 1', 'hora_inicio' => '09:30', 'hora_fin' => '09:45', 'tipo' => 'recreo', 'es_bloqueado' => true),
        array('slot_id' => 'b2', 'id' => 2, 'nombre' => 'Bloque 2', 'hora_inicio' => '09:45', 'hora_fin' => '11:15', 'tipo' => 'clase', 'es_bloqueado' => false),
        array('slot_id' => 'r2', 'id' => 5, 'nombre' => 'Recreo 2', 'hora_inicio' => '11:15', 'hora_fin' => '11:30', 'tipo' => 'recreo', 'es_bloqueado' => true),
        array('slot_id' => 'b3', 'id' => 3, 'nombre' => 'Bloque 3', 'hora_inicio' => '11:30', 'hora_fin' => '13:00', 'tipo' => 'clase', 'es_bloqueado' => false),
        array('slot_id' => 'a1', 'id' => 6, 'nombre' => 'Almuerzo', 'hora_inicio' => '13:00', 'hora_fin' => '14:00', 'tipo' => 'almuerzo', 'es_bloqueado' => true),
        array('slot_id' => 'b4', 'id' => 7, 'nombre' => 'Bloque 4', 'hora_inicio' => '14:00', 'hora_fin' => '15:30', 'tipo' => 'clase', 'es_bloqueado' => false),
        array('slot_id' => 'r3', 'id' => 9, 'nombre' => 'Recreo 3', 'hora_inicio' => '15:30', 'hora_fin' => '15:45', 'tipo' => 'recreo', 'es_bloqueado' => true),
        array('slot_id' => 'b5', 'id' => 8, 'nombre' => 'Bloque 5', 'hora_inicio' => '15:45', 'hora_fin' => '17:15', 'tipo' => 'clase', 'es_bloqueado' => false),
    );
}

function calendar_block_meta($slotId)
{
    $slotId = calendar_normalize_slot_id($slotId);
    if ($slotId === '') {
        return null;
    }
    foreach (calendar_block_catalog() as $slot) {
        if (($slot['slot_id'] ?? '') === $slotId) {
            return $slot;
        }
    }
    return null;
}

function calendar_block_key($room, $date, $slotId)
{
    return calendar_normalize_room($room) . ':' . $date . ':' . $slotId;
}

function calendar_get_block($store, $room, $date, $slotId)
{
    $key = calendar_block_key($room, $date, $slotId);
    return isset($store['block_reservations'][$key]) && is_array($store['block_reservations'][$key])
        ? $store['block_reservations'][$key]
        : null;
}

function calendar_set_block(&$store, $room, $date, $slotId, $payload)
{
    $store['block_reservations'][calendar_block_key($room, $date, $slotId)] = $payload;
}

function calendar_remove_block(&$store, $room, $date, $slotId)
{
    unset($store['block_reservations'][calendar_block_key($room, $date, $slotId)]);
}

function calendar_get_blocks_for_day($store, $room, $date)
{
    $room   = calendar_normalize_room($room);
    $prefix = $room . ':' . $date . ':';
    $blocks = array();
    foreach ($store['block_reservations'] as $key => $block) {
        if (strpos($key, $prefix) !== 0 || !is_array($block)) {
            continue;
        }
        $slotId = $block['slot_id'] ?? '';
        if ($slotId !== '') {
            $blocks[$slotId] = $block;
        }
    }
    return $blocks;
}

function calendar_get_block_summaries($store, $room, $year, $semester)
{
    $room      = calendar_normalize_room($room);
    $prefix    = $room . ':';
    $summaries = array();
    foreach ($store['block_reservations'] as $key => $block) {
        if (strpos($key, $prefix) !== 0 || !is_array($block)) {
            continue;
        }
        $date = $block['date'] ?? '';
        if (!calendar_date_in_semester($date, $year, $semester)) {
            continue;
        }
        $slotMeta = calendar_block_meta($block['slot_id'] ?? '');
        if (!$slotMeta || !empty($slotMeta['es_bloqueado'])) {
            continue;
        }
        $status = calendar_normalize_status($block['status'] ?? 'disponible');
        if ($status !== 'disponible') {
            $summaries[$date] = ($summaries[$date] ?? 0) + 1;
        }
    }
    return $summaries;
}

function calendar_get_block_pending_requests($store, $user, $year, $room, $month = null)
{
    $email      = admin_normalize_email($user['email']);
    $canOverride = calendar_user_can_override($user);
    $items      = array();
    $monthPrefix = null;
    if ($month !== null) {
        $month = (int) $month;
        if ($month >= 1 && $month <= 12) {
            $monthPrefix = sprintf('%04d-%02d-', (int) $year, $month);
        }
    }
    foreach ($store['block_change_requests'] as $request) {
        if (!is_array($request)) {
            continue;
        }
        if (($request['approval_status'] ?? '') !== 'pendiente') {
            continue;
        }
        if (($request['room'] ?? '') !== $room) {
            continue;
        }
        if (substr($request['date'] ?? '', 0, 4) !== (string) $year) {
            continue;
        }
        if ($monthPrefix !== null && strpos((string) ($request['date'] ?? ''), $monthPrefix) !== 0) {
            continue;
        }
        if (!$canOverride
            && $email !== ($request['owner_email'] ?? '')
            && $email !== ($request['requested_by_email'] ?? '')) {
            continue;
        }
        $items[] = $request;
    }
    usort($items, function ($a, $b) {
        return strcmp(
            ($a['date'] ?? '') . ($a['slot_id'] ?? ''),
            ($b['date'] ?? '') . ($b['slot_id'] ?? '')
        );
    });
    return $items;
}
