<?php
// Settings REST endpoints and enhanced routes
namespace GH_Updater;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once GH_UPDATER_PLUGIN_DIR . 'includes/class-settings.php';
require_once GH_UPDATER_PLUGIN_DIR . 'includes/class-webhook-manager.php';
require_once GH_UPDATER_PLUGIN_DIR . 'includes/class-event-processor.php';

// Extend existing Rest_Routes class with settings handlers by hooking into rest_api_init
add_action( 'rest_api_init', function() {
    register_rest_route( 'gh-updater/v1', '/settings', [
        [ 'methods' => 'GET', 'callback' => function() {
            if ( ! current_user_can( 'manage_options' ) ) return new \WP_Error( 'forbidden', 'Forbidden', [ 'status' => 403 ] );
            $token = Settings::decrypt_token();
            return rest_ensure_response( [ 'has_token' => ! empty( $token ), 'webhook_secret' => Settings::get_webhook_secret() ? true : false ] );
        }, 'permission_callback' => function() { return current_user_can( 'manage_options' ); } ],
        [ 'methods' => 'POST', 'callback' => function( $req ) {
            if ( ! current_user_can( 'manage_options' ) ) return new \WP_Error( 'forbidden', 'Forbidden', [ 'status' => 403 ] );
            $params = $req->get_params();
            if ( isset( $params['token'] ) ) {
                Settings::encrypt_token( $params['token'] );
            }
            if ( isset( $params['webhook_secret'] ) ) {
                Settings::set_webhook_secret( $params['webhook_secret'] );
            }
            return rest_ensure_response( [ 'ok' => true ] );
        }, 'permission_callback' => function() { return current_user_can( 'manage_options' ); } ]
    ] );

    register_rest_route( 'gh-updater/v1', '/hooks/create', [
        'methods' => 'POST',
        'callback' => function( $req ) {
            if ( ! current_user_can( 'manage_options' ) ) return new \WP_Error( 'forbidden', 'Forbidden', [ 'status' => 403 ] );
            $body = $req->get_json_params();
            if ( empty( $body['owner'] ) || empty( $body['repo'] ) ) return new \WP_Error( 'missing_params', 'Missing owner or repo', [ 'status' => 400 ] );
            $token = Settings::decrypt_token();
            if ( empty( $token ) ) return new \WP_Error( 'no_token', 'No GitHub token configured', [ 'status' => 403 ] );
            $wm = new Webhook_Manager( $token );
            $callback = get_rest_url( null, '/gh-updater/v1/webhook' );
            $secret = Settings::get_webhook_secret();
            if ( empty( $secret ) ) {
                $secret = wp_generate_password( 24, false, false );
                Settings::set_webhook_secret( $secret );
            }
            $resp = $wm->create_repo_webhook( $body['owner'], $body['repo'], $callback, $secret );
            return rest_ensure_response( [ 'status' => $resp->status, 'body' => $resp->body ] );
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); }
    ] );

    register_rest_route( 'gh-updater/v1', '/activity', [
        'methods' => 'GET',
        'callback' => function( $req ) {
            if ( ! current_user_can( 'manage_options' ) ) return new \WP_Error( 'forbidden', 'Forbidden', [ 'status' => 403 ] );
            global $wpdb;
            $table = $wpdb->prefix . 'github_updater_activity';
            $limit = intval( $req->get_param( 'per_page' ) ? $req->get_param( 'per_page' ) : 50 );
            $page = max(1, intval( $req->get_param( 'page' ) ? $req->get_param( 'page' ) : 1 ));
            $offset = ( $page - 1 ) * $limit;
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY created_at DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
            return rest_ensure_response( $rows );
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); }
    ] );
});
