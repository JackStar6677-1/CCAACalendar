<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/calendar_store.php';
require_once __DIR__ . '/mailer.php';

admin_bootstrap_session();
admin_require_login();

header('Content-Type: application/json; charset=UTF-8');

function calendar_api_response($payload, $statusCode = 200)
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function calendar_api_input()
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return array();
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function calendar_api_trimmed($value, $maxLength)
{
    $text = trim((string) $value);
    if ($maxLength <= 0 || $text === '') {
        return $text;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength);
    }
    return substr($text, 0, $maxLength);
}

function calendar_api_send_mail($to, $subject, $bodyPlain, $bodyHtml = null)
{
    $to = admin_normalize_email($to);
    if ($to === '') {
        return false;
    }

    $error = null;
    return castel_mailer_send($to, $subject, $bodyPlain, $error, $bodyHtml);
}

function calendar_api_mail_esc($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function calendar_api_base_url()
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? trim((string) $_SERVER['HTTP_HOST']) : 'localhost';
    $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '/admin/calendar_api.php';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return $scheme . '://' . $host . ($dir !== '' ? $dir : '');
}

function calendar_api_mail_calendar_url()
{
    return calendar_api_base_url() . '/calendar.php';
}

function calendar_api_slot_display_for_mail($slotId)
{
    $slotId = (string) $slotId;
    if ($slotId === '') {
        return '';
    }
    $meta = calendar_block_meta($slotId);
    if (is_array($meta) && !empty($meta['nombre'])) {
        return (string) $meta['nombre'] . ' (' . $slotId . ')';
    }
    return $slotId;
}

/**
 * @param array<int, string> $introParagraphs
 * @param array<int, array{k:string,v:string}> $rows
 * @return array{plain:string,html:string}
 */
function calendar_api_notification_bodies($headline, $addressee, array $introParagraphs, array $rows, $ctaLabel = 'Abrir calendario')
{
    $url = calendar_api_mail_calendar_url();
    $name = trim((string) $addressee);
    $plainSalutation = $name !== '' ? $name : 'estimada/o';
    $lines = array();
    $lines[] = 'Hola ' . $plainSalutation . ',';
    $lines[] = '';
    foreach ($introParagraphs as $p) {
        if ((string) $p !== '') {
            $lines[] = (string) $p;
            $lines[] = '';
        }
    }
    foreach ($rows as $row) {
        $k = isset($row['k']) ? (string) $row['k'] : '';
        $v = isset($row['v']) ? (string) $row['v'] : '';
        $lines[] = $k . ': ' . $v;
    }
    $lines[] = '';
    $lines[] = $ctaLabel . ':';
    $lines[] = $url;
    $lines[] = '';
    $lines[] = 'Mensaje automático del panel privado del calendario.';
    $plain = implode("\n", $lines);

    $htmlName = calendar_api_mail_esc($name !== '' ? $name : 'estimada/o');
    $introHtml = '';
    foreach ($introParagraphs as $p) {
        if ((string) $p !== '') {
            $introHtml .= '<p style="margin:0 0 12px;color:#1e293b;font-size:15px;line-height:1.55;">' . calendar_api_mail_esc($p) . '</p>';
        }
    }
    $rowsHtml = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:18px 0 4px;border-collapse:separate;border-spacing:0;background:#f1f5f9;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0">';
    foreach ($rows as $row) {
        $k = calendar_api_mail_esc(isset($row['k']) ? $row['k'] : '');
        $v = calendar_api_mail_esc(isset($row['v']) ? $row['v'] : '');
        $rowsHtml .= '<tr>'
            . '<td valign="top" style="padding:11px 16px;font-weight:700;color:#0f264f;font-size:14px;width:40%;border-bottom:1px solid #e2e8f0;background:rgba(255,255,255,0.7);">' . $k . '</td>'
            . '<td valign="top" style="padding:11px 16px;color:#334155;font-size:14px;line-height:1.45;border-bottom:1px solid #e2e8f0;">' . $v . '</td>'
            . '</tr>';
    }
    $rowsHtml .= '</table>';

    $ctaEsc = calendar_api_mail_esc($ctaLabel);
    $urlEsc = calendar_api_mail_esc($url);
    $headEsc = calendar_api_mail_esc($headline);

    $inner =
        '<p style="margin:0 0 14px;color:#1e293b;font-size:16px;line-height:1.45;">Hola <strong>' . $htmlName . '</strong>,</p>'
        . $introHtml
        . $rowsHtml
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 8px;"><tr>'
        . '<td style="border-radius:999px;background:linear-gradient(135deg,#4E8452,#3a6b3e);">'
        . '<a href="' . $urlEsc . '" style="display:inline-block;padding:14px 28px;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;border-radius:999px;">' . $ctaEsc . '</a>'
        . '</td></tr></table>'
        . '<p style="margin:14px 0 0;font-size:13px;line-height:1.5;color:#64748b;">Si el botón no se muestra, copia este enlace en el navegador:<br>'
        . '<a href="' . $urlEsc . '" style="color:#1f63bb;word-break:break-all;">' . $urlEsc . '</a></p>';

    $logo = calendar_api_base_url() . '/calendar-icon.svg';
    $html = '<!DOCTYPE html><html lang="es"><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#e8edf4;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#e8edf4;padding:20px 10px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:620px;margin:0 auto;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #d9e2ec;box-shadow:0 16px 42px rgba(15,38,79,0.12);">'
        . '<tr><td style="padding:20px 24px 16px;background:linear-gradient(125deg,#2C4C74 0%,#355a82 48%,#4E8452 100%);">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
        . '<td style="width:56px;vertical-align:middle;"><img src="' . calendar_api_mail_esc($logo) . '" alt="RoomKeeper" width="52" height="52" style="display:block;border-radius:12px;background:rgba(255,255,255,0.14);"></td>'
        . '<td style="vertical-align:middle;padding-left:14px;">'
        . '<p style="margin:0 0 4px;font-size:11px;letter-spacing:0.16em;text-transform:uppercase;color:rgba(255,255,255,0.82);font-weight:700;">RoomKeeper</p>'
        . '<p style="margin:0;font-size:18px;line-height:1.25;font-weight:800;color:#ffffff;">' . $headEsc . '</p>'
        . '</td></tr></table>'
        . '</td></tr>'
        . '<tr><td style="padding:24px 24px 8px;">' . $inner . '</td></tr>'
        . '<tr><td style="padding:16px 24px 20px;background:#f1f5f9;border-top:1px solid #e2e8f0;font-size:12px;line-height:1.5;color:#64748b;">'
        . 'Mensaje automático del <strong>panel privado</strong> (calendario web). '
        . 'Las respuestas suelen usar la casilla configurada en <strong>Reply-To</strong>.'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';

    return array('plain' => $plain, 'html' => $html);
}

function calendar_api_room_label($room)
{
    return $room === 'media' ? 'Sala Media' : 'Sala Básica';
}

function calendar_api_status_label($status)
{
    switch ($status) {
        case 'reservada':
            return 'Reservada';
        case 'mantenimiento':
            return 'Mantención';
        case 'bloqueada':
            return 'Bloqueada';
        case 'liberar':
            return 'Liberar';
        default:
            return 'Disponible';
    }
}

function calendar_api_send_reservation_notice($targetEmail, $targetName, $actorName, $reservation, $messageTitle, $messageIntro)
{
    $date = isset($reservation['date']) ? $reservation['date'] : '';
    $room = calendar_api_room_label(isset($reservation['room']) ? $reservation['room'] : 'basica');
    $status = calendar_api_status_label(isset($reservation['status']) ? $reservation['status'] : 'disponible');
    $responsable = isset($reservation['responsable_label']) && $reservation['responsable_label'] !== '' ? $reservation['responsable_label'] : 'Sin detalle';
    $notes = isset($reservation['notes']) && $reservation['notes'] !== '' ? $reservation['notes'] : 'Sin observaciones.';

    $greet = $targetName !== '' ? $targetName : $targetEmail;
    $rows = array(
        array('k' => 'Fecha', 'v' => $date),
        array('k' => 'Sala', 'v' => $room),
        array('k' => 'Estado', 'v' => $status),
        array('k' => 'Responsable / curso', 'v' => $responsable),
        array('k' => 'Observaciones', 'v' => $notes),
        array('k' => 'Registrado por', 'v' => $actorName),
    );
    $bodies = calendar_api_notification_bodies($messageTitle, $greet, array($messageIntro), $rows, 'Abrir calendario privado');

    return calendar_api_send_mail($targetEmail, $messageTitle, $bodies['plain'], $bodies['html']);
}

