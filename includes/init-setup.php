<?php
namespace GH_Updater;

if ( ! defined( 'ABSPATH' ) ) exit;

// Ensure activity table exists on init (upgrade path)
add_action( 'init', function() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table = $wpdb->prefix . 'github_updater_activity';
    $sql = "CREATE TABLE IF NOT EXISTS $table (
      id bigint unsigned NOT NULL AUTO_INCREMENT,
      event_type varchar(50) NOT NULL,
      repo_full_name varchar(255) DEFAULT NULL,
      actor varchar(255) DEFAULT NULL,
      meta longtext NULL,
      payload longtext NOT NULL,
      created_at datetime DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY repo_full_name (repo_full_name),
      KEY event_type (event_type)
    ) $charset_collate;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    // Register processing hooks
    Event_Processor::register_hooks();

    // Ensure poll cron schedule exists (every 5 minutes)
    if ( ! wp_next_scheduled( 'gh_updater_poll' ) ) {
        if ( ! wp_get_schedules() || ! isset( wp_get_schedules()['five_minutes'] ) ) {
            add_filter( 'cron_schedules', function( $schedules ) {
                $schedules['five_minutes'] = [ 'interval' => 300, 'display' => 'Every 5 Minutes' ];
                return $schedules;
            } );
        }
        wp_schedule_event( time(), 'five_minutes', 'gh_updater_poll' );
    }
} );

// Process events single-run endpoint (for testing)
add_action( 'rest_api_init', function() {
    register_rest_route( 'gh-updater/v1', '/process-events', [
        'methods' => 'POST',
        'callback' => function() {
            if ( ! current_user_can( 'manage_options' ) ) return new \WP_Error( 'forbidden', 'Forbidden', [ 'status' => 403 ] );
            do_action( 'gh_updater_process_events' );
            return rest_ensure_response( [ 'status' => 'queued' ] );
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); }
    ]);
});
