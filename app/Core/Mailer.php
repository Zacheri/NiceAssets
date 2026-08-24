<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

final class Mailer
{
    public static function send(string $toEmail, string $toName, string $subject, string $html): bool
    {
        if (trim($toEmail) === '') {
            return false;
        }
        $from = Setting::get('mail_from', Config::get('mail.from'));
        $fromName = Config::get('mail.from_name');
        $host = Setting::get('smtp_host', Config::get('mail.smtp_host'));
        try {
            if ($host !== null && trim((string) $host) !== '') {
                self::sendSmtp($host, $from, $fromName, $toEmail, $toName, $subject, $html);
            } else {
                self::sendMail($from, $fromName, $toEmail, $toName, $subject, $html);
            }
            return true;
        } catch (\Throwable $e) {
            Logger::error('Mail send failed', ['to' => $toEmail, 'error' => $e->getMessage()]);
            @file_put_contents(
                Config::get('storage.logs') . '/mail.log',
                '[' . date('Y-m-d H:i:s') . '] FAILED to=' . $toEmail . ' subject=' . $subject . ' error=' . $e->getMessage() . "\n",
                FILE_APPEND | LOCK_EX
            );
            return false;
        }
    }

    private static function buildMessage(string $from, string $fromName, string $toEmail, string $subject, string $html): array
    {
        $boundary = 'atr_' . bin2hex(random_bytes(8));
        $encodedFrom = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = implode("\r\n", [
            'MIME-Version: 1.0',
            'From: ' . $encodedFrom . ' <' . $from . '>',
            'To: <' . $toEmail . '>',
            'Subject: ' . $encodedSubject,
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ]);
        $body = implode("\r\n", [
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim(chunk_split(base64_encode('ATR Inventory notification: ' . $subject))),
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim(chunk_split(base64_encode($html))),
            '--' . $boundary . '--',
        ]);
        return [$headers, $body];
    }

    private static function sendMail(string $from, string $fromName, string $toEmail, string $toName, string $subject, string $html): void
    {
        [$headers, $body] = self::buildMessage($from, $fromName, $toEmail, $subject, $html);
        if (!mail($toEmail, $subject, $body, $headers)) {
            throw new \RuntimeException('mail() returned false');
        }
    }

    private static function sendSmtp(string $host, string $from, string $fromName, string $toEmail, string $toName, string $subject, string $html): void
    {
        $port = (int) Setting::get('smtp_port', Config::get('mail.smtp_port'));
        $user = (string) Setting::get('smtp_user', Config::get('mail.smtp_user'));
        $pass = (string) Setting::get('smtp_pass', Config::get('mail.smtp_pass'));

        $socket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 10);
        if (!$socket) {
            throw new \RuntimeException("SMTP connect failed: $errstr ($errno)");
        }
        stream_set_timeout($socket, 15);
        $read = static function () use ($socket): string {
            $data = '';
            while ($line = fgets($socket, 515)) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };

        $read();
        self::smtpCommand($socket, $read, 'EHLO ' . gethostname(), [250]);
        if ($port === 587 || Setting::get('smtp_secure', 'tls') === 'tls') {
            self::smtpCommand($socket, $read, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('TLS negotiation failed');
            }
            self::smtpCommand($socket, $read, 'EHLO ' . gethostname(), [250]);
        }
        if ($user !== '') {
            self::smtpCommand($socket, $read, 'AUTH LOGIN', [334]);
            self::smtpCommand($socket, $read, base64_encode($user), [334]);
            self::smtpCommand($socket, $read, base64_encode($pass), [235]);
        }
        self::smtpCommand($socket, $read, 'MAIL FROM:<' . $from . '>', [250]);
        self::smtpCommand($socket, $read, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
        self::smtpCommand($socket, $read, 'DATA', [354]);

        [$headers, $body] = self::buildMessage($from, $fromName, $toEmail, $subject, $html);
        $message = $headers . "\r\n\r\n" . $body . "\r\n";
        $escaped = str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], $message);
        fwrite($socket, $escaped . "\r\n.");
        $response = $read();
        if (!str_starts_with($response, '250')) {
            throw new \RuntimeException('SMTP DATA rejected: ' . trim($response));
        }
        fwrite($socket, "QUIT\r\n");
        fclose($socket);
    }

    private static function smtpCommand($socket, callable $read, string $command, array $expectedCodes): void
    {
        fwrite($socket, $command . "\r\n");
        $response = $read();
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new \RuntimeException("SMTP '$command' rejected: " . trim($response));
        }
    }

    public static function alertHtml(string $title, string $message, array $lines = [], string $link = '/'): string
    {
        $rows = '';
        foreach ($lines as $line) {
            $rows .= '<tr><td style="padding:6px 10px;border-bottom:1px solid #e2e8f0">' . e($line) . '</td></tr>';
        }
        return '<!doctype html><html><body style="margin:0;padding:24px;background:#f1f5f9;font-family:system-ui,Arial,sans-serif;color:#0f172a">'
            . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0">'
            . '<div style="background:#0f172a;padding:16px 24px"><span style="color:#38bdf8;font-weight:700">ATR Inventory</span></div>'
            . '<div style="padding:24px">'
            . '<h2 style="margin:0 0 8px;font-size:18px">' . e($title) . '</h2>'
            . '<p style="margin:0 0 16px;color:#475569">' . e($message) . '</p>'
            . ($rows !== '' ? '<table style="width:100%;border-collapse:collapse;font-size:13px">' . $rows . '</table>' : '')
            . '<p style="margin:20px 0 0"><a href="' . e(url($link)) . '" style="display:inline-block;background:#2563eb;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none">Open ATR Inventory</a></p>'
            . '</div></div></body></html>';
    }
}