function calendar_api_send_change_request_notice($ownerEmail, $ownerName, $request)
{
    $subject = 'Solicitud de cambio en calendario de sala de computación';
    $greet = $ownerName !== '' ? $ownerName : $ownerEmail;
    $intro = array('Un docente solicitó modificar una reserva que hoy está a tu nombre en el calendario de la sala de computación.');
    $rows = array(
        array('k' => 'Fecha', 'v' => (string) ($request['date'] ?? '')),
        array('k' => 'Sala', 'v' => calendar_api_room_label($request['room'] ?? 'basica')),
        array('k' => 'Solicitante', 'v' => (string) ($request['requested_by_name'] ?? $request['requested_by_email'] ?? '')),
        array('k' => 'Estado solicitado', 'v' => calendar_api_status_label($request['requested_status'] ?? 'reservada')),
        array('k' => 'Responsable propuesto', 'v' => (($request['requested_responsable_label'] ?? '') !== '' ? (string) $request['requested_responsable_label'] : 'Sin detalle')),
        array('k' => 'Observaciones propuestas', 'v' => (($request['requested_notes'] ?? '') !== '' ? (string) $request['requested_notes'] : 'Sin observaciones.')),
        array('k' => 'Motivo', 'v' => (($request['reason'] ?? '') !== '' ? (string) $request['reason'] : 'Sin motivo indicado.')),
    );
    $bodies = calendar_api_notification_bodies('Solicitud de cambio · calendario', $greet, $intro, $rows, 'Revisar y responder en el panel');

    return calendar_api_send_mail($ownerEmail, $subject, $bodies['plain'], $bodies['html']);
}

function calendar_api_send_request_result_notice($request, $decision)
{
    $approvedBy = $request['approved_by_name'] ?? $request['approved_by_email'] ?? 'Equipo del colegio';
    $subject = $decision === 'approve'
        ? 'Solicitud aprobada en calendario de sala de computación'
        : 'Solicitud rechazada en calendario de sala de computación';
    $headline = $decision === 'approve' ? 'Solicitud aprobada' : 'Solicitud rechazada';
    $greet = ($request['requested_by_name'] ?? '') !== '' ? (string) $request['requested_by_name'] : (string) ($request['requested_by_email'] ?? '');
    $intro = array(
        $decision === 'approve'
            ? 'Tu solicitud de cambio en el calendario de la sala de computación fue aprobada.'
            : 'Tu solicitud de cambio en el calendario de la sala de computación fue rechazada.',
    );
    $rows = array(
        array('k' => 'Fecha', 'v' => (string) ($request['date'] ?? '')),
        array('k' => 'Sala', 'v' => calendar_api_room_label($request['room'] ?? 'basica')),
        array('k' => 'Estado solicitado', 'v' => calendar_api_status_label($request['requested_status'] ?? 'reservada')),
        array('k' => 'Responsable propuesto', 'v' => (($request['requested_responsable_label'] ?? '') !== '' ? (string) $request['requested_responsable_label'] : 'Sin detalle')),
        array('k' => 'Respondió', 'v' => (string) $approvedBy),
    );
    $bodies = calendar_api_notification_bodies($headline, $greet, $intro, $rows, 'Ver calendario actualizado');

    return calendar_api_send_mail($request['requested_by_email'] ?? '', $subject, $bodies['plain'], $bodies['html']);
}

function calendar_api_send_block_reservation_notice($targetEmail, $targetName, $actorName, $reservation, $messageTitle, $messageIntro)
{
    $date = isset($reservation['date']) ? $reservation['date'] : '';
    $room = calendar_api_room_label(isset($reservation['room']) ? $reservation['room'] : 'basica');
    $slot = isset($reservation['slot_id']) ? (string) $reservation['slot_id'] : '';
    $slotLabel = calendar_api_slot_display_for_mail($slot);
    $status = calendar_api_status_label(isset($reservation['status']) ? $reservation['status'] : 'disponible');
    $asignatura = isset($reservation['asignatura']) && $reservation['asignatura'] !== '' ? $reservation['asignatura'] : 'Sin detalle';
    $curso = isset($reservation['curso']) && $reservation['curso'] !== '' ? $reservation['curso'] : 'Sin curso';
    $docente = isset($reservation['docente']) && $reservation['docente'] !== '' ? $reservation['docente'] : 'Sin detalle';
    $notes = isset($reservation['notes']) && $reservation['notes'] !== '' ? $reservation['notes'] : 'Sin observaciones.';

    $greet = $targetName !== '' ? $targetName : $targetEmail;
    $rows = array(
        array('k' => 'Fecha', 'v' => $date),
        array('k' => 'Sala', 'v' => $room),
        array('k' => 'Bloque horario', 'v' => $slotLabel),
        array('k' => 'Estado', 'v' => $status),
        array('k' => 'Asignatura', 'v' => $asignatura),
        array('k' => 'Curso', 'v' => $curso),
        array('k' => 'Docente responsable', 'v' => $docente),
        array('k' => 'Observaciones', 'v' => $notes),
        array('k' => 'Registrado por', 'v' => $actorName),
    );
    $bodies = calendar_api_notification_bodies('Reserva de bloque · sala de computación', $greet, array($messageIntro), $rows, 'Abrir calendario privado');

    return calendar_api_send_mail($targetEmail, $messageTitle, $bodies['plain'], $bodies['html']);
}

function calendar_api_send_block_change_request_notice($ownerEmail, $ownerName, $request)
{
    $subject = 'Solicitud sobre un bloque de la sala de computación';
    $greet = $ownerName !== '' ? $ownerName : $ownerEmail;
    $slotId = (string) ($request['slot_id'] ?? '');
    $intro = array('Un colega solicitó modificar un bloque de la sala de computación que hoy está asociado a ti.');
    $rows = array(
        array('k' => 'Fecha', 'v' => (string) ($request['date'] ?? '')),
        array('k' => 'Sala', 'v' => calendar_api_room_label($request['room'] ?? 'basica')),
        array('k' => 'Bloque horario', 'v' => calendar_api_slot_display_for_mail($slotId)),
        array('k' => 'Solicitante', 'v' => (string) ($request['requested_by_name'] ?? $request['requested_by_email'] ?? '')),
        array('k' => 'Estado solicitado', 'v' => calendar_api_status_label($request['requested_status'] ?? 'reservada')),
        array('k' => 'Asignatura propuesta', 'v' => (($request['requested_asignatura'] ?? '') !== '' ? (string) $request['requested_asignatura'] : 'Sin detalle')),
        array('k' => 'Curso propuesto', 'v' => (($request['requested_curso'] ?? '') !== '' ? (string) $request['requested_curso'] : 'Sin detalle')),
        array('k' => 'Docente propuesto', 'v' => (($request['requested_docente'] ?? '') !== '' ? (string) $request['requested_docente'] : 'Sin detalle')),
        array('k' => 'Observaciones propuestas', 'v' => (($request['requested_notes'] ?? '') !== '' ? (string) $request['requested_notes'] : 'Sin observaciones.')),
        array('k' => 'Motivo', 'v' => (($request['reason'] ?? '') !== '' ? (string) $request['reason'] : 'Sin motivo indicado.')),
    );
    $bodies = calendar_api_notification_bodies('Nueva solicitud sobre tu bloque', $greet, $intro, $rows, 'Aprobar o rechazar en el panel');

    return calendar_api_send_mail($ownerEmail, $subject, $bodies['plain'], $bodies['html']);
}

