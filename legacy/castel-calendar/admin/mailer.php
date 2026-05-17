<?php

function castel_mailer_config()
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = __DIR__ . '/mail_config.php';
    if (!file_exists($path)) {
        return null;
    }

    $loaded = require $path;
    if (!is_array($loaded)) {
        $config = null;
        return $config;
    }
    $envPass = getenv('CASTEL_SMTP_PASSWORD');
    if ($envPass !== false && $envPass !== '') {
        $loaded['password'] = (string) $envPass;
    }
    $config = $loaded;
    return $config;
}

function castel_mailer_read_response($socket)
{
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $response .= $line;
        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }
    return $response;
}

function castel_mailer_expect($response, array $expectedCodes)
{
    foreach ($expectedCodes as $code) {
        if (strpos($response, (string) $code) === 0) {
            return true;
        }
    }
    return false;
}

function castel_mailer_write($socket, $command)
{
    fwrite($socket, $command . "\r\n");
}

function castel_mailer_send($to, $subject, $body, &$error = null, $htmlBody = null)
{
    $config = castel_mailer_config();
    if (!$config) {
        $error = 'No existe configuración SMTP.';
        return false;
    }

    $host = isset($config['host']) ? (string) $config['host'] : '';
    $port = isset($config['port']) ? (int) $config['port'] : 465;
    $secure = isset($config['secure']) ? (string) $config['secure'] : 'ssl';
    $username = isset($config['username']) ? (string) $config['username'] : '';
    $password = isset($config['password']) ? (string) $config['password'] : '';
    $fromEmail = isset($config['from_email']) ? (string) $config['from_email'] : $username;
    $fromName = isset($config['from_name']) ? (string) $config['from_name'] : 'RoomKeeper';
    $replyTo = isset($config['reply_to']) ? (string) $config['reply_to'] : $fromEmail;

    if ($host === '' || $username === '' || $password === '' || $fromEmail === '') {
        $error = 'La configuración SMTP está incompleta.';
        return false;
    }

    $transport = $secure === 'ssl' ? 'ssl://' . $host : $host;
    $socket = @stream_socket_client($transport . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        $error = 'No se pudo conectar al servidor SMTP: ' . $errstr;
        return false;
    }

    stream_set_timeout($socket, 20);

    $response = castel_mailer_read_response($socket);
    if (!castel_mailer_expect($response, array(220))) {
        fclose($socket);
        $error = 'Respuesta SMTP inválida al conectar: ' . trim($response);
        return false;
    }

    castel_mailer_write($socket, 'EHLO roomkeeper.local');
    $response = castel_mailer_read_response($socket);
    if (!castel_mailer_expect($response, array(250))) {
        fclose($socket);
        $error = 'EHLO falló: ' . trim($response);
        return false;
    }

    castel_mailer_write($socket, 'AUTH LOGIN');
    $response = castel_mailer_read_response($socket);
    if (!castel_mailer_expect($response, array(334))) {
        fclose($socket);
        $error = 'AUTH LOGIN falló: ' . trim($response);
        return false;
    }

    castel_mailer_write($socket, base64_encode($username));
    $response = castel_mailer_read_response($socket);
    if (!castel_mailer_expect($response, array(334))) {
        fclose($socket);
        $error = 'Usuario SMTP rechazado: ' . trim($response);
        return false;
    }

    castel_mailer_write($socket, base64_encode($password));
    $response = castel_mailer_read_response($socket);
    if (!castel_mailer_expect($response, array(235))) {
        fclose($socket);
        $error = 'Contraseña SMTP rechazada: ' . trim($response);
        return false;
    }

    castel_mailer_write($socket, 'MAIL FROM:<' . $fromEmail . '>');
    $response = castel_mailer_read_response($socket);
    if (!castel_mailer_expect($response, array(250))) {
        fclose($socket);
        $error = 'MAIL FROM rechazado: ' . trim($response);
        return false;
    }

    castel_mailer_write($socket, 'RCPT TO:<' . $to . '>');
    $response = castel_mailer_read_response($socket);
    if (!castel_mailer_expect($response, array(250, 251))) {
        fclose($socket);
        $error = 'RCPT TO rechazado: ' . trim($response);
        return false;
    }

    castel_mailer_write($socket, 'DATA');
    $response = castel_mailer_read_response($socket);
    if (!castel_mailer_expect($response, array(354))) {
        fclose($socket);
        $error = 'DATA rechazado: ' . trim($response);
        return false;
    }

    $headers = array(
        'Date: ' . date('r'),
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'Reply-To: ' . $replyTo,
        'To: ' . $to,
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'MIME-Version: 1.0',
        'X-Mailer: RoomKeeper SMTP Mailer',
    );

    if ($htmlBody !== null && $htmlBody !== '') {
        $boundary = 'castel_' . bin2hex(random_bytes(10));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $plainPart = preg_replace("/(?<!\r)\n/", "\r\n", (string) $body);
        $htmlPart = preg_replace("/(?<!\r)\n/", "\r\n", (string) $htmlBody);
        $mimeBody =
            '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $plainPart . "\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $htmlPart . "\r\n"
            . '--' . $boundary . '--';
        $normalizedBody = preg_replace('/^\./m', '..', $mimeBody);
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $normalizedBody = preg_replace("/(?<!\r)\n/", "\r\n", $body);
        $normalizedBody = preg_replace('/^\./m', '..', $normalizedBody);
    }

    $message = implode("\r\n", $headers) . "\r\n\r\n" . $normalizedBody . "\r\n.";
    fwrite($socket, $message . "\r\n");

    $response = castel_mailer_read_response($socket);
    if (!castel_mailer_expect($response, array(250))) {
        fclose($socket);
        $error = 'Envío DATA falló: ' . trim($response);
        return false;
    }

    castel_mailer_write($socket, 'QUIT');
    fclose($socket);
    return true;
}
