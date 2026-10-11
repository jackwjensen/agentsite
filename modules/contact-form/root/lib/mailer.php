<?php
declare(strict_types=1);

// Installed from modules/contact-form (v2). To update: uninstall, then install.
//
// Minimal SMTP client — fsockopen, STARTTLS by capability, AUTH LOGIN only
// when credentials are configured and only over an encrypted channel.
//
// Configuration comes from the environment: SMTP_HOST, SMTP_PORT (default
// 587), SMTP_USER, SMTP_PASS, MAIL_TO, MAIL_FROM, MAIL_FROM_NAME.
//
// Every outbound message is also written to _logs/mail/ as a timestamped
// .txt file. That is a delivery receipt in production and the only record in
// development — but note carefully: **a log file means the mailer was
// reached, NOT that mail was sent.** Only a successful send returns true.

require_once ROOT . '/lib/request.php';

function send_contact_mail(
    string $name,
    string $email,
    string $subject,
    string $message,
    string $lang,
    string &$debug_info = ''
): bool {
    $to        = getenv('MAIL_TO')        ?: (string) cfg('identity.email');
    $from      = getenv('MAIL_FROM')      ?: (string) cfg('identity.email');
    $from_name = getenv('MAIL_FROM_NAME') ?: (string) cfg('site.name') . ' (contact form)';

    // client_ip(), never REMOTE_ADDR: behind a reverse proxy the latter is
    // the proxy, so every delivered mail would name the same internal
    // address as the sender. The user agent is here because it is what
    // separates the two things a contact form receives that are neither
    // prospects nor caught by the spam layers — a scripted client announces
    // itself (python-requests, curl, Go-http-client) while a real browser's
    // string is unmistakable.
    $user_agent = request_header('HTTP_USER_AGENT');

    $body = "Name:    $name\n"
          . "Email:   $email\n"
          . "Subject: $subject\n"
          . "Lang:    $lang\n"
          . 'IP:      ' . client_ip() . "\n"
          . 'Agent:   ' . ($user_agent === '' ? '(none sent)' : $user_agent) . "\n"
          . 'Time:    ' . date('c') . "\n\n"
          . "Message:\n"
          . "--------\n"
          . $message . "\n";

    $msg = [
        'to'        => $to,
        'from'      => $from,
        'from_name' => $from_name,
        'reply_to'  => $email,
        'subject'   => '[' . strtoupper($lang) . '] ' . cfg('site.name') . ' – ' . $subject,
        'body'      => $body,
    ];

    $host = getenv('SMTP_HOST') ?: '';
    $user = getenv('SMTP_USER') ?: '';
    $pass = getenv('SMTP_PASS') ?: '';

    // Always write the log file — dev record + production safety net.
    mailer_write_log($msg);

    if ($host === '') {
        $debug_info = 'SMTP not configured — SMTP_HOST is empty.';
        return false;
    }

    // Both set = authenticated relay (production). Both empty =
    // unauthenticated relay (a local mail catcher). One of each is a
    // misconfiguration, not a mode.
    if (($user === '') !== ($pass === '')) {
        $debug_info = 'SMTP misconfigured — set both SMTP_USER and SMTP_PASS, or neither.';
        return false;
    }

    return smtp_send(
        [
            'host' => $host,
            'port' => (int) (getenv('SMTP_PORT') ?: 587),
            'user' => $user,
            'pass' => $pass,
        ],
        $msg,
        $debug_info
    );
}

function mailer_write_log(array $msg): void
{
    $log_dir = ROOT . '/_logs/mail';
    if (!is_dir($log_dir) && !mkdir($log_dir, 0775, true) && !is_dir($log_dir)) {
        error_log('mailer: could not create ' . $log_dir);
        return;
    }
    $filename = date('Y-m-d_His') . '_' . bin2hex(random_bytes(2)) . '.txt';
    $entry = 'Time:    ' . date('c') . "\n"
           . 'To:      ' . $msg['to'] . "\n"
           . 'From:    ' . $msg['from_name'] . ' <' . $msg['from'] . ">\n"
           . 'ReplyTo: ' . $msg['reply_to'] . "\n"
           . 'Subject: ' . $msg['subject'] . "\n"
           . str_repeat('-', 60) . "\n"
           . $msg['body'] . "\n";
    if (file_put_contents($log_dir . '/' . $filename, $entry) === false) {
        error_log('mailer: could not write log ' . $filename);
    }
}

