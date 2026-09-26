<?php

declare(strict_types=1);

/**
 * Mail configuration template for SimpleMCP.
 *
 * Copy this file to `config/mail.php` and configure your SMTP or preferred driver.
 *
 * Supported drivers:
 * - 'smtp': Connects to an external SMTP relay (e.g. Brevo, SendGrid, Mailgun, Gmail) via socket SMTP.
 * - 'log':  Writes all sent emails and 2FA OTP codes to data/mail.log (ideal for local testing).
 * - 'mail': Uses PHP's built-in mail() function.
 */
return [
    'driver' => 'smtp',
    'from' => [
        'address' => 'no-reply@example.com',
        'name' => 'SimpleMCP Security',
    ],
    'smtp' => [
        'host' => 'smtp-relay.brevo.com',
        'port' => 587,
        'encryption' => 'tls', // 'tls', 'ssl', or null
        'username' => 'your-smtp-username@example.com',
        'password' => 'your-smtp-password-or-api-key',
        'timeout' => 5,
    ],
    'log_path' => dirname(__DIR__) . '/data/mail.log',
];
