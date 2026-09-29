<?php

declare(strict_types=1);

namespace McpServer\Auth;

use InvalidArgumentException;
use RuntimeException;

/**
 * Pure PHP 8.4 WebAuthn (FIDO2 / Passkey) engine with zero external dependencies.
 *
 * Implements the W3C WebAuthn Level 3 specification:
 * - Attestation verification for credential registration (ES256, RS256).
 * - Assertion verification for user authentication / passkey login.
 * - Minimal built-in CBOR decoder and COSE-to-PEM public key transformer.
 */
final class WebAuthn {
    /**
     * Generate publicKeyCredentialCreationOptions for registration.
     *
     * @param string[] $excludeCredentialIds Base64URL-encoded credential IDs already registered
     * @return array<string, mixed>
     */
    public static function createRegistrationOptions(
        string $username,
        string $displayName,
        string $rpId,
        string $rpName,
        string $challenge,
        array $excludeCredentialIds = []
    ): array {
        $exclude = array_map(static fn(string $id): array => [
            'id' => $id,
            'type' => 'public-key',
            'transports' => ['internal', 'usb', 'nfc', 'ble', 'hybrid'],
        ], $excludeCredentialIds);

        return [
            'challenge' => self::base64UrlEncode($challenge),
            'rp' => [
                'name' => $rpName,
                'id' => $rpId,
            ],
            'user' => [
                'id' => self::base64UrlEncode($username),
                'name' => $username,
                'displayName' => $displayName !== '' ? $displayName : $username,
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],   // ES256
                ['type' => 'public-key', 'alg' => -257], // RS256
            ],
            'authenticatorSelection' => [
                'residentKey' => 'preferred',
                'requireResidentKey' => false,
                'userVerification' => 'preferred',
            ],
            'timeout' => 60000,
            'attestation' => 'none',
            'excludeCredentials' => $exclude,
        ];
    }

    /**
     * Generate publicKeyCredentialRequestOptions for authentication.
     *
     * @param string[] $allowCredentialIds Base64URL-encoded credential IDs (empty for resident/discoverable keys)
     * @return array<string, mixed>
     */
    public static function createAuthenticationOptions(
        string $rpId,
        string $challenge,
        array $allowCredentialIds = []
    ): array {
        $allow = array_map(static fn(string $id): array => [
            'id' => $id,
            'type' => 'public-key',
            'transports' => ['internal', 'usb', 'nfc', 'ble', 'hybrid'],
        ], $allowCredentialIds);

        $options = [
            'challenge' => self::base64UrlEncode($challenge),
            'rpId' => $rpId,
            'timeout' => 60000,
            'userVerification' => 'preferred',
        ];

        if ($allow !== []) {
            $options['allowCredentials'] = $allow;
        }

        return $options;
    }

    /**
     * Verify WebAuthn registration attestation response and extract public key in PEM format.
     *
     * @return array{credential_id: string, public_key_pem: string, aaguid: string, sign_count: int}
     */
    public static function verifyRegistration(
        string $clientDataJson,
        string $attestationObjectBase64,
        string $expectedChallenge,
        string $expectedOrigin,
        string $expectedRpId
    ): array {
        // 1. Verify clientDataJSON (support both raw JSON and base64url-encoded payload)
        if (!str_starts_with(ltrim($clientDataJson), '{')) {
            $clientDataJson = self::base64UrlDecode($clientDataJson);
        }
        $clientData = json_decode($clientDataJson, true);
        if (!is_array($clientData)) {
            throw new InvalidArgumentException('Malformed clientDataJSON');
        }
        if (($clientData['type'] ?? '') !== 'webauthn.create') {
            throw new InvalidArgumentException('Invalid clientData type: expected webauthn.create');
        }
        if (!hash_equals($expectedChallenge, self::base64UrlDecode((string) ($clientData['challenge'] ?? '')))) {
            throw new InvalidArgumentException('Registration challenge mismatch');
        }
        $origin = rtrim((string) ($clientData['origin'] ?? ''), '/');
        $expected = rtrim($expectedOrigin, '/');
        if ($origin !== $expected) {
            $isLoopback = str_contains($expected, '://localhost') || str_contains($expected, '://127.0.0.1');
            $normOrigin = str_replace('://127.0.0.1', '://localhost', $origin);
            $normExpected = str_replace('://127.0.0.1', '://localhost', $expected);
            if (!$isLoopback || $normOrigin !== $normExpected) {
                throw new InvalidArgumentException("Registration origin mismatch: expected {$expectedOrigin}, got {$origin}");
            }
        }

        // 2. Decode attestationObject (CBOR)
        $attestationBytes = self::base64UrlDecode($attestationObjectBase64);
        $attestation = self::cborDecode($attestationBytes);
        if (!is_array($attestation) || !isset($attestation['authData'])) {
            throw new InvalidArgumentException('Invalid attestationObject CBOR');
        }

        $authData = (string) $attestation['authData'];
        $authDataLen = strlen($authData);
        if ($authDataLen < 37) {
            throw new InvalidArgumentException('authData is too short');
        }

        // 3. Verify rpIdHash
        $rpIdHash = substr($authData, 0, 32);
        if (!hash_equals(hash('sha256', $expectedRpId, true), $rpIdHash)) {
            throw new InvalidArgumentException('rpIdHash mismatch in registration');
        }

        // 4. Verify flags: User Present (bit 0) and Attested Credential Data Present (bit 6)
        $flags = ord($authData[32]);
        $up = ($flags & 0x01) !== 0;
        $at = ($flags & 0x40) !== 0;
        if (!$up) {
            throw new InvalidArgumentException('User Present (UP) flag was not set');
        }
        if (!$at) {
            throw new InvalidArgumentException('Attested Credential Data (AT) flag was not set');
        }

        $signCount = unpack('N', substr($authData, 33, 4))[1];

        // 5. Parse Attested Credential Data
        // bytes 37..52: AAGUID (16 bytes)
        $aaguid = bin2hex(substr($authData, 37, 16));

        // bytes 53..54: Credential ID length L (uint16)
        $credIdLen = unpack('n', substr($authData, 53, 2))[1];
        if ($authDataLen < 55 + $credIdLen) {
            throw new InvalidArgumentException('authData truncated at credential ID');
        }

        // bytes 55..55+L: Credential ID
        $credentialIdRaw = substr($authData, 55, $credIdLen);
        $credentialId = self::base64UrlEncode($credentialIdRaw);

        // bytes 55+L..: Credential Public Key in COSE format
        $coseBytes = substr($authData, 55 + $credIdLen);
        $coseKey = self::cborDecode($coseBytes);
        if (!is_array($coseKey)) {
            throw new InvalidArgumentException('Failed to decode COSE public key');
        }

        $pem = self::coseToPem($coseKey);

        return [
            'credential_id' => $credentialId,
            'public_key_pem' => $pem,
            'aaguid' => $aaguid,
            'sign_count' => (int) $signCount,
        ];
    }

    /**
     * Verify WebAuthn authentication assertion response.
     *
     * @return int New sign count to persist
     */
    public static function verifyAuthentication(
        string $clientDataJson,
        string $authenticatorDataBase64,
        string $signatureBase64,
        string $publicKeyPem,
        int $storedSignCount,
        string $expectedChallenge,
        string $expectedOrigin,
        string $expectedRpId
    ): int {
        // 1. Verify clientDataJSON (support both raw JSON and base64url-encoded payload)
        if (!str_starts_with(ltrim($clientDataJson), '{')) {
            $clientDataJson = self::base64UrlDecode($clientDataJson);
        }
        $clientData = json_decode($clientDataJson, true);
        if (!is_array($clientData)) {
            throw new InvalidArgumentException('Malformed clientDataJSON');
        }
        if (($clientData['type'] ?? '') !== 'webauthn.get') {
            throw new InvalidArgumentException('Invalid clientData type: expected webauthn.get');
        }
        if (!hash_equals($expectedChallenge, self::base64UrlDecode((string) ($clientData['challenge'] ?? '')))) {
            throw new InvalidArgumentException('Authentication challenge mismatch');
        }
        $origin = rtrim((string) ($clientData['origin'] ?? ''), '/');
        $expected = rtrim($expectedOrigin, '/');
        if ($origin !== $expected) {
            $isLoopback = str_contains($expected, '://localhost') || str_contains($expected, '://127.0.0.1');
            $normOrigin = str_replace('://127.0.0.1', '://localhost', $origin);
            $normExpected = str_replace('://127.0.0.1', '://localhost', $expected);
            if (!$isLoopback || $normOrigin !== $normExpected) {
                throw new InvalidArgumentException("Authentication origin mismatch: expected {$expectedOrigin}, got {$origin}");
            }
        }

        // 2. Parse authenticatorData
        $authData = self::base64UrlDecode($authenticatorDataBase64);
        if (strlen($authData) < 37) {
            throw new InvalidArgumentException('authenticatorData is too short');
        }

        $rpIdHash = substr($authData, 0, 32);
        if (!hash_equals(hash('sha256', $expectedRpId, true), $rpIdHash)) {
            throw new InvalidArgumentException('rpIdHash mismatch in authentication');
        }

        $flags = ord($authData[32]);
        $up = ($flags & 0x01) !== 0;
        if (!$up) {
            throw new InvalidArgumentException('User Present (UP) flag was not set');
        }

        $newSignCount = unpack('N', substr($authData, 33, 4))[1];
        // Per W3C WebAuthn Level 3 (§7.2, Step 21):
        // Authenticators without monotonic counters (synced passkeys / multi-device credentials,
        // Apple iCloud Keychain, Google Password Manager, Windows Hello software keys) always report 0.
        // Only evaluate cloned authenticator detection if the counter actively advances or was positive.
        if ($storedSignCount > 0 && $newSignCount > 0 && $newSignCount <= $storedSignCount) {
            throw new RuntimeException('Cloned authenticator detected: signature counter did not advance');
        }

        // 3. Verify signature
        $signature = self::base64UrlDecode($signatureBase64);
        // If signature is raw 64-byte r || s (unwrapped ECDSA), convert to ASN.1 DER sequence for OpenSSL
        if (strlen($signature) === 64 && !str_starts_with($signature, "\x30")) {
            $encodeDerInt = static function(string $bytes): string {
                $bytes = ltrim($bytes, "\x00");
                if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
                    $bytes = "\x00" . $bytes;
                }
                return "\x02" . chr(strlen($bytes)) . $bytes;
            };
            $seq = $encodeDerInt(substr($signature, 0, 32)) . $encodeDerInt(substr($signature, 32, 32));
            $signature = "\x30" . chr(strlen($seq)) . $seq;
        }

        $signedData = $authData . hash('sha256', $clientDataJson, true);

        $ok = openssl_verify($signedData, $signature, $publicKeyPem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new InvalidArgumentException('WebAuthn signature verification failed');
        }

        return max((int) $newSignCount, $storedSignCount);
    }

    /**
     * Convert a COSE Key map to standard OpenSSL PEM format (supports ES256 and RS256).
     *
     * @param array<int|string, mixed> $cose
     */
    public static function coseToPem(array $cose): string {
        $kty = (int) ($cose[1] ?? 0);
        $alg = (int) ($cose[3] ?? 0);

        // ES256: kty = 2 (EC2), crv = 1 (P-256), alg = -7
        if ($kty === 2 && $alg === -7) {
            $crv = (int) ($cose[-1] ?? 1);
            if ($crv !== 1) {
                throw new InvalidArgumentException("Unsupported EC curve crv={$crv}, expected P-256 (1)");
            }
            $x = (string) ($cose[-2] ?? '');
            $y = (string) ($cose[-3] ?? '');
            if (strlen($x) !== 32 || strlen($y) !== 32) {
                throw new InvalidArgumentException('Invalid EC P-256 coordinates in COSE key');
            }

            // Uncompressed EC point: 0x04 || X || Y (65 bytes)
            $point = "\x04" . $x . $y;
            // Standard SubjectPublicKeyInfo DER header for id-ecPublicKey with prime256v1:
            // 30 59 30 13 06 07 2a 86 48 ce 3d 02 01 06 08 2a 86 48 ce 3d 03 01 07 03 42 00
            $derHeader = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
            $der = $derHeader . $point;
            return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        }

        // RS256: kty = 3 (RSA), alg = -257
        if ($kty === 3 && $alg === -257) {
            $n = (string) ($cose[-1] ?? ''); // modulus
            $e = (string) ($cose[-2] ?? ''); // exponent
            if ($n === '' || $e === '') {
                throw new InvalidArgumentException('Invalid RSA parameters in COSE key');
            }

            $encodeInt = static function(string $bytes): string {
                // If highest bit is 1, prepend 0x00 to mark positive integer in ASN.1
                if ((ord($bytes[0]) & 0x80) !== 0) {
                    $bytes = "\x00" . $bytes;
                }
                $len = strlen($bytes);
                if ($len < 128) {
                    $lenBytes = chr($len);
                } else {
                    $packed = ltrim(pack('N', $len), "\x00");
                    $lenBytes = chr(0x80 | strlen($packed)) . $packed;
                }
                return "\x02" . $lenBytes . $bytes;
            };

            $rsaSeq = $encodeInt($n) . $encodeInt($e);
            $rsaLen = strlen($rsaSeq);
            $rsaLenBytes = $rsaLen < 128 ? chr($rsaLen) : chr(0x80 | strlen(ltrim(pack('N', $rsaLen), "\x00"))) . ltrim(pack('N', $rsaLen), "\x00");
            $rsaDer = "\x30" . $rsaLenBytes . $rsaSeq;

            // Wrap in SubjectPublicKeyInfo
            // AlgorithmIdentifier: rsaEncryption (1.2.840.113549.1.1.1) NULL
            $algId = hex2bin('300d06092a864886f70d0101010500');
            $bitStringLen = strlen($rsaDer) + 1;
            $bitLenBytes = $bitStringLen < 128 ? chr($bitStringLen) : chr(0x80 | strlen(ltrim(pack('N', $bitStringLen), "\x00"))) . ltrim(pack('N', $bitStringLen), "\x00");
            $bitString = "\x03" . $bitLenBytes . "\x00" . $rsaDer;

            $total = $algId . $bitString;
            $totLen = strlen($total);
            $totLenBytes = $totLen < 128 ? chr($totLen) : chr(0x80 | strlen(ltrim(pack('N', $totLen), "\x00"))) . ltrim(pack('N', $totLen), "\x00");
            $der = "\x30" . $totLenBytes . $total;

            return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        }

        throw new InvalidArgumentException("Unsupported COSE algorithm kty={$kty}, alg={$alg}");
    }

    /** Minimal recursive CBOR decoder */
    public static function cborDecode(string $data): mixed {
        $offset = 0;
        return self::decodeCborItem($data, $offset, strlen($data), 0);
    }

    private static function decodeCborItem(string $data, int &$offset, int $length, int $depth = 0): mixed {
        if ($depth > 16) {
            throw new InvalidArgumentException('CBOR nesting exceeds maximum depth of 16');
        }
        if ($offset >= $length) {
            throw new InvalidArgumentException("Unexpected end of CBOR stream at offset {$offset}");
        }

        $init = ord($data[$offset++]);
        $major = $init >> 5;
        $info = $init & 0x1f;

        $val = self::readCborLengthOrVal($data, $offset, $length, $info);

        return match ($major) {
            0 => $val, // unsigned int
            1 => -1 - $val, // negative int
            2 => self::readCborRaw($data, $offset, $length, $val), // byte string
            3 => self::readCborRaw($data, $offset, $length, $val), // text string
            4 => self::readCborArray($data, $offset, $length, $val, $depth + 1), // array
            5 => self::readCborMap($data, $offset, $length, $val, $depth + 1), // map
            6 => self::decodeCborItem($data, $offset, $length, $depth + 1), // tag (return tagged value)
            7 => match ($info) {
                20 => false,
                21 => true,
                22 => null,
                default => null,
            },
            default => throw new InvalidArgumentException("Unsupported CBOR major type {$major}"),
        };
    }

    private static function readCborLengthOrVal(string $data, int &$offset, int $length, int $info): int {
        if ($info < 24) {
            return $info;
        }
        return match ($info) {
            24 => ord(self::readCborRaw($data, $offset, $length, 1)),
            25 => unpack('n', self::readCborRaw($data, $offset, $length, 2))[1],
            26 => unpack('N', self::readCborRaw($data, $offset, $length, 4))[1],
            27 => (int) unpack('J', self::readCborRaw($data, $offset, $length, 8))[1],
            default => throw new InvalidArgumentException("Unsupported CBOR length info {$info}"),
        };
    }

    private static function readCborRaw(string $data, int &$offset, int $length, int $len): string {
        if ($offset + $len > $length) {
            throw new InvalidArgumentException("CBOR buffer underflow");
        }
        $sub = substr($data, $offset, $len);
        $offset += $len;
        return $sub;
    }

    /** @return mixed[] */
    private static function readCborArray(string $data, int &$offset, int $length, int $count, int $depth): array {
        if ($count > ($length - $offset)) {
            throw new InvalidArgumentException("CBOR item count {$count} exceeds remaining buffer");
        }
        $arr = [];
        for ($i = 0; $i < $count; $i++) {
            $arr[] = self::decodeCborItem($data, $offset, $length, $depth);
        }
        return $arr;
    }

    /** @return array<int|string, mixed> */
    private static function readCborMap(string $data, int &$offset, int $length, int $count, int $depth): array {
        if ($count > ($length - $offset)) {
            throw new InvalidArgumentException("CBOR item count {$count} exceeds remaining buffer");
        }
        $map = [];
        for ($i = 0; $i < $count; $i++) {
            $key = self::decodeCborItem($data, $offset, $length, $depth);
            $val = self::decodeCborItem($data, $offset, $length, $depth);
            $map[$key] = $val;
        }
        return $map;
    }

    public static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string {
        $pad = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }
}