function smtp_send(array $cfg, array $msg, string &$debug_info = ''): bool
{
    $errno  = 0;
    $errstr = '';
    // Suppressed deliberately: fsockopen emits a warning AND returns false on
    // an unreachable host; the message is captured in $errstr and reported
    // through $debug_info, so the warning would be a duplicate reaching the
    // browser rather than the log.
    $sock = @fsockopen($cfg['host'], $cfg['port'], $errno, $errstr, 15);
    if ($sock === false) {
        $debug_info = "Connect failed to {$cfg['host']}:{$cfg['port']} — $errstr (errno $errno)";
        error_log('SMTP ' . $debug_info);
        return false;
    }
    stream_set_timeout($sock, 15);

    $read = function () use ($sock): string {
        $out = '';
        while (!feof($sock)) {
            $line = fgets($sock, 1024);
            if ($line === false) break;
            $out .= $line;
            // Multi-line responses: a space in position 3 marks the last line.
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $out;
    };

    $say = function (string $cmd) use ($sock): void {
        fwrite($sock, $cmd . "\r\n");
    };

    // Returns the full response on success (callers that need capabilities
    // read it), or false on a code mismatch.
    $expect = function (int $code, string $step) use ($read, &$debug_info) {
        $resp = $read();
        if ((int) substr($resp, 0, 3) !== $code) {
            $debug_info = "$step: expected $code, got: " . trim($resp);
            error_log('SMTP ' . $debug_info);
            return false;
        }
        return $resp;
    };

    $helo_host = parse_url((string) cfg('site.url'), PHP_URL_HOST) ?: 'localhost';

    try {
        if ($expect(220, 'greeting') === false) return false;

        $say('EHLO ' . $helo_host);
        $ehlo = $expect(250, 'EHLO');
        if ($ehlo === false) return false;

        // STARTTLS by capability, not by port heuristic: a real mail server
        // advertises it and gets encrypted; a local dev catcher does not and
        // is spoken to in plain text.
        $encrypted = false;
        if (stripos((string) $ehlo, 'STARTTLS') !== false) {
            $say('STARTTLS');
            if ($expect(220, 'STARTTLS') === false) return false;
            if (!stream_socket_enable_crypto(
                $sock,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
                | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
            )) {
                $debug_info = 'TLS handshake failed';
                error_log('SMTP ' . $debug_info);
                return false;
            }
            $encrypted = true;
            $say('EHLO ' . $helo_host);
            if ($expect(250, 'EHLO after STARTTLS') === false) return false;
        }

        // AUTH only when credentials exist, and never in plain text: a
        // TLS-stripping relay gets a failed send rather than your password.
        if ($cfg['user'] !== '') {
            if (!$encrypted) {
                $debug_info = 'refusing AUTH — server did not offer STARTTLS, and credentials must not travel in plain text';
                error_log('SMTP ' . $debug_info);
                return false;
            }
            $say('AUTH LOGIN');
            if ($expect(334, 'AUTH LOGIN') === false)   return false;
            $say(base64_encode($cfg['user']));
            if ($expect(334, 'AUTH username') === false) return false;
            $say(base64_encode($cfg['pass']));
            if ($expect(235, 'AUTH password') === false) return false;
        }

        $say('MAIL FROM:<' . $msg['from'] . '>');
        if ($expect(250, 'MAIL FROM') === false) return false;
        $say('RCPT TO:<' . $msg['to'] . '>');
        if ($expect(250, 'RCPT TO') === false)   return false;

        $say('DATA');
        if ($expect(354, 'DATA') === false)      return false;

        $headers = [
            'From: "' . addslashes($msg['from_name']) . '" <' . $msg['from'] . '>',
            'To: <' . $msg['to'] . '>',
            'Reply-To: <' . $msg['reply_to'] . '>',
            'Subject: ' . mb_encode_mimeheader($msg['subject']),
            'Date: ' . date('r'),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        // CRLF line endings; dot-stuff lines starting with "."
        $body = str_replace("\r\n", "\n", $msg['body']);
        $body = str_replace("\n", "\r\n", $body);
        $body = preg_replace('/^\./m', '..', $body);

        $say(implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.");
        if ($expect(250, 'message accepted') === false) return false;

        $say('QUIT');
        return true;
    } finally {
        fclose($sock);
    }
}
