<?php

declare(strict_types=1);

namespace McpServer\Auth;

/**
 * Service for sending authentication and security emails (e.g. 2FA OTP codes).
 *
 * Supports three drivers via config/mail.php:
 * 1. 'mail': PHP built-in mail() function.
 * 2. 'smtp': Native zero-dependency socket SMTP.
 * 3. 'log':  Appends outgoing emails to data/mail.log (for local development/testing).
 *
 * Automatic Fallback: If 'mail' or 'smtp' fails to transmit (e.g. no local MTA configured
 * on Windows/development), the message and OTP are automatically logged to data/mail.log
 * and DebugLog so the user is never stranded during testing or local usage.
 */
final class EmailService {
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed>|null $config
     */
    public function __construct(?array $config = null) {
        $defaultConfig = [
            'driver' => 'mail',
            'from' => [
                'address' => 'no-reply@simplemcp.local',
                'name' => 'SimpleMCP Security',
            ],
            'smtp' => [
                'host' => '127.0.0.1',
                'port' => 25,
                'encryption' => null,
                'username' => '',
                'password' => '',
                'timeout' => 5,
            ],
            'log_path' => dirname(__DIR__, 2) . '/data/mail.log',
        ];

        if ($config === null) {
            $configFile = dirname(__DIR__, 2) . '/config/mail.php';
            if (file_exists($configFile)) {
                $loaded = require $configFile;
                $config = is_array($loaded) ? $loaded : [];
            } else {
                $config = [];
            }
        }

        $this->config = array_replace_recursive($defaultConfig, $config);
    }

