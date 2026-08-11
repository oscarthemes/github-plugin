<?php
namespace GH_Updater;

if ( ! defined( 'ABSPATH' ) ) exit;

class Settings {
    const OPTION_TOKEN = 'gh_updater_token_enc';
    const OPTION_SECRET = 'gh_updater_webhook_secret';

    public static function get_secret_key() {
        if ( defined( 'GH_UPDATER_SECRET_KEY' ) && GH_UPDATER_SECRET_KEY ) {
            return GH_UPDATER_SECRET_KEY;
        }
        // fallback to WP config constant if available
        if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
            return substr( AUTH_KEY, 0, 32 );
        }
        return false;
    }

    public static function encrypt_token( $token ) {
        $key = self::get_secret_key();
        if ( ! $key ) {
            // no key — store plain token (not recommended)
            update_option( 'gh_updater_token', $token );
            return true;
        }
        $ivlen = openssl_cipher_iv_length('AES-256-CBC');
        $iv = openssl_random_pseudo_bytes($ivlen);
        $ciphertext_raw = openssl_encrypt( $token, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        $hmac = hash_hmac( 'sha256', $ciphertext_raw, $key, true );
        $encoded = base64_encode( $iv . $hmac . $ciphertext_raw );
        update_option( self::OPTION_TOKEN, $encoded );
        return true;
    }

    public static function decrypt_token() {
        $key = self::get_secret_key();
        if ( ! $key ) {
            // fallback to plain option
            return get_option( 'gh_updater_token', '' );
        }
        $encoded = get_option( self::OPTION_TOKEN, '' );
        if ( empty( $encoded ) ) return '';
        $c = base64_decode( $encoded );
        $ivlen = openssl_cipher_iv_length('AES-256-CBC');
        $iv = substr( $c, 0, $ivlen );
        $hmac = substr( $c, $ivlen, 32 );
        $ciphertext_raw = substr( $c, $ivlen + 32 );
        $calcmac = hash_hmac( 'sha256', $ciphertext_raw, $key, true );
        if ( ! hash_equals( $hmac, $calcmac ) ) {
            return '';
        }
        $plain = openssl_decrypt( $ciphertext_raw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        return $plain;
    }

    public static function set_webhook_secret( $secret ) {
        update_option( self::OPTION_SECRET, $secret );
    }

    public static function get_webhook_secret() {
        return get_option( self::OPTION_SECRET, '' );
    }
}
