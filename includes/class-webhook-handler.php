<?php
namespace GH_Updater;

if ( ! defined( 'ABSPATH' ) ) exit;

class Webhook_Handler {
    public static function verify_signature( $payload, $signature, $secret ) {
        if ( empty( $secret ) ) {
            return false;
        }
        $hash = 'sha256=' . hash_hmac( 'sha256', $payload, $secret );
        return hash_equals( $hash, $signature );
    }

    public static function handle() {
        $payload = file_get_contents('php://input');
        $sig = isset( $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ) ? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : '';
        $event = isset( $_SERVER['HTTP_X_GITHUB_EVENT'] ) ? $_SERVER['HTTP_X_GITHUB_EVENT'] : '';

        $secret = get_option( 'gh_updater_webhook_secret', '' );

        if ( ! self::verify_signature( $payload, $sig, $secret ) ) {
            status_header( 401 );
            echo 'Invalid signature';
            exit;
        }

        $data = json_decode( $payload, true );

        global $wpdb;
        $table = $wpdb->prefix . 'github_updater_events';
        $wpdb->insert( $table, [
            'monitored_id' => 0,
            'event_type'   => $event,
            'event_payload'=> wp_json_encode( $data ),
            'processed'    => 0,
        ] );

        // Schedule background processing (hook can be implemented later)
        if ( ! wp_next_scheduled( 'gh_updater_process_events' ) ) {
            wp_schedule_single_event( time() + 1, 'gh_updater_process_events' );
        }

        status_header( 200 );
        echo 'ok';
        exit;
    }
}
