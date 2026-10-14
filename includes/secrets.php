<?php

/**
 * Encryption of the stored Google secrets (Places API key, OAuth client secret, refresh token).
 * Loaded by the plugin and by uninstall.php, which revokes the Google connection with the token.
 *
 * The values are encrypted with libsodium's secretbox ("hri1:" + base64(nonce + ciphertext)). The key
 * comes from wp-config.php, not from the database, so a database dump or copy does not give them away:
 * HRI_ENCRYPTION_KEY if it is defined (at least 32 characters), otherwise AUTH_KEY + SECURE_AUTH_KEY.
 * Code running on the site can still read them, as it can read wp-config.php too.
 */

if (! defined('ABSPATH')) exit;

const HRI_SECRET_PREFIX = 'hri1:';

/** The 32 byte encryption key, or '' when wp-config.php has no usable source for it */
function hri_secret_key()
{
    if (defined('HRI_ENCRYPTION_KEY') && is_string(HRI_ENCRYPTION_KEY) && strlen(HRI_ENCRYPTION_KEY) >= 32) {
        $base = HRI_ENCRYPTION_KEY;
    } elseif (
        defined('AUTH_KEY') && defined('SECURE_AUTH_KEY') && strlen(AUTH_KEY . SECURE_AUTH_KEY) >= 64
        && strpos(AUTH_KEY . SECURE_AUTH_KEY, 'put your unique phrase here') === false
    ) {
        $base = AUTH_KEY . SECURE_AUTH_KEY;
    } else {
        return '';
    }

    return hash_hmac('sha256', 'headless-reviews-importer', $base, true);
}

/**
 * Encrypt a value for storage.
 *
 * @throws \RuntimeException When there is no encryption key.
 */
function hri_encrypt_secret($plain)
{
    $key = hri_secret_key();
    if ($key === '') {
        throw new \RuntimeException(__('No encryption key: wp-config.php has no AUTH_KEY and SECURE_AUTH_KEY (or HRI_ENCRYPTION_KEY of at least 32 characters).', 'hri-reviews-importer'));
    }

    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return HRI_SECRET_PREFIX . base64_encode($nonce . sodium_crypto_secretbox((string) $plain, $nonce, $key));
}

/**
 * Decrypt a stored value.
 *
 * @return array 'value' (plain text, '' if it failed), 'legacy' (stored unencrypted by 1.5.0 or older,
 *               to be rewritten), 'failed' (cannot be decrypted: other key, or a database from another site).
 */
function hri_decrypt_secret($stored)
{
    $stored = (string) $stored;
    if ($stored === '') {
        return array('value' => '', 'legacy' => false, 'failed' => false);
    }
    if (strpos($stored, HRI_SECRET_PREFIX) !== 0) {
        return array('value' => $stored, 'legacy' => true, 'failed' => false);
    }

    $key   = hri_secret_key();
    $raw   = base64_decode(substr($stored, strlen(HRI_SECRET_PREFIX)), true);
    $plain = false;
    if ($key !== '' && $raw !== false && strlen($raw) >= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key
        );
    }

    return ($plain === false)
        ? array('value' => '', 'legacy' => false, 'failed' => true)
        : array('value' => $plain, 'legacy' => false, 'failed' => false);
}
