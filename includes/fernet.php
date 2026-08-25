<?php
/**
 * #136 (logbook): implementazione minima di Fernet (https://github.com/fernet/spec),
 * compatibile con la libreria Python `cryptography.fernet.Fernet` già usata dal
 * bot (crypto.py) per email_password_enc. Serve SOLO a leggere/scrivere quella
 * colonna dal gestionale — nessun'altra funzionalità.
 *
 * Formato token: version(1B=0x80) || timestamp(8B big-endian) || IV(16B) ||
 * AES-128-CBC(plaintext, PKCS7) || HMAC-SHA256(tutto il resto). Tutto in
 * base64 url-safe con padding. Chiave: 32 byte url-safe base64 → primi 16
 * byte = chiave di firma (HMAC), ultimi 16 = chiave di cifratura (AES).
 */

if (!function_exists('fernetEncrypt')) {

function _fernetB64UrlEncode(string $data): string {
    return strtr(base64_encode($data), '+/', '-_');
}

function _fernetB64UrlDecode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

function fernetEncrypt(string $plaintext, string $keyB64): string {
    $keyRaw = _fernetB64UrlDecode($keyB64);
    if (strlen($keyRaw) !== 32) throw new RuntimeException('Chiave Fernet non valida (attesi 32 byte)');
    $signingKey = substr($keyRaw, 0, 16);
    $encKey     = substr($keyRaw, 16, 16);
    $iv = random_bytes(16);
    $ciphertext = openssl_encrypt($plaintext, 'aes-128-cbc', $encKey, OPENSSL_RAW_DATA, $iv);
    if ($ciphertext === false) throw new RuntimeException('Cifratura AES fallita');
    $body = "\x80" . pack('J', time()) . $iv . $ciphertext;
    $hmac = hash_hmac('sha256', $body, $signingKey, true);
    return _fernetB64UrlEncode($body . $hmac);
}

function fernetDecrypt(string $token, string $keyB64): string {
    $keyRaw = _fernetB64UrlDecode($keyB64);
    if (strlen($keyRaw) !== 32) throw new RuntimeException('Chiave Fernet non valida (attesi 32 byte)');
    $signingKey = substr($keyRaw, 0, 16);
    $encKey     = substr($keyRaw, 16, 16);
    $raw = _fernetB64UrlDecode($token);
    if (strlen($raw) < 1 + 8 + 16 + 32) throw new RuntimeException('Token Fernet troppo corto');
    $hmacGot = substr($raw, -32);
    $body    = substr($raw, 0, -32);
    $hmacCalc = hash_hmac('sha256', $body, $signingKey, true);
    if (!hash_equals($hmacCalc, $hmacGot)) throw new RuntimeException('Firma Fernet non valida (chiave sbagliata o token alterato)');
    $version = ord($body[0]);
    if ($version !== 0x80) throw new RuntimeException('Versione Fernet non supportata');
    $iv = substr($body, 9, 16);
    $ciphertext = substr($body, 25);
    $plaintext = openssl_decrypt($ciphertext, 'aes-128-cbc', $encKey, OPENSSL_RAW_DATA, $iv);
    if ($plaintext === false) throw new RuntimeException('Decifratura AES fallita');
    return $plaintext;
}

}
