<?php
namespace GH_Updater;

if ( ! defined( 'ABSPATH' ) ) exit;

class Rest_Routes {
    public static function register_routes() {
        register_rest_route( 'gh-updater/v1', '/webhook', [
            'methods'             => 'POST',
            'callback'            => [ __NAMESPACE__ . '\\Webhook_Handler', 'handle' ],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route( 'gh-updater/v1', '/repos', [
            'methods'             => 'GET',
            'callback'            => [ __NAMESPACE__ . '\\Rest_Routes', 'list_repos' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            }
        ]);

        register_rest_route( 'gh-updater/v1', '/repos/(?P<owner>[^/]+)/(?P<repo>[^/]+)/branches', [
            'methods' => 'GET',
            'callback' => [ __NAMESPACE__ . '\\Rest_Routes', 'list_branches' ],
            'permission_callback' => function() { return current_user_can( 'manage_options' ); }
        ]);

        register_rest_route( 'gh-updater/v1', '/repos/(?P<owner>[^/]+)/(?P<repo>[^/]+)/compare', [
            'methods' => 'GET',
            'callback' => [ __NAMESPACE__ . '\\Rest_Routes', 'compare' ],
            'permission_callback' => function() { return current_user_can( 'manage_options' ); }
        ]);
    }

    public static function list_repos( $request ) {
        $token = get_option( 'gh_updater_token' );
        if ( ! $token ) {
            return new \WP_Error( 'no_token', 'No GitHub token configured', [ 'status' => 403 ] );
        }
        $client = new GitHub_Client( $token );
        $resp = $client->rest( 'GET', '/user/repos', [ 'per_page' => 100 ] );
        if ( $resp->status >= 400 ) {
            return new \WP_Error( 'github_error', 'GitHub API error', [ 'status' => $resp->status, 'data' => $resp->body ] );
        }
        return rest_ensure_response( $resp->body );
    }

    public static function list_branches( $request ) {
        $token = get_option( 'gh_updater_token' );
        if ( ! $token ) {
            return new \WP_Error( 'no_token', 'No GitHub token configured', [ 'status' => 403 ] );
        }
        $owner = $request->get_param( 'owner' );
        $repo = $request->get_param( 'repo' );
        $client = new GitHub_Client( $token );
        $resp = $client->rest( 'GET', "/repos/{$owner}/{$repo}/branches", [ 'per_page' => 100 ] );
        if ( $resp->status >= 400 ) {
            return new \WP_Error( 'github_error', 'GitHub API error', [ 'status' => $resp->status, 'data' => $resp->body ] );
        }
        return rest_ensure_response( $resp->body );
    }

    public static function compare( $request ) {
        $token = get_option( 'gh_updater_token' );
        if ( ! $token ) {
            return new \WP_Error( 'no_token', 'No GitHub token configured', [ 'status' => 403 ] );
        }
        $owner = $request->get_param( 'owner' );
        $repo = $request->get_param( 'repo' );
        $base = $request->get_param( 'base' );
        $head = $request->get_param( 'head' );
        if ( empty( $base ) || empty( $head ) ) {
            return new \WP_Error( 'missing_params', 'Missing base or head param', [ 'status' => 400 ] );
        }
        $client = new GitHub_Client( $token );
        $resp = $client->rest( 'GET', "/repos/{$owner}/{$repo}/compare/" . rawurlencode( $base ) . "..." . rawurlencode( $head ) );
        if ( $resp->status >= 400 ) {
            return new \WP_Error( 'github_error', 'GitHub API error', [ 'status' => $resp->status, 'data' => $resp->body ] );
        }
        return rest_ensure_response( $resp->body );
    }
}
