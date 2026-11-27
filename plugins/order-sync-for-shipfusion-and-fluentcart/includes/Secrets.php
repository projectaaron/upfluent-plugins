<?php

namespace UpfluentSf;

defined('ABSPATH') || exit;

/**
 * Encrypts the ShipFusion API token at rest: AES-256-GCM, a random 12-byte IV
 * per value, key derived from the site's auth salt. Stored as
 * "g1:" . base64(iv . tag . ciphertext). A value that no longer decrypts
 * (the salts were changed) reads as empty, so the screen asks for the token
 * again instead of sending garbage to ShipFusion.
 */
class Secrets
{
    const PREFIX = 'g1:';
    const CIPHER = 'aes-256-gcm';

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        if (!self::available()) {
            // Without OpenSSL there is no safe way to store it; the screen says so.
            return '';
        }
        $iv  = random_bytes(12);
        $tag = '';
        $out = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, 'upfluent-sf', 16);
        if ($out === false) {
            return '';
        }
        return self::PREFIX . base64_encode($iv . $tag . $out);
    }

    public static function decrypt(string $stored): string
    {
        if (strpos($stored, self::PREFIX) !== 0 || !self::available()) {
            return '';
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= 28) {
            return '';
        }
        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'upfluent-sf');
        return is_string($plain) ? $plain : '';
    }

    public static function available(): bool
    {
        return function_exists('openssl_encrypt') && in_array(self::CIPHER, openssl_get_cipher_methods(), true);
    }

    private static function key(): string
    {
        return hash('sha256', wp_salt('auth') . 'upfluent-sf', true);
    }
}
