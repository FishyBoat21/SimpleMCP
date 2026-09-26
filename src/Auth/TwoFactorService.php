<?php

declare(strict_types=1);

namespace McpServer\Auth;

use PDO;

/**
 * Two-Factor Authentication (2FA) and Trusted Device management.
 *
 * Enforces email-based 2FA when logging in from an unrecognized device:
 * 1. Checks if the incoming request presents a valid trusted device cookie.
 * 2. If untrusted ("new device"), issues a cryptographically secure 6-digit OTP
 *    and sends it to the user's registered email.
 * 3. Upon successful verification of the OTP, registers and trusts the device
 *    via a secure long-lived cookie so subsequent logins from this device bypass 2FA.
 */
final class TwoFactorService {
    public const DEVICE_COOKIE_NAME = 'simplemcp_device';
    public const OTP_TTL_SECONDS = 600; // 10 minutes
    public const MAX_OTP_ATTEMPTS = 5;

    public function __construct(
        private readonly Database $db,
        private readonly UserStore $users,
        private readonly EmailService $emailService,
    ) {}

    /**
     * Check if the device is recognized and trusted for the specified user.
     *
     * @param string $username Account username
     * @param string|null $rawCookie Raw value of the device cookie
     * @return bool True if device is trusted and unexpired
     */
    public function isTrustedDevice(string $username, ?string $rawCookie): bool {
        if ($rawCookie === null || trim($rawCookie) === '') {
            return false;
        }

        $hash = hash('sha256', trim($rawCookie));
        $now = time();

        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM trusted_devices WHERE username = :username AND device_hash = :hash AND expires_at > :now LIMIT 1'
        );
        $stmt->execute([
            ':username' => $username,
            ':hash' => $hash,
            ':now' => $now,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }

        // Update last used timestamp
        $updateStmt = $this->db->pdo()->prepare('UPDATE trusted_devices SET last_used_at = :now WHERE id = :id');
        $updateStmt->execute([':now' => $now, ':id' => $row['id']]);

        return true;
    }

    /**
     * Trust the current device by generating a persistent token and saving its hash.
     *
     * @param string $username Account username
     * @param string $userAgent Client user-agent string
     * @param string $ip Client IP address
     * @param string $name Friendly name for the device
     * @param int $ttlDays Expiration in days (default 90 days)
     * @return string The raw device token to set in the client cookie
     */
    public function trustDevice(
        string $username,
        string $userAgent = '',
        string $ip = '',
        string $name = 'Web Browser',
        int $ttlDays = 90
    ): string {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $id = bin2hex(random_bytes(16));
        $now = time();
        $expiresAt = $now + ($ttlDays * 86400);

        if ($name === 'Web Browser' && $userAgent !== '') {
            $name = $this->friendlyDeviceName($userAgent);
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO trusted_devices (id, username, device_hash, user_agent, ip_address, name, created_at, last_used_at, expires_at)
             VALUES (:id, :username, :device_hash, :user_agent, :ip_address, :name, :created_at, :last_used_at, :expires_at)'
        );
        $stmt->execute([
            ':id' => $id,
            ':username' => $username,
            ':device_hash' => $hash,
            ':user_agent' => substr($userAgent, 0, 255),
            ':ip_address' => substr($ip, 0, 45),
            ':name' => $name,
            ':created_at' => $now,
            ':last_used_at' => $now,
            ':expires_at' => $expiresAt,
        ]);

        return $token;
    }