    /**
     * Send a 6-digit OTP verification email for a new device login.
     *
     * @param string $toEmail Recipient email address
     * @param string $otp 6-digit one-time password
     * @param string $username Account username
     * @param string $userAgent Browser / client user agent
     * @param string $ip Remote IP address
     * @return bool True if delivered or logged successfully
     */
    public function sendOtpEmail(string $toEmail, string $otp, string $username, string $userAgent = '', string $ip = ''): bool {
        $subject = "[SimpleMCP] {$otp} is your verification code";
        $now = date('Y-m-d H:i:s T');

        $deviceSummary = 'New browser or device';
        if ($userAgent !== '') {
            $deviceSummary .= ' (' . substr($userAgent, 0, 80) . ')';
        }
        if ($ip !== '') {
            $deviceSummary .= " from IP {$ip}";
        }

        $textBody = <<<TEXT
Hello {$username},

A login attempt was made to your SimpleMCP account from an unrecognized device:
- Device: {$deviceSummary}
- Time: {$now}

Your 6-digit verification code is:

    {$otp}

This code will expire in 10 minutes. Enter this code on the verification screen to confirm your identity and trust this device.

If you did not attempt this login, someone else may know your password. Please change your password immediately.

--
SimpleMCP Security Team
TEXT;

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.5; color: #1e293b; max-width: 520px; margin: 0 auto; padding: 20px; }
.card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 28px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
.header { font-size: 1.25rem; font-weight: 700; color: #0f172a; margin-bottom: 12px; }
.code-box { background: #f1f5f9; border: 2px dashed #94a3b8; border-radius: 8px; text-align: center; padding: 18px; margin: 24px 0; }
.code { font-size: 2.2rem; font-weight: 800; letter-spacing: 6px; color: #2563eb; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
.hint { font-size: 0.88rem; color: #64748b; margin-top: 6px; }
.details { background: #f8fafc; border-radius: 6px; padding: 12px 16px; font-size: 0.88rem; color: #334155; margin: 16px 0; }
.footer { font-size: 0.8rem; color: #94a3b8; margin-top: 24px; border-top: 1px solid #f1f5f9; padding-top: 12px; }
</style>
</head>
<body>
<div class="card">
    <div class="header">SimpleMCP Two-Factor Authentication</div>
    <p>Hello <strong>{$username}</strong>,</p>
    <p>A sign-in request was initiated from an unrecognized device or browser. Use the code below to complete your login:</p>
    <div class="code-box">
        <div class="code">{$otp}</div>
        <div class="hint">Valid for 10 minutes</div>
    </div>
    <div class="details">
        <div><strong>Device:</strong> {$deviceSummary}</div>
        <div><strong>Time:</strong> {$now}</div>
    </div>
    <p style="font-size:0.88rem;color:#64748b;">If you did not request this login, someone may have obtained your credentials. Change your password immediately.</p>
    <div class="footer">SimpleMCP Security Notification</div>
</div>
</body>
</html>
HTML;

        $driver = (string) ($this->config['driver'] ?? 'mail');
        $success = false;

        if ($driver === 'smtp') {
            $success = $this->sendViaSmtp($toEmail, $subject, $textBody, $htmlBody);
        } elseif ($driver === 'mail') {
            $success = $this->sendViaPhpMail($toEmail, $subject, $textBody, $htmlBody);
        }

        // If driver is 'log' or if network sending failed, write to mail.log as reliable fallback
        if (!$success) {
            $this->logMail($toEmail, $subject, $otp, $textBody);
            DebugLog::write("EMAIL 2FA OTP={$otp} to={$toEmail} user={$username} fallback=logged");
            return true;
        }

        DebugLog::write("EMAIL 2FA OTP={$otp} to={$toEmail} user={$username} sent via {$driver}");
        // Also log to mail.log in development for testing visibility
        $this->logMail($toEmail, $subject, $otp, $textBody);
        return true;
    }

    private function sendViaPhpMail(string $to, string $subject, string $textBody, string $htmlBody): bool {
        $fromAddress = (string) ($this->config['from']['address'] ?? 'no-reply@localhost');
        $fromName = (string) ($this->config['from']['name'] ?? 'SimpleMCP Security');

        $boundary = 'b1_' . bin2hex(random_bytes(16));

        $headers = [];
        $headers[] = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromAddress}>";
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
        $headers[] = "X-Mailer: SimpleMCP 2FA Mailer";

        $message = "--{$boundary}\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $textBody . "\r\n\r\n";
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $htmlBody . "\r\n\r\n";
        $message .= "--{$boundary}--";

        try {
            // Silence warnings if local mail agent is not configured
            return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $message, implode("\r\n", $headers));
        } catch (\Throwable $e) {
            DebugLog::write("EMAIL sendViaPhpMail failed: " . $e->getMessage());
            return false;
        }
    }

    private function sendViaSmtp(string $to, string $subject, string $textBody, string $htmlBody): bool {
        $smtp = (array) ($this->config['smtp'] ?? []);
        $host = (string) ($smtp['host'] ?? '127.0.0.1');
        $port = (int) ($smtp['port'] ?? 25);
        $timeout = (int) ($smtp['timeout'] ?? 5);
        $encryption = $smtp['encryption'] ?? null;
        $username = (string) ($smtp['username'] ?? '');
        $password = (string) ($smtp['password'] ?? '');
        $from = (string) ($this->config['from']['address'] ?? 'no-reply@localhost');

        $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout);
        if (!$socket) {
            DebugLog::write("SMTP socket connect error: {$errstr} ({$errno})");
            return false;
        }

        try {
            stream_set_timeout($socket, $timeout);

            $this->readSmtpResponse($socket, 220);
            $this->writeSmtpCommand($socket, "EHLO " . gethostname(), 250);

            if ($encryption === 'tls') {
                $this->writeSmtpCommand($socket, "STARTTLS", 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException("STARTTLS negotiation failed");
                }
                $this->writeSmtpCommand($socket, "EHLO " . gethostname(), 250);
            }

            if ($username !== '' && $password !== '') {
                $this->writeSmtpCommand($socket, "AUTH LOGIN", 334);
                $this->writeSmtpCommand($socket, base64_encode($username), 334);
                $this->writeSmtpCommand($socket, base64_encode($password), 235);
            }

            $this->writeSmtpCommand($socket, "MAIL FROM:<{$from}>", 250);
            $this->writeSmtpCommand($socket, "RCPT TO:<{$to}>", 250);
            $this->writeSmtpCommand($socket, "DATA", 354);

            $boundary = 'b1_' . bin2hex(random_bytes(16));
            $fromName = (string) ($this->config['from']['name'] ?? 'SimpleMCP Security');
            $data = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n"
                  . "To: <{$to}>\r\n"
                  . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
                  . "MIME-Version: 1.0\r\n"
                  . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n"
                  . "--{$boundary}\r\n"
                  . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
                  . $textBody . "\r\n\r\n"
                  . "--{$boundary}\r\n"
                  . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
                  . $htmlBody . "\r\n\r\n"
                  . "--{$boundary}--\r\n.\r\n";

            fwrite($socket, $data);
            $this->readSmtpResponse($socket, 250);
            $this->writeSmtpCommand($socket, "QUIT", 221);
            fclose($socket);
            return true;
        } catch (\Throwable $e) {
            DebugLog::write("SMTP transmission failed: " . $e->getMessage());
            if (is_resource($socket)) {
                fclose($socket);
            }
            return false;
        }
    }

    private function readSmtpResponse($socket, int $expectedCode): string {
        $response = '';
        while (!feof($socket)) {
            $line = fgets($socket, 512);
            if ($line === false) {
                break;
            }
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new \RuntimeException("SMTP unexpected response: " . trim($response));
        }
        return $response;
    }

    private function writeSmtpCommand($socket, string $cmd, int $expectedCode): void {
        fwrite($socket, $cmd . "\r\n");
        $this->readSmtpResponse($socket, $expectedCode);
    }

    private function logMail(string $to, string $subject, string $otp, string $body): void {
        $logPath = (string) ($this->config['log_path'] ?? (dirname(__DIR__, 2) . '/data/mail.log'));
        $dir = dirname($logPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $entry = sprintf(
            "[%s] TO: %s | OTP: %s | SUBJECT: %s\n%s\n%s\n",
            date('Y-m-d H:i:s'),
            $to,
            $otp,
            $subject,
            str_repeat('-', 60),
            $body
        );
        file_put_contents($logPath, $entry, FILE_APPEND | LOCK_EX);
    }
}
