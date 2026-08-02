<?php
/**
 * Plugin Name: GitHub Updater
 * Plugin URI:  https://github.com/oscarthemes/github-plugin
 * Description: Monitor GitHub repos/branches/commits in near real time. Modular provider architecture and webhook support. Prototype scaffold.
 * Version:     0.1.0
 * Author:      OscarThemes
 * Text Domain: github-updater
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GH_UPDATER_VERSION', '0.1.0' );
define( 'GH_UPDATER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GH_UPDATER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Includes
require_once GH_UPDATER_PLUGIN_DIR . 'includes/class-provider-interface.php';
require_once GH_UPDATER_PLUGIN_DIR . 'includes/class-github-client.php';
require_once GH_UPDATER_PLUGIN_DIR . 'includes/class-webhook-handler.php';
require_once GH_UPDATER_PLUGIN_DIR . 'includes/rest-routes.php';

register_activation_hook( __FILE__, function() {
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $table1 = $wpdb->prefix . 'github_updater_monitored';
    $sql = "CREATE TABLE $table1 (
      id bigint unsigned NOT NULL AUTO_INCREMENT,
      user_id bigint unsigned NULL,
      repo_full_name varchar(255) NOT NULL,
      provider varchar(50) NOT NULL DEFAULT 'github',
      watched tinyint(1) NOT NULL DEFAULT 1,
      branches longtext NULL,
      last_checked datetime NULL,
      created_at datetime DEFAULT CURRENT_TIMESTAMP,
      updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY repo_full_name (repo_full_name)
    ) $charset_collate;";
    dbDelta( $sql );

    $table2 = $wpdb->prefix . 'github_updater_events';
    $sql2 = "CREATE TABLE $table2 (
      id bigint unsigned NOT NULL AUTO_INCREMENT,
      monitored_id bigint unsigned NOT NULL,
      event_type varchar(50) NOT NULL,
      event_payload longtext NOT NULL,
      processed tinyint(1) DEFAULT 0,
      created_at datetime DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY monitored_id (monitored_id),
      KEY event_type (event_type)
    ) $charset_collate;";
    dbDelta( $sql2 );
});

add_action( 'rest_api_init', function() {
    \GH_Updater\Rest_Routes::register_routes();
});

add_action( 'admin_menu', function() {
    add_menu_page( 'GitHub Updater', 'GitHub Updater', 'manage_options', 'github-updater', function(){
        echo '<div class="wrap"><h1>GitHub Updater</h1><div id="gh-updater-app">Loading…</div></div>';
    }, 'dashicons-admin-generic' );
});

add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( $hook !== 'toplevel_page_github-updater' ) return;

    wp_enqueue_style( 'gh-updater-admin', GH_UPDATER_PLUGIN_URL . 'assets/css/admin.css', [], GH_UPDATER_VERSION );
    wp_enqueue_script( 'gh-updater-admin', GH_UPDATER_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], GH_UPDATER_VERSION, true );
    wp_localize_script( 'gh-updater-admin', 'GH_UPDATER', [
        'restBase' => esc_url_raw( rest_url( 'gh-updater/v1' ) ),
        'nonce'    => wp_create_nonce( 'wp_rest' ),
    ] );
});