function calendar_api_send_block_request_result_notice($request, $decision)
{
    $approvedBy = $request['approved_by_name'] ?? $request['approved_by_email'] ?? 'Equipo del colegio';
    $subject = $decision === 'approve'
        ? 'Solicitud aprobada (bloque de sala de computación)'
        : 'Solicitud rechazada (bloque de sala de computación)';
    $headline = $decision === 'approve' ? 'Solicitud aprobada · bloque' : 'Solicitud rechazada · bloque';
    $greet = ($request['requested_by_name'] ?? '') !== '' ? (string) $request['requested_by_name'] : (string) ($request['requested_by_email'] ?? '');
    $intro = array(
        $decision === 'approve'
            ? 'Tu solicitud sobre un bloque de la sala de computación fue aprobada.'
            : 'Tu solicitud sobre un bloque de la sala de computación fue rechazada.',
    );
    $slotId = (string) ($request['slot_id'] ?? '');
    $rows = array(
        array('k' => 'Fecha', 'v' => (string) ($request['date'] ?? '')),
        array('k' => 'Sala', 'v' => calendar_api_room_label($request['room'] ?? 'basica')),
        array('k' => 'Bloque horario', 'v' => calendar_api_slot_display_for_mail($slotId)),
        array('k' => 'Estado solicitado', 'v' => calendar_api_status_label($request['requested_status'] ?? 'reservada')),
        array('k' => 'Respondió', 'v' => (string) $approvedBy),
    );
    $bodies = calendar_api_notification_bodies($headline, $greet, $intro, $rows, 'Abrir calendario');

    return calendar_api_send_mail($request['requested_by_email'] ?? '', $subject, $bodies['plain'], $bodies['html']);
}

function calendar_api_wants_send_email($input)
{
    return !array_key_exists('send_email', $input) || !empty($input['send_email']);
}

function calendar_api_current_user()
{
    $user = admin_current_user();
    if (!$user) {
        calendar_api_response(array('ok' => false, 'message' => 'Sesión inválida.'), 401);
    }
    if (!empty($user['is_active']) || !array_key_exists('is_active', $user)) {
        return $user;
    }
    calendar_api_response(array('ok' => false, 'message' => 'Esta cuenta está desactivada.'), 403);
}

function calendar_api_user_payload($user)
{
    return array(
        'email' => $user['email'],
        'name' => admin_user_display_name($user),
        'role' => admin_user_role($user),
        'can_override' => calendar_user_can_override($user),
        'can_manage_holidays' => calendar_user_can_manage_holidays($user),
    );
}

function calendar_api_pending_requests($store, $user, $year, $room, $semester)
{
    $email = admin_normalize_email($user['email']);
    $canOverride = calendar_user_can_override($user);
    $items = array();

    foreach ($store['change_requests'] as $request) {
        if (!is_array($request)) {
            continue;
        }
        if (($request['approval_status'] ?? '') !== 'pendiente') {
            continue;
        }
        if (($request['room'] ?? '') !== $room) {
            continue;
        }
        if (!calendar_date_in_semester($request['date'] ?? '', $year, $semester)) {
            continue;
        }
        if (!$canOverride && $email !== ($request['owner_email'] ?? '') && $email !== ($request['requested_by_email'] ?? '')) {
            continue;
        }
        $items[] = $request;
    }

    usort($items, function ($left, $right) {
        return strcmp($left['date'] . ($left['created_at'] ?? ''), $right['date'] . ($right['created_at'] ?? ''));
    });

    return $items;
}