    /**
     * Revoke a trusted device.
     */
    public function revokeDevice(string $deviceId, string $username): bool {
        $stmt = $this->db->pdo()->prepare('DELETE FROM trusted_devices WHERE id = :id AND username = :username');
        $stmt->execute([':id' => $deviceId, ':username' => $username]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get all active trusted devices for a user.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTrustedDevices(string $username): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name, user_agent, ip_address, created_at, last_used_at, expires_at
             FROM trusted_devices
             WHERE username = :username AND expires_at > :now
             ORDER BY last_used_at DESC'
        );
        $stmt->execute([':username' => $username, ':now' => time()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Generate and dispatch a 6-digit OTP code to the user's email address.
     *
     * @return array{success: bool, otp: string, error: ?string}
     */
    public function sendOtp(string $username, string $email, string $userAgent = '', string $ip = ''): array {
        $email = trim($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'otp' => '', 'error' => 'A valid email address is required.'];
        }

        // Generate 6-digit numeric code
        $otp = sprintf('%06d', random_int(0, 999999));
        $otpHash = hash('sha256', $otp);
        $id = bin2hex(random_bytes(16));
        $now = time();
        $expiresAt = $now + self::OTP_TTL_SECONDS;

        // Invalidate any existing OTPs for this user
        $delStmt = $this->db->pdo()->prepare('DELETE FROM email_otps WHERE username = :username');
        $delStmt->execute([':username' => $username]);

        // Insert new OTP record
        $insStmt = $this->db->pdo()->prepare(
            'INSERT INTO email_otps (id, username, otp_hash, email, attempts, created_at, expires_at)
             VALUES (:id, :username, :otp_hash, :email, 0, :created_at, :expires_at)'
        );
        $insStmt->execute([
            ':id' => $id,
            ':username' => $username,
            ':otp_hash' => $otpHash,
            ':email' => $email,
            ':created_at' => $now,
            ':expires_at' => $expiresAt,
        ]);

        $sent = $this->emailService->sendOtpEmail($email, $otp, $username, $userAgent, $ip);
        if (!$sent) {
            return ['success' => false, 'otp' => '', 'error' => 'Failed to transmit verification email. Please try again.'];
        }

        return ['success' => true, 'otp' => $otp, 'error' => null];
    }

    /**
     * Verify an OTP submitted by the user.
     *
     * @return array{success: bool, error: ?string}
     */
    public function verifyOtp(string $username, string $submittedOtp): array {
        $otp = trim($submittedOtp);
        if ($otp === '' || !preg_match('/^[0-9]{6}$/', $otp)) {
            return ['success' => false, 'error' => 'Please enter a valid 6-digit verification code.'];
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM email_otps WHERE username = :username ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return ['success' => false, 'error' => 'No active verification code found. Please request a new code.'];
        }

        if ((int) $row['expires_at'] < time()) {
            $this->db->pdo()->prepare('DELETE FROM email_otps WHERE id = :id')->execute([':id' => $row['id']]);
            return ['success' => false, 'error' => 'The verification code has expired. Please request a new code.'];
        }

        $attempts = (int) $row['attempts'] + 1;

        if ($attempts > self::MAX_OTP_ATTEMPTS) {
            $this->db->pdo()->prepare('DELETE FROM email_otps WHERE id = :id')->execute([':id' => $row['id']]);
            return ['success' => false, 'error' => 'Too many failed verification attempts. Please request a new code.'];
        }

        if (hash_equals((string) $row['otp_hash'], hash('sha256', $otp))) {
            // Success! Delete the one-time code
            $this->db->pdo()->prepare('DELETE FROM email_otps WHERE id = :id')->execute([':id' => $row['id']]);
            return ['success' => true, 'error' => null];
        }

        // Update attempt count
        $this->db->pdo()->prepare('UPDATE email_otps SET attempts = :attempts WHERE id = :id')->execute([
            ':attempts' => $attempts,
            ':id' => $row['id'],
        ]);

        $remaining = self::MAX_OTP_ATTEMPTS - $attempts;
        $attemptMsg = $remaining > 0 ? " ({$remaining} attempts remaining)" : '';
        return ['success' => false, 'error' => "Incorrect verification code. Please check your email and try again{$attemptMsg}."];
    }

    /**
     * Send device cookie to client response.
     */
    public function setDeviceCookie(string $token, bool $isHttps, int $ttlDays = 90): void {
        if (!headers_sent()) {
            setcookie(self::DEVICE_COOKIE_NAME, $token, [
                'expires' => time() + ($ttlDays * 86400),
                'path' => '/',
                'domain' => '',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    /**
     * Clear the device cookie on client.
     */
    public function clearDeviceCookie(): void {
        if (!headers_sent()) {
            setcookie(self::DEVICE_COOKIE_NAME, '', [
                'expires' => time() - 86400,
                'path' => '/',
                'domain' => '',
                'secure' => false,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    /**
     * Mask an email address for privacy (e.g. j***n@example.com).
     */
    public function maskEmail(string $email): string {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return $email;
        }

        $local = $parts[0];
        $domain = $parts[1];
        $len = strlen($local);

        if ($len <= 2) {
            $maskedLocal = substr($local, 0, 1) . '***';
        } else {
            $maskedLocal = substr($local, 0, 1) . str_repeat('*', max(3, $len - 2)) . substr($local, -1);
        }

        return $maskedLocal . '@' . $domain;
    }

    private function friendlyDeviceName(string $ua): string {
        $os = 'Device';
        if (str_contains($ua, 'Windows')) $os = 'Windows';
        elseif (str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS')) $os = 'macOS';
        elseif (str_contains($ua, 'Linux')) $os = 'Linux';
        elseif (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) $os = 'iOS Device';
        elseif (str_contains($ua, 'Android')) $os = 'Android';

        $browser = 'Browser';
        if (str_contains($ua, 'Edg')) $browser = 'Edge';
        elseif (str_contains($ua, 'Chrome')) $browser = 'Chrome';
        elseif (str_contains($ua, 'Firefox')) $browser = 'Firefox';
        elseif (str_contains($ua, 'Safari') && !str_contains($ua, 'Chrome')) $browser = 'Safari';

        return "{$browser} on {$os}";
    }
}