function calendar_api_slot_config()
{
    $configPath = __DIR__ . '/config_time_slots.json';
    if (is_file($configPath)) {
        $raw = file_get_contents($configPath);
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return array(
        'bloques_horarios' => calendar_block_catalog(),
        'cursos' => array(),
        'docente_default' => 'Pablo Elías Avendaño Miranda',
        'jornada_ti' => array(
            'dias_habiles' => array('hora_salida' => '17:30', 'dias' => array(1, 2, 3, 4)),
            'viernes' => array('hora_salida' => '16:35', 'dias' => array(5)),
            'mensaje' => 'Fuera de Horario Soporte',
        ),
    );
}

function calendar_api_notice_path()
{
    return __DIR__ . '/../data/calendar_notices.json';
}

function calendar_api_calendar_notices()
{
    $path = calendar_api_notice_path();
    if (!is_file($path)) {
        return array();
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return array();
    }

    $notices = array();
    foreach ($decoded as $notice) {
        if (!is_array($notice)) {
            continue;
        }
        $times = array();
        foreach (($notice['weekly_times'] ?? array()) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $weekday = (int) ($row['weekday'] ?? 0);
            $time = trim((string) ($row['time'] ?? ''));
            if ($weekday < 1 || $weekday > 5 || $time === '') {
                continue;
            }
            $times[] = array(
                'weekday' => $weekday,
                'weekday_label' => trim((string) ($row['weekday_label'] ?? '')),
                'time' => $time,
                'slot_hint' => trim((string) ($row['slot_hint'] ?? '')),
            );
        }
        if (!$times) {
            continue;
        }
        $notices[] = array(
            'id' => trim((string) ($notice['id'] ?? '')),
            'title' => trim((string) ($notice['title'] ?? 'Aviso')),
            'subtitle' => trim((string) ($notice['subtitle'] ?? '')),
            'audience' => trim((string) ($notice['audience'] ?? '')),
            'room_note' => trim((string) ($notice['room_note'] ?? '')),
            'weekly_times' => $times,
        );
    }

    return $notices;
}

function calendar_api_in_month($date, $year, $month)
{
    if (!calendar_is_valid_date_key($date)) {
        return false;
    }
    $prefix = sprintf('%04d-%02d-', (int) $year, (int) $month);
    return strpos($date, $prefix) === 0;
}

function calendar_api_roster_for_course($store, $course)
{
    $course = trim((string) $course);
    if ($course === '') {
        return array();
    }
    if (!isset($store['course_rosters']) || !is_array($store['course_rosters'])) {
        return array();
    }
    $roster = $store['course_rosters'][$course] ?? array();
    if (!is_array($roster)) {
        return array();
    }
    return array_values(array_filter(array_map('trim', $roster), function ($name) {
        return $name !== '';
    }));
}

$user = calendar_api_current_user();
$method = strtoupper($_SERVER['REQUEST_METHOD']);
$action = isset($_GET['action']) ? (string) $_GET['action'] : '';
$input = $method === 'POST' ? calendar_api_input() : $_GET;

if ($method === 'GET' && $action === 'load_blocks') {
    $year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
    $month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('n');
    $room = calendar_normalize_room(isset($_GET['room']) ? $_GET['room'] : 'basica');
    if ($month < 1 || $month > 12) {
        calendar_api_response(array('ok' => false, 'message' => 'Mes inválido.'), 422);
    }

    $store = calendar_store_read_all();
    $reservas = array();
    $dayBadges = array();
    foreach ($store['block_reservations'] as $reservation) {
        if (!is_array($reservation)) {
            continue;
        }
        if (($reservation['room'] ?? '') !== $room) {
            continue;
        }
        $date = (string) ($reservation['date'] ?? '');
        if (!calendar_api_in_month($date, $year, $month)) {
            continue;
        }
        $slotId = calendar_normalize_slot_id((string) ($reservation['slot_id'] ?? ''));
        if ($slotId === '') {
            continue;
        }
        if (!isset($reservas[$date]) || !is_array($reservas[$date])) {
            $reservas[$date] = array();
        }
        $reservas[$date][$slotId] = $reservation;
        $slotMeta = calendar_block_meta($slotId);
        $status = calendar_normalize_status((string) ($reservation['status'] ?? 'disponible'));
        if ($slotMeta && empty($slotMeta['es_bloqueado']) && $status !== 'disponible') {
            $dayBadges[$date] = ($dayBadges[$date] ?? 0) + 1;
        }
    }

    $customHolidaysInMonth = array();
    $customHolidaysForYear = array();
    $yearKey = (string) $year;
    if (isset($store['custom_holidays'][$yearKey]) && is_array($store['custom_holidays'][$yearKey])) {
        foreach ($store['custom_holidays'][$yearKey] as $holidayDate => $holiday) {
            $label = '';
            if (is_array($holiday)) {
                $label = trim((string) ($holiday['label'] ?? ''));
            } elseif (is_string($holiday)) {
                $label = trim($holiday);
            }
            if ($label === '') {
                continue;
            }
            $dateKey = (string) $holidayDate;
            $customHolidaysForYear[$dateKey] = $label;
            if (calendar_api_in_month($dateKey, $year, $month)) {
                $customHolidaysInMonth[$dateKey] = $label;
            }
        }
    }

    $slotConfig = calendar_api_slot_config();
    calendar_api_response(array(
        'ok' => true,
        'csrf_token' => admin_csrf_token(),
        'user' => calendar_api_user_payload($user),
        'year' => $year,
        'month' => $month,
        'room' => $room,
        'slots' => $slotConfig['bloques_horarios'] ?? calendar_block_catalog(),
        'cursos' => $slotConfig['cursos'] ?? array(),
        'docente_default' => $slotConfig['docente_default'] ?? 'Pablo Elías Avendaño Miranda',
        'jornada_ti' => $slotConfig['jornada_ti'] ?? array(),
        'status_colors' => $slotConfig['status_colors'] ?? array(),
        'reservas' => $reservas,
        'day_badges' => $dayBadges,
        'pending_requests' => calendar_get_block_pending_requests($store, $user, $year, $room, $month),
        'custom_holidays_in_month' => $customHolidaysInMonth,
        'custom_holidays_for_year' => calendar_user_can_manage_holidays($user) ? $customHolidaysForYear : array(),
        'calendar_notices' => calendar_api_calendar_notices(),
    ));
}

if ($method === 'GET' && $action === 'seat_map') {
    $room = calendar_normalize_room(isset($_GET['room']) ? $_GET['room'] : 'basica');
    $date = isset($_GET['date']) ? (string) $_GET['date'] : '';
    $slotId = calendar_normalize_slot_id(isset($_GET['slot_id']) ? $_GET['slot_id'] : '');
    if (!calendar_is_valid_date_key($date) || $slotId === '') {
        calendar_api_response(array('ok' => false, 'message' => 'Parámetros inválidos.'), 422);
    }

    $store = calendar_store_read_all();
    $reservation = calendar_get_block($store, $room, $date, $slotId);
    if (!$reservation) {
        calendar_api_response(array('ok' => false, 'message' => 'No hay reserva activa en ese bloque.'), 404);
    }

    $course = trim((string) ($reservation['curso'] ?? ''));
    $roster = calendar_api_roster_for_course($store, $course);
    $seats = array();
    for ($seat = 1; $seat <= 40; $seat++) {
        $seats[] = array(
            'puesto' => $seat,
            'alumno' => $roster[$seat - 1] ?? '',
        );
    }

    calendar_api_response(array(
        'ok' => true,
        'reservation' => $reservation,
        'seats' => $seats,
    ));
}

if ($method === 'GET' && $action === 'load') {
    $year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
    $room = calendar_normalize_room(isset($_GET['room']) ? $_GET['room'] : 'basica');
    $semester = calendar_normalize_semester(isset($_GET['semester']) ? $_GET['semester'] : 's1');
    $store = calendar_store_read_all();
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

    $customHolidays = isset($store['custom_holidays'][(string) $year]) && is_array($store['custom_holidays'][(string) $year])
        ? $store['custom_holidays'][(string) $year]
        : array();

    calendar_api_response(array(
        'ok' => true,
        'user' => calendar_api_user_payload($user),
        'csrf_token' => admin_csrf_token(),
        'year' => $year,
        'room' => $room,
        'semester' => $semester,
        'reservations' => $reservations,
        'custom_holidays' => $customHolidays,
        'pending_requests' => calendar_api_pending_requests($store, $user, $year, $room, $semester),
    ));
}

if ($method === 'GET' && $action === 'export') {
    $year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
    $room = calendar_normalize_room(isset($_GET['room']) ? $_GET['room'] : 'basica');
    $semester = calendar_normalize_semester(isset($_GET['semester']) ? $_GET['semester'] : 's1');
    $store = calendar_store_read_all();
    calendar_api_response(array(
        'ok' => true,
        'payload' => calendar_store_export_period($store, $year, $room, $semester),
    ));
}

if ($method !== 'POST') {
    calendar_api_response(array('ok' => false, 'message' => 'Acción no permitida.'), 405);
}

if (!admin_validate_csrf(isset($input['csrf_token']) ? $input['csrf_token'] : null)) {
    calendar_api_response(array('ok' => false, 'message' => 'La sesión expiró. Recarga la página.'), 419);
}

if ($action === 'save_block') {
    $room = calendar_normalize_room(isset($input['room']) ? $input['room'] : 'basica');
    $date = isset($input['date']) ? (string) $input['date'] : '';
    $slotId = calendar_normalize_slot_id(isset($input['slot_id']) ? $input['slot_id'] : '');
    $status = calendar_normalize_status(isset($input['status']) ? $input['status'] : 'reservada');
    $asignatura = calendar_api_trimmed($input['asignatura'] ?? '', 120);
    $curso = calendar_api_trimmed($input['curso'] ?? '', 80);
    $docente = calendar_api_trimmed($input['docente'] ?? '', 120);
    $notes = calendar_api_trimmed($input['notes'] ?? '', 1200);
    $version = isset($input['version']) ? (int) $input['version'] : 0;

    if (!calendar_is_valid_date_key($date) || $slotId === '') {
        calendar_api_response(array('ok' => false, 'message' => 'Fecha o bloque inválido.'), 422);
    }

    $slotMeta = calendar_block_meta($slotId);
    if (!$slotMeta || !empty($slotMeta['es_bloqueado'])) {
        calendar_api_response(array('ok' => false, 'message' => 'Ese bloque no admite reservas.'), 422);
    }

    if ($status !== 'reservada' && $status !== 'mantenimiento' && $status !== 'disponible') {
        $status = 'reservada';
    }

    $isClear = $status === 'disponible' && $asignatura === '' && $curso === '' && $docente === '' && $notes === '';

    list(, , $result) = calendar_store_mutate(function (&$store) use ($user, $room, $date, $slotId, $status, $asignatura, $curso, $docente, $notes, $version, $isClear) {
        $email = admin_normalize_email($user['email']);
        $name = admin_user_display_name($user);
        $blockKey = calendar_block_key($room, $date, $slotId);
        $existing = calendar_get_block($store, $room, $date, $slotId);

        if ($existing) {
            $ownerEmail = admin_normalize_email((string) ($existing['owner_email'] ?? ''));
            if (!calendar_user_can_override($user) && $ownerEmail !== $email) {
                return array(
                    'ok' => false,
                    'code' => 'owner_locked',
                    'message' => 'Bloque reservado por otro usuario. Debes solicitar aprobación.',
                    'reservation' => $existing,
                );
            }
            if ($version > 0 && (int) ($existing['version'] ?? 0) !== $version) {
                return array(
                    'ok' => false,
                    'code' => 'version_conflict',
                    'message' => 'Este bloque cambió mientras lo editabas.',
                    'reservation' => $existing,
                );
            }
            if ($isClear) {
                calendar_remove_block($store, $room, $date, $slotId);
                calendar_append_audit($store, 'delete_block', $email, $blockKey, $existing, null);
                return array('ok' => true, 'message' => 'Bloque liberado.', 'deleted_block' => $existing);
            }
            $updated = $existing;
            $updated['status'] = $status;
            $updated['asignatura'] = $asignatura;
            $updated['curso'] = $curso;
            $updated['docente'] = $docente;
            $updated['notes'] = $notes;
            $updated['updated_at'] = date('c');
            $updated['updated_by'] = $email;
            $updated['updated_by_name'] = $name;
            $updated['version'] = (int) ($existing['version'] ?? 0) + 1;
            calendar_set_block($store, $room, $date, $slotId, $updated);
            calendar_append_audit($store, 'update_block', $email, $blockKey, $existing, $updated);
            return array('ok' => true, 'message' => 'Bloque actualizado.', 'reservation' => $updated);
        }

        if ($isClear) {
            return array('ok' => true, 'message' => 'Sin cambios.');
        }

        $store['meta']['last_block_id'] = (int) ($store['meta']['last_block_id'] ?? 0) + 1;
        $created = array(
            'id' => $store['meta']['last_block_id'],
            'slot_id' => $slotId,
            'date' => $date,
            'room' => $room,
            'status' => $status,
            'owner_email' => $email,
            'owner_name' => $name,
            'asignatura' => $asignatura,
            'curso' => $curso,
            'docente' => $docente,
            'notes' => $notes,
            'version' => 1,
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'updated_by' => $email,
            'updated_by_name' => $name,
        );
        calendar_set_block($store, $room, $date, $slotId, $created);
        calendar_append_audit($store, 'create_block', $email, $blockKey, null, $created);
        return array('ok' => true, 'message' => 'Bloque reservado.', 'reservation' => $created);
    });

    $wantsMail = calendar_api_wants_send_email($input);
    $result['send_email_requested'] = $wantsMail;
    if (!empty($result['ok']) && $wantsMail) {
        $actorName = admin_user_display_name($user);
        $mailSent = false;
        if (!empty($result['deleted_block'])) {
            $ex = $result['deleted_block'];
            $to = admin_normalize_email((string) ($ex['owner_email'] ?? ''));
            if ($to !== '') {
                $greet = (isset($ex['owner_name']) && $ex['owner_name'] !== '') ? (string) $ex['owner_name'] : $to;
                $slotId = (string) ($ex['slot_id'] ?? '');
                $bodies = calendar_api_notification_bodies(
                    'Bloque liberado · sala de computación',
                    $greet,
                    array('Se liberó un bloque de la sala de computación que estaba registrado a tu nombre.'),
                    array(
                        array('k' => 'Fecha', 'v' => (string) ($ex['date'] ?? '')),
                        array('k' => 'Sala', 'v' => calendar_api_room_label($ex['room'] ?? 'basica')),
                        array('k' => 'Bloque horario', 'v' => calendar_api_slot_display_for_mail($slotId)),
                        array('k' => 'Liberado por', 'v' => $actorName),
                    ),
                    'Abrir calendario'
                );
                $mailSent = calendar_api_send_mail($to, 'Bloque liberado en sala de computación', $bodies['plain'], $bodies['html']);
                if ($mailSent) {
                    $result['mail_notice'] = 'Correo tipo "bloque liberado" enviado al docente que tenía la reserva.';
                }
            }
        } elseif (!empty($result['reservation'])) {
            $r = $result['reservation'];
            $to = admin_normalize_email($user['email']);
            if ($to !== '') {
                $intro = (strpos((string) ($result['message'] ?? ''), 'actualizado') !== false)
                    ? 'Se actualizó tu reserva de bloque en la sala de computación.'
                    : 'Se registró tu reserva de bloque en la sala de computación.';
                $mailSent = calendar_api_send_block_reservation_notice(
                    $to,
                    $actorName,
                    $actorName,
                    $r,
                    'Reserva de bloque — sala de computación',
                    $intro
                );
                if ($mailSent) {
                    $result['mail_notice'] = (strpos((string) ($result['message'] ?? ''), 'actualizado') !== false)
                        ? 'Correo de confirmación "reserva actualizada" enviado a tu casilla.'
                        : 'Correo de confirmación "bloque reservado" enviado a tu casilla.';
                }
            }
        }
        $result['mail_sent'] = $mailSent;
    } else {
        $result['mail_sent'] = false;
    }

    calendar_api_response($result, !empty($result['ok']) ? 200 : 409);
}

if ($action === 'request_block_change') {
    $room = calendar_normalize_room(isset($input['room']) ? $input['room'] : 'basica');
    $date = isset($input['date']) ? (string) $input['date'] : '';
    $slotId = calendar_normalize_slot_id(isset($input['slot_id']) ? $input['slot_id'] : '');
    $requestedStatus = calendar_normalize_status(isset($input['status']) ? $input['status'] : 'reservada');
    if (!in_array($requestedStatus, array('reservada', 'mantenimiento', 'disponible'), true)) {
        $requestedStatus = 'reservada';
    }
    $asignatura = calendar_api_trimmed($input['asignatura'] ?? '', 120);
    $curso = calendar_api_trimmed($input['curso'] ?? '', 80);
    $docente = calendar_api_trimmed($input['docente'] ?? '', 120);
    $notes = calendar_api_trimmed($input['notes'] ?? '', 1200);
    $reason = calendar_api_trimmed($input['reason'] ?? '', 800);

    if (!calendar_is_valid_date_key($date) || $slotId === '' || $reason === '') {
        calendar_api_response(array('ok' => false, 'message' => 'Completa fecha, bloque y motivo.'), 422);
    }

    list(, , $result) = calendar_store_mutate(function (&$store) use ($user, $room, $date, $slotId, $requestedStatus, $asignatura, $curso, $docente, $notes, $reason) {
        $existing = calendar_get_block($store, $room, $date, $slotId);
        if (!$existing) {
            return array('ok' => false, 'message' => 'Ya no existe una reserva en ese bloque.');
        }
        $email = admin_normalize_email($user['email']);
        $ownerEmail = admin_normalize_email((string) ($existing['owner_email'] ?? ''));
        if ($ownerEmail === $email) {
            return array('ok' => false, 'message' => 'No necesitas solicitar aprobación para tu propia reserva.');
        }

        foreach ($store['block_change_requests'] as $request) {
            if (!is_array($request)) {
                continue;
            }
            if (($request['room'] ?? '') === $room
                && ($request['date'] ?? '') === $date
                && ($request['slot_id'] ?? '') === $slotId
                && ($request['requested_by_email'] ?? '') === $email
                && ($request['approval_status'] ?? '') === 'pendiente') {
                return array('ok' => false, 'message' => 'Ya tienes una solicitud pendiente para este bloque.');
            }
        }

        $store['meta']['last_block_change_request_id'] = (int) ($store['meta']['last_block_change_request_id'] ?? 0) + 1;
        $request = array(
            'id' => $store['meta']['last_block_change_request_id'],
            'room' => $room,
            'date' => $date,
            'slot_id' => $slotId,
            'reservation_id' => $existing['id'] ?? null,
            'owner_email' => $ownerEmail,
            'owner_name' => $existing['owner_name'] ?? $ownerEmail,
            'requested_by_email' => $email,
            'requested_by_name' => admin_user_display_name($user),
            'requested_status' => $requestedStatus,
            'requested_asignatura' => $asignatura,
            'requested_curso' => $curso,
            'requested_docente' => $docente,
            'requested_notes' => $notes,
            'reason' => $reason,
            'approval_status' => 'pendiente',
            'created_at' => date('c'),
        );
        $store['block_change_requests'][] = $request;
        calendar_append_audit($store, 'request_block_change', $email, calendar_block_key($room, $date, $slotId), $existing, $request);
        return array('ok' => true, 'message' => 'Solicitud de aprobación enviada.', 'request' => $request);
    });

    $wantsMail = calendar_api_wants_send_email($input);
    $result['send_email_requested'] = $wantsMail;
    if (!empty($result['ok']) && $wantsMail && !empty($result['request'])) {
        $req = $result['request'];
        $ownerEmail = admin_normalize_email((string) ($req['owner_email'] ?? ''));
        if ($ownerEmail !== '') {
            $sent = calendar_api_send_block_change_request_notice(
                $ownerEmail,
                (string) ($req['owner_name'] ?? $ownerEmail),
                $req
            );
            $result['mail_sent'] = $sent;
            if ($sent) {
                $result['mail_notice'] = 'Correo tipo "solicitud de cambio de bloque" enviado al propietario de la reserva.';
            }
        } else {
            $result['mail_sent'] = false;
        }
    } else {
        $result['mail_sent'] = false;
    }

    calendar_api_response($result, !empty($result['ok']) ? 200 : 409);
}

if ($action === 'respond_block_request') {
    $requestId = isset($input['request_id']) ? (int) $input['request_id'] : 0;
    $decision = strtolower(trim((string) ($input['decision'] ?? '')));
    if (!in_array($decision, array('approve', 'reject'), true)) {
        calendar_api_response(array('ok' => false, 'message' => 'Decisión inválida.'), 422);
    }

    list(, , $result) = calendar_store_mutate(function (&$store) use ($user, $requestId, $decision) {
        $email = admin_normalize_email($user['email']);
        $name = admin_user_display_name($user);
        $canOverride = calendar_user_can_override($user);
        foreach ($store['block_change_requests'] as $index => $request) {
            if (!is_array($request) || (int) ($request['id'] ?? 0) !== $requestId) {
                continue;
            }
            if (($request['approval_status'] ?? '') !== 'pendiente') {
                return array('ok' => false, 'message' => 'La solicitud ya fue resuelta.');
            }
            $ownerEmail = admin_normalize_email((string) ($request['owner_email'] ?? ''));
            if (!$canOverride && $ownerEmail !== $email) {
                return array('ok' => false, 'message' => 'No tienes permiso para responder esta solicitud.');
            }

            $request['approval_status'] = $decision === 'approve' ? 'aprobada' : 'rechazada';
            $request['approved_by_email'] = $email;
            $request['approved_by_name'] = $name;
            $request['approved_at'] = date('c');
            $store['block_change_requests'][$index] = $request;

            $slotId = calendar_normalize_slot_id((string) ($request['slot_id'] ?? ''));
            $reservation = $slotId !== '' ? calendar_get_block($store, $request['room'], $request['date'], $slotId) : null;
            if ($decision === 'approve' && !$reservation) {
                return array('ok' => false, 'message' => 'La reserva original ya no existe. No se pudo aprobar la solicitud.');
            }
            if ($decision === 'approve' && $reservation) {
                $oldPayload = $reservation;
                $reservation['status'] = calendar_normalize_status($request['requested_status'] ?? 'reservada');
                $reservation['asignatura'] = (string) ($request['requested_asignatura'] ?? $reservation['asignatura']);
                $reservation['curso'] = (string) ($request['requested_curso'] ?? $reservation['curso']);
                $reservation['docente'] = (string) ($request['requested_docente'] ?? $reservation['docente']);
                $reservation['notes'] = (string) ($request['requested_notes'] ?? $reservation['notes']);
                $reservation['owner_email'] = (string) ($request['requested_by_email'] ?? $reservation['owner_email']);
                $reservation['owner_name'] = (string) ($request['requested_by_name'] ?? $reservation['owner_name']);
                $reservation['updated_at'] = date('c');
                $reservation['updated_by'] = $email;
                $reservation['updated_by_name'] = $name;
                $reservation['version'] = (int) ($reservation['version'] ?? 0) + 1;
                calendar_set_block($store, $request['room'], $request['date'], $slotId, $reservation);
                calendar_append_audit($store, 'approve_block_change', $email, calendar_block_key($request['room'], $request['date'], $slotId), $oldPayload, $reservation);
            } else {
                calendar_append_audit($store, 'reject_block_change', $email, calendar_block_key($request['room'], $request['date'], (string) ($request['slot_id'] ?? '')), null, $request);
            }
            return array(
                'ok' => true,
                'message' => $decision === 'approve' ? 'Solicitud aprobada.' : 'Solicitud rechazada.',
                'request' => $request,
                'decision' => $decision,
            );
        }
        return array('ok' => false, 'message' => 'No se encontró la solicitud.');
    });

    $wantsMail = calendar_api_wants_send_email($input);
    $result['send_email_requested'] = $wantsMail;
    if (!empty($result['ok']) && $wantsMail && !empty($result['request'])) {
        $sent = calendar_api_send_block_request_result_notice($result['request'], (string) ($result['decision'] ?? ''));
        $result['mail_sent'] = $sent;
        if ($sent) {
            $dec = (string) ($result['decision'] ?? '');
            $result['mail_notice'] = $dec === 'approve'
                ? 'Correo tipo "solicitud aprobada" enviado al docente que solicitó el cambio.'
                : 'Correo tipo "solicitud rechazada" enviado al docente que solicitó el cambio.';
        }
    } else {
        $result['mail_sent'] = false;
    }

    calendar_api_response($result, !empty($result['ok']) ? 200 : 404);
}

if ($action === 'report_incidence') {
    $room = calendar_normalize_room(isset($input['room']) ? $input['room'] : 'basica');
    $date = isset($input['date']) ? (string) $input['date'] : '';
    $slotId = calendar_normalize_slot_id(isset($input['slot_id']) ? $input['slot_id'] : '');
    $detalle = calendar_api_trimmed($input['detalle'] ?? '', 1200);
    if (!calendar_is_valid_date_key($date) || $slotId === '' || $detalle === '') {
        calendar_api_response(array('ok' => false, 'message' => 'Completa bloque y detalle de la incidencia.'), 422);
    }

    list(, , $result) = calendar_store_mutate(function (&$store) use ($user, $room, $date, $slotId, $detalle) {
        $store['meta']['last_incidence_id'] = (int) ($store['meta']['last_incidence_id'] ?? 0) + 1;
        $record = array(
            'id' => $store['meta']['last_incidence_id'],
            'room' => $room,
            'date' => $date,
            'slot_id' => $slotId,
            'detalle' => $detalle,
            'reported_by_email' => admin_normalize_email($user['email']),
            'reported_by_name' => admin_user_display_name($user),
            'created_at' => date('c'),
        );
        $store['incidences'][] = $record;
        calendar_append_audit($store, 'report_incidence', $record['reported_by_email'], calendar_block_key($room, $date, $slotId), null, $record);
        return array('ok' => true, 'message' => 'Incidencia registrada correctamente.');
    });
    calendar_api_response($result, !empty($result['ok']) ? 200 : 422);
}

if ($action === 'save_reservation') {
    $room = calendar_normalize_room(isset($input['room']) ? $input['room'] : 'basica');
    $date = isset($input['date']) ? (string) $input['date'] : '';
    $status = calendar_normalize_status(isset($input['status']) ? $input['status'] : 'disponible');
    $responsable = trim((string) ($input['responsable_label'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));
    $version = isset($input['version']) ? (int) $input['version'] : 0;
    $sendEmail = !empty($input['send_email']);

    if (!calendar_is_valid_date_key($date)) {
        calendar_api_response(array('ok' => false, 'message' => 'La fecha es inválida.'), 422);
    }

    list(, , $result) = calendar_store_mutate(function (&$store) use ($user, $room, $date, $status, $responsable, $notes, $version, $sendEmail) {
        $email = admin_normalize_email($user['email']);
        $name = admin_user_display_name($user);
        $existing = calendar_get_reservation($store, $room, $date);
        $reservationKey = calendar_reservation_key($room, $date);
        $emptyIntent = $status === 'disponible' && $responsable === '' && $notes === '';

        if (!$existing && $emptyIntent) {
            return array('ok' => true, 'reservation' => null, 'message' => 'Sin cambios.');
        }

        if ($existing) {
            $ownerEmail = admin_normalize_email($existing['owner_email'] ?? '');
            $canOverride = calendar_user_can_override($user);

            if (!$canOverride && $ownerEmail !== $email) {
                return array(
                    'ok' => false,
                    'code' => 'owner_locked',
                    'message' => 'Este día ya fue reservado por otro docente. Debes solicitar un cambio.',
                    'reservation' => $existing,
                );
            }

            if ($version > 0 && (int) ($existing['version'] ?? 0) !== $version) {
                return array(
                    'ok' => false,
                    'code' => 'version_conflict',
                    'message' => 'El registro cambió mientras lo estabas editando. Recarga para ver la última versión.',
                    'reservation' => $existing,
                );
            }

            if ($emptyIntent) {
                calendar_remove_reservation($store, $room, $date);
                calendar_append_audit($store, 'delete', $email, $reservationKey, $existing, null);
                $mailSent = false;
                if ($sendEmail) {
                    $mailSent = calendar_api_send_reservation_notice(
                        $email,
                        $name,
                        $name,
                        $existing,
                        'Reserva liberada en calendario de sala de computación',
                        'Tu reserva fue liberada del calendario privado.'
                    );
                }
                return array('ok' => true, 'reservation' => null, 'message' => 'Reserva liberada.', 'mail_sent' => $mailSent);
            }

            $updated = $existing;
            $updated['status'] = $status;
            $updated['responsable_label'] = $responsable;
            $updated['notes'] = $notes;
            $updated['updated_at'] = date('c');
            $updated['updated_by'] = $email;
            $updated['updated_by_name'] = $name;
            $updated['version'] = (int) ($existing['version'] ?? 0) + 1;

            if (calendar_user_can_override($user) && $ownerEmail !== $email) {
                $updated['last_forced_override_by'] = $email;
                $updated['last_forced_override_at'] = date('c');
                calendar_append_audit($store, 'force_override', $email, $reservationKey, $existing, $updated);
            } else {
                calendar_append_audit($store, 'update', $email, $reservationKey, $existing, $updated);
            }

            calendar_set_reservation($store, $room, $date, $updated);
            $mailSent = false;
            if ($sendEmail) {
                $mailSent = calendar_api_send_reservation_notice(
                    $email,
                    $name,
                    $name,
                    $updated,
                    'Reserva actualizada en calendario de sala de computación',
                    'Tu reserva fue actualizada en el calendario privado.'
                );
                if (calendar_user_can_override($user) && $ownerEmail !== $email) {
                    calendar_api_send_reservation_notice(
                        $ownerEmail,
                        $existing['owner_name'] ?? $ownerEmail,
                        $name,
                        $updated,
                        'Tu reserva fue ajustada por un responsable del panel',
                        'Una reserva que estaba a tu nombre fue ajustada desde el panel administrativo.'
                    );
                }
            }
            return array('ok' => true, 'reservation' => $updated, 'message' => 'Reserva actualizada.', 'mail_sent' => $mailSent);
        }

        $store['meta']['last_reservation_id'] = (int) ($store['meta']['last_reservation_id'] ?? 0) + 1;
        $created = array(
            'id' => $store['meta']['last_reservation_id'],
            'date' => $date,
            'room' => $room,
            'status' => $status,
            'owner_email' => $email,
            'owner_name' => $name,
            'responsable_label' => $responsable,
            'notes' => $notes,
            'version' => 1,
            'is_locked' => true,
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'created_by' => $email,
            'updated_by' => $email,
            'updated_by_name' => $name,
        );
        calendar_set_reservation($store, $room, $date, $created);
        calendar_append_audit($store, 'create', $email, $reservationKey, null, $created);
        $mailSent = false;
        if ($sendEmail) {
            $mailSent = calendar_api_send_reservation_notice(
                $email,
                $name,
                $name,
                $created,
                'Reserva creada en calendario de sala de computación',
                'Se registró una nueva reserva en el calendario privado.'
            );
        }
        return array('ok' => true, 'reservation' => $created, 'message' => 'Reserva guardada.', 'mail_sent' => $mailSent);
    });

    calendar_api_response($result, !empty($result['ok']) ? 200 : 409);
}

if ($action === 'request_change') {
    $room = calendar_normalize_room(isset($input['room']) ? $input['room'] : 'basica');
    $date = isset($input['date']) ? (string) $input['date'] : '';
    $status = calendar_normalize_status(isset($input['status']) ? $input['status'] : 'reservada');
    $responsable = trim((string) ($input['responsable_label'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));
    $reason = trim((string) ($input['reason'] ?? ''));
    $sendEmail = !empty($input['send_email']);

    if (!calendar_is_valid_date_key($date)) {
        calendar_api_response(array('ok' => false, 'message' => 'La fecha es inválida.'), 422);
    }

    list(, , $result) = calendar_store_mutate(function (&$store) use ($user, $room, $date, $status, $responsable, $notes, $reason, $sendEmail) {
        $email = admin_normalize_email($user['email']);
        $existing = calendar_get_reservation($store, $room, $date);
        if (!$existing) {
            return array('ok' => false, 'message' => 'Ya no existe una reserva para este día.');
        }

        $ownerEmail = admin_normalize_email($existing['owner_email'] ?? '');
        if ($ownerEmail === $email) {
            return array('ok' => false, 'message' => 'No necesitas solicitar cambio sobre tu propia reserva.');
        }

        foreach ($store['change_requests'] as $request) {
            if (!is_array($request)) {
                continue;
            }
            if (($request['room'] ?? '') === $room
                && ($request['date'] ?? '') === $date
                && ($request['requested_by_email'] ?? '') === $email
                && ($request['approval_status'] ?? '') === 'pendiente') {
                return array('ok' => false, 'message' => 'Ya tienes una solicitud pendiente para este día.');
            }
        }

        $store['meta']['last_change_request_id'] = (int) ($store['meta']['last_change_request_id'] ?? 0) + 1;
        $request = array(
            'id' => $store['meta']['last_change_request_id'],
            'room' => $room,
            'date' => $date,
            'reservation_id' => $existing['id'] ?? null,
            'owner_email' => $ownerEmail,
            'owner_name' => $existing['owner_name'] ?? $ownerEmail,
            'requested_by_email' => $email,
            'requested_by_name' => admin_user_display_name($user),
            'requested_status' => $status,
            'requested_responsable_label' => $responsable,
            'requested_notes' => $notes,
            'reason' => $reason,
            'approval_status' => 'pendiente',
            'created_at' => date('c'),
        );
        $store['change_requests'][] = $request;
        calendar_append_audit($store, 'request_change', $email, calendar_reservation_key($room, $date), $existing, $request);
        $mailSent = false;
        if ($sendEmail) {
            $mailSent = calendar_api_send_change_request_notice($ownerEmail, $existing['owner_name'] ?? $ownerEmail, $request);
        }
        return array('ok' => true, 'request' => $request, 'message' => 'Solicitud enviada al propietario de la reserva.', 'mail_sent' => $mailSent);
    });

    calendar_api_response($result, !empty($result['ok']) ? 200 : 409);
}

if ($action === 'respond_request') {
    $requestId = isset($input['request_id']) ? (int) $input['request_id'] : 0;
    $decision = strtolower(trim((string) ($input['decision'] ?? '')));
    $sendEmail = !empty($input['send_email']);
    if (!in_array($decision, array('approve', 'reject'), true)) {
        calendar_api_response(array('ok' => false, 'message' => 'Decisión inválida.'), 422);
    }

    list(, , $result) = calendar_store_mutate(function (&$store) use ($user, $requestId, $decision, $sendEmail) {
        $email = admin_normalize_email($user['email']);
        $canOverride = calendar_user_can_override($user);

        foreach ($store['change_requests'] as $index => $request) {
            if (!is_array($request) || (int) ($request['id'] ?? 0) !== $requestId) {
                continue;
            }

            if (($request['approval_status'] ?? '') !== 'pendiente') {
                return array('ok' => false, 'message' => 'La solicitud ya fue resuelta.');
            }

            $ownerEmail = admin_normalize_email($request['owner_email'] ?? '');
            if (!$canOverride && $ownerEmail !== $email) {
                return array('ok' => false, 'message' => 'No tienes permiso para responder esta solicitud.');
            }

            $request['approval_status'] = $decision === 'approve' ? 'aprobada' : 'rechazada';
            $request['approved_by_email'] = $email;
            $request['approved_by_name'] = admin_user_display_name($user);
            $request['approved_at'] = date('c');
            $store['change_requests'][$index] = $request;

            $reservation = calendar_get_reservation($store, $request['room'], $request['date']);
            if ($decision === 'approve' && $reservation) {
                $oldPayload = $reservation;
                if (($request['requested_status'] ?? '') === 'liberar') {
                    calendar_remove_reservation($store, $request['room'], $request['date']);
                    calendar_append_audit($store, 'approve_change', $email, calendar_reservation_key($request['room'], $request['date']), $oldPayload, null);
                } else {
                    $reservation['status'] = calendar_normalize_status($request['requested_status'] ?? 'reservada');
                    $reservation['responsable_label'] = (string) ($request['requested_responsable_label'] ?? '');
                    $reservation['notes'] = (string) ($request['requested_notes'] ?? '');
                    $reservation['owner_email'] = (string) ($request['requested_by_email'] ?? $reservation['owner_email']);
                    $reservation['owner_name'] = (string) ($request['requested_by_name'] ?? $reservation['owner_name']);
                    $reservation['updated_at'] = date('c');
                    $reservation['updated_by'] = $email;
                    $reservation['updated_by_name'] = admin_user_display_name($user);
                    $reservation['version'] = (int) ($reservation['version'] ?? 0) + 1;
                    calendar_set_reservation($store, $request['room'], $request['date'], $reservation);
                    calendar_append_audit($store, 'approve_change', $email, calendar_reservation_key($request['room'], $request['date']), $oldPayload, $reservation);
                }
            } else {
                calendar_append_audit($store, 'reject_change', $email, calendar_reservation_key($request['room'], $request['date']), null, $request);
            }

            $mailSent = false;
            if ($sendEmail) {
                $mailSent = calendar_api_send_request_result_notice($request, $decision);
            }

            return array('ok' => true, 'request' => $request, 'message' => $decision === 'approve' ? 'Solicitud aprobada.' : 'Solicitud rechazada.', 'mail_sent' => $mailSent);
        }

        return array('ok' => false, 'message' => 'No se encontró la solicitud.');
    });

    calendar_api_response($result, !empty($result['ok']) ? 200 : 404);
}

if ($action === 'save_holiday') {
    if (!calendar_user_can_manage_holidays($user)) {
        calendar_api_response(array('ok' => false, 'message' => 'Solo coordinación, directivos o admin pueden modificar días especiales.'), 403);
    }

    $date = isset($input['date']) ? (string) $input['date'] : '';
    $label = trim((string) ($input['label'] ?? ''));
    if (!calendar_is_valid_date_key($date) || $label === '') {
        calendar_api_response(array('ok' => false, 'message' => 'Completa la fecha y el motivo.'), 422);
    }

    $year = substr($date, 0, 4);
    list(, $store, $result) = calendar_store_mutate(function (&$store) use ($user, $date, $label, $year) {
        if (!isset($store['custom_holidays'][(string) $year]) || !is_array($store['custom_holidays'][(string) $year])) {
            $store['custom_holidays'][(string) $year] = array();
        }
        $store['custom_holidays'][(string) $year][$date] = array(
            'date' => $date,
            'label' => $label,
            'created_by' => admin_normalize_email($user['email']),
            'created_by_name' => admin_user_display_name($user),
            'updated_at' => date('c'),
        );
        return array('ok' => true, 'message' => 'Día especial guardado.');
    });

    calendar_api_response(array_merge($result, array('custom_holidays' => $store['custom_holidays'][(string) $year])), 200);
}

if ($action === 'remove_holiday') {
    if (!calendar_user_can_manage_holidays($user)) {
        calendar_api_response(array('ok' => false, 'message' => 'Solo coordinación, directivos o admin pueden modificar días especiales.'), 403);
    }

    $date = isset($input['date']) ? (string) $input['date'] : '';
    if (!calendar_is_valid_date_key($date)) {
        calendar_api_response(array('ok' => false, 'message' => 'La fecha es inválida.'), 422);
    }

    $year = substr($date, 0, 4);
    list(, $store, $result) = calendar_store_mutate(function (&$store) use ($year, $date) {
        if (isset($store['custom_holidays'][(string) $year][$date])) {
            unset($store['custom_holidays'][(string) $year][$date]);
        }
        return array('ok' => true, 'message' => 'Día especial eliminado.');
    });

    $holidays = isset($store['custom_holidays'][(string) $year]) ? $store['custom_holidays'][(string) $year] : array();
    calendar_api_response(array_merge($result, array('custom_holidays' => $holidays)), 200);
}

if ($action === 'import_period') {
    if (!calendar_user_can_override($user)) {
        calendar_api_response(array('ok' => false, 'message' => 'Solo admin, directivos o coordinación pueden importar respaldos.'), 403);
    }

    $payload = isset($input['payload']) && is_array($input['payload']) ? $input['payload'] : null;
    if (!$payload) {
        calendar_api_response(array('ok' => false, 'message' => 'No llegó ningún respaldo válido.'), 422);
    }

    $year = isset($payload['year']) ? (int) $payload['year'] : (int) date('Y');
    $room = calendar_normalize_room(isset($payload['room']) ? $payload['room'] : 'basica');
    $semester = calendar_normalize_semester(isset($payload['semester']) ? $payload['semester'] : 's1');
    $reservations = isset($payload['reservations']) && is_array($payload['reservations']) ? $payload['reservations'] : array();
    $customHolidays = isset($payload['custom_holidays']) && is_array($payload['custom_holidays']) ? $payload['custom_holidays'] : array();

    list(, , $result) = calendar_store_mutate(function (&$store) use ($user, $year, $room, $semester, $reservations, $customHolidays) {
        $email = admin_normalize_email($user['email']);

        foreach ($reservations as $date => $reservation) {
            if (!calendar_is_valid_date_key($date) || !calendar_date_in_semester($date, $year, $semester)) {
                continue;
            }
            $current = calendar_get_reservation($store, $room, $date);
            $status = calendar_normalize_status($reservation['status'] ?? 'reservada');
            $responsable = trim((string) ($reservation['responsable_label'] ?? ''));
            $notes = trim((string) ($reservation['notes'] ?? ''));

            if ($status === 'disponible' && $responsable === '' && $notes === '') {
                calendar_remove_reservation($store, $room, $date);
                continue;
            }

            if (!$current) {
                $store['meta']['last_reservation_id'] = (int) ($store['meta']['last_reservation_id'] ?? 0) + 1;
                $current = array(
                    'id' => $store['meta']['last_reservation_id'],
                    'date' => $date,
                    'room' => $room,
                    'created_at' => date('c'),
                    'created_by' => $email,
                );
            }

            $current['status'] = $status;
            $current['responsable_label'] = $responsable;
            $current['notes'] = $notes;
            $current['owner_email'] = $reservation['owner_email'] ?? $email;
            $current['owner_name'] = $reservation['owner_name'] ?? admin_user_display_name($user);
            $current['updated_at'] = date('c');
            $current['updated_by'] = $email;
            $current['updated_by_name'] = admin_user_display_name($user);
            $current['version'] = (int) ($current['version'] ?? 0) + 1;
            $current['is_locked'] = true;
            calendar_set_reservation($store, $room, $date, $current);
        }

        if (!isset($store['custom_holidays'][(string) $year]) || !is_array($store['custom_holidays'][(string) $year])) {
            $store['custom_holidays'][(string) $year] = array();
        }
        foreach ($customHolidays as $date => $holiday) {
            if (!calendar_is_valid_date_key($date) || !calendar_date_in_semester($date, $year, $semester)) {
                continue;
            }
            $store['custom_holidays'][(string) $year][$date] = array(
                'date' => $date,
                'label' => trim((string) ($holiday['label'] ?? 'Jornada interna')),
                'created_by' => $email,
                'created_by_name' => admin_user_display_name($user),
                'updated_at' => date('c'),
            );
        }

        return array('ok' => true, 'message' => 'Respaldo importado al período actual.');
    });

    calendar_api_response($result, 200);
}

calendar_api_response(array('ok' => false, 'message' => 'Acción desconocida.'), 404);
