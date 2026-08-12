<?php
/**
 * Plugin Name: GitHub Updater (Minimal)
 * Plugin URI:  https://github.com/oscarthemes/github-plugin
 * Description: Minimal, ready-to-use GitHub monitoring plugin using a Personal Access Token and webhooks. Single-file simplified implementation.
 * Version:     0.2.0
 * Author:      OscarThemes
 * Text Domain: github-updater
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'GH_UPDATER_VERSION', '0.2.0' );
define( 'GH_UPDATER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GH_UPDATER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Activation: create tables
register_activation_hook( __FILE__, function() {
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $table_events = $wpdb->prefix . 'github_updater_events';
    $sql1 = "CREATE TABLE IF NOT EXISTS $table_events (
      id bigint unsigned NOT NULL AUTO_INCREMENT,
      event_type varchar(50) NOT NULL,
      repo_full_name varchar(255) DEFAULT NULL,
      payload longtext NOT NULL,
      processed tinyint(1) DEFAULT 0,
      created_at datetime DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY processed (processed)
    ) $charset_collate;";

    $table_activity = $wpdb->prefix . 'github_updater_activity';
    $sql2 = "CREATE TABLE IF NOT EXISTS $table_activity (
      id bigint unsigned NOT NULL AUTO_INCREMENT,
      event_type varchar(50) NOT NULL,
      repo_full_name varchar(255) DEFAULT NULL,
      actor varchar(255) DEFAULT NULL,
      meta longtext NULL,
      payload longtext NOT NULL,
      created_at datetime DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id)
    ) $charset_collate;";

    dbDelta( $sql1 );
    dbDelta( $sql2 );
});

// Simple encryption helpers (optional)
class GHU_Settings {
    const OPT_TOKEN = 'gh_updater_token_enc';
    const OPT_PLAIN = 'gh_updater_token';
    const OPT_SECRET = 'gh_updater_webhook_secret';

    public static function key() {
        if ( defined( 'GH_UPDATER_SECRET_KEY' ) && GH_UPDATER_SECRET_KEY ) return GH_UPDATER_SECRET_KEY;
        if ( defined('AUTH_KEY') && AUTH_KEY ) return substr( AUTH_KEY, 0, 32 );
        return false;
    }

    public static function save_token( $token ) {
        $k = self::key();
        if ( $k && function_exists('openssl_encrypt') ) {
            $ivlen = openssl_cipher_iv_length('AES-256-CBC');
            $iv = openssl_random_pseudo_bytes($ivlen);
            $cipher = openssl_encrypt( $token, 'AES-256-CBC', $k, OPENSSL_RAW_DATA, $iv );
            $hmac = hash_hmac('sha256', $cipher, $k, true);
            $val = base64_encode( $iv . $hmac . $cipher );
            update_option( self::OPT_TOKEN, $val );
            // remove plain if set
            delete_option( self::OPT_PLAIN );
            return true;
        }
        update_option( self::OPT_PLAIN, $token );
        delete_option( self::OPT_TOKEN );
        return true;
    }

    public static function get_token() {
        $k = self::key();
        if ( $k && get_option( self::OPT_TOKEN ) ) {
            $encoded = get_option( self::OPT_TOKEN );
            $c = base64_decode( $encoded );
            $ivlen = openssl_cipher_iv_length('AES-256-CBC');
            $iv = substr( $c, 0, $ivlen );
            $hmac = substr( $c, $ivlen, 32 );
            $cipher = substr( $c, $ivlen + 32 );
            $calc = hash_hmac('sha256', $cipher, $k, true);
            if ( ! hash_equals( $hmac, $calc ) ) return '';
            $plain = openssl_decrypt( $cipher, 'AES-256-CBC', $k, OPENSSL_RAW_DATA, $iv );
            return $plain;
        }
        return get_option( self::OPT_PLAIN, '' );
    }

    public static function set_webhook_secret( $s ) { update_option( self::OPT_SECRET, $s ); }
    public static function get_webhook_secret() { return get_option( self::OPT_SECRET, '' ); }
    public static function remove_token() { delete_option( self::OPT_TOKEN ); delete_option( self::OPT_PLAIN ); }
}

// Lightweight GitHub REST helper
class GHU_Client {
    private $token;
    private $base = 'https://api.github.com';
    public function __construct( $token ) { $this->token = $token; }
    private function headers() {
        $h = [ 'User-Agent: GitHub-Updater/0.2' , 'Accept: application/vnd.github.v3+json' ];
        if ( $this->token ) $h[] = 'Authorization: token ' . $this->token;
        return $h;
    }
    public function request( $method, $path, $data = null ) {
        $url = strpos( $path, 'http' ) === 0 ? $path : $this->base . $path;
        $ch = curl_init();
        $opts = [ CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_HTTPHEADER => $this->headers(), CURLOPT_TIMEOUT => 20 ];
        if ( in_array( strtoupper($method), ['POST','PUT','PATCH'] ) && ! is_null( $data ) ) {
            $opts[CURLOPT_POSTFIELDS] = is_string($data) ? $data : json_encode($data);
            $opts[CURLOPT_HTTPHEADER] = array_merge( $this->headers(), ['Content-Type: application/json'] );
        }
        curl_setopt_array( $ch, $opts );
        $resp = curl_exec( $ch );
        $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        if ( curl_errno($ch) ) { $err = curl_error($ch); curl_close($ch); return (object)['status'=>0,'error'=>$err]; }
        curl_close($ch);
        $body = json_decode( $resp, true );
        return (object)['status'=>$code,'body'=>$body,'raw'=>$resp];
    }
}

// Register REST routes
add_action( 'rest_api_init', function() {
    register_rest_route( 'gh-updater/v1', '/settings', [ 'methods'=>'GET', 'callback'=>function(){ if(!current_user_can('manage_options')) return new WP_Error('forbidden','Forbidden', ['status'=>403]); $t = GHUP_Settings::get_token(); return rest_ensure_response(['has_token'=>!empty($t),'webhook_secret'=>!empty(GHU_Settings::get_webhook_secret())]); } ] );
    register_rest_route( 'gh-updater/v1', '/settings', [ 'methods'=>'POST', 'callback'=>function( $req ){ if(!current_user_can('manage_options')) return new WP_Error('forbidden','Forbidden', ['status'=>403]); $params = $req->get_json_params(); if(isset($params['token'])) GHU_Settings::save_token( $params['token'] ); if(isset($params['webhook_secret'])) GHU_Settings::set_webhook_secret( $params['webhook_secret'] ); return rest_ensure_response(['ok'=>true]); } ] );
    register_rest_route( 'gh-updater/v1', '/settings/test-token', [ 'methods'=>'POST', 'callback'=>function( $req ){ if(!current_user_can('manage_options')) return new WP_Error('forbidden','Forbidden', ['status'=>403]); $params = $req->get_json_params(); $token = isset($params['token']) && $params['token'] ? $params['token'] : GHU_Settings::get_token(); if ( empty($token) ) return new WP_Error('no_token','No token', ['status'=>400]); $c = new GHU_Client($token); $user = $c->request('GET','/user'); $rate = $c->request('GET','/rate_limit'); return rest_ensure_response(['status'=>$user->status,'user'=>$user->body,'rate'=>$rate->body]); } ] );
    register_rest_route( 'gh-updater/v1', '/settings/remove-token', [ 'methods'=>'POST', 'callback'=>function(){ if(!current_user_can('manage_options')) return new WP_Error('forbidden','Forbidden', ['status'=>403]); GHU_Settings::remove_token(); return rest_ensure_response(['ok'=>true]); } ] );

    register_rest_route( 'gh-updater/v1', '/repos', [ 'methods'=>'GET', 'callback'=>function(){ if(!current_user_can('manage_options')) return new WP_Error('forbidden','Forbidden', ['status'=>403]); $token = GHU_Settings::get_token(); if ( empty($token) ) return new WP_Error('no_token','No token configured', ['status'=>403]); $c = new GHU_Client($token); $resp = $c->request('GET','/user/repos?per_page=100'); return rest_ensure_response($resp->body); } ] );

    register_rest_route( 'gh-updater/v1', '/repos/(?P<owner>[^/]+)/(?P<repo>[^/]+)/branches', [ 'methods'=>'GET', 'callback'=>function($req){ if(!current_user_can('manage_options')) return new WP_Error('forbidden','Forbidden', ['status'=>403]); $token = GHU_Settings::get_token(); if ( empty($token) ) return new WP_Error('no_token','No token configured', ['status'=>403]); $owner = $req->get_param('owner'); $repo = $req->get_param('repo'); $c = new GHU_Client($token); $resp = $c->request('GET',"/repos/{$owner}/{$repo}/branches?per_page=100"); return rest_ensure_response($resp->body); } ] );

    register_rest_route( 'gh-updater/v1', '/hooks/create', [ 'methods'=>'POST', 'callback'=>function($req){ if(!current_user_can('manage_options')) return new WP_Error('forbidden','Forbidden', ['status'=>403]); $body = $req->get_json_params(); if(empty($body['owner'])||empty($body['repo'])) return new WP_Error('missing','Missing', ['status'=>400]); $token = GHU_Settings::get_token(); if ( empty($token) ) return new WP_Error('no_token','No token configured', ['status'=>403]); $wm = new GHU_Client($token); $secret = GHU_Settings::get_webhook_secret(); if ( empty($secret) ) { $secret = wp_generate_password(24,false,false); GHU_Settings::set_webhook_secret($secret); }
        $callback = rest_url('gh-updater/v1/webhook');
        $payload = ['name'=>'web','active'=>true,'events'=>['push','pull_request','release','create','delete'],'config'=>['url'=>$callback,'content_type'=>'json','secret'=>$secret,'insecure_ssl'=>'0']];
        $resp = $wm->request('POST', "/repos/{$body['owner']}/{$body['repo']}/hooks", $payload );
        return rest_ensure_response(['status'=>$resp->status,'body'=>$resp->body]); } ] );

    register_rest_route( 'gh-updater/v1', '/hooks/list', [ 'methods'=>'GET', 'callback'=>function($req){ if(!current_user_can('manage_options')) return new WP_Error('forbidden','Forbidden', ['status'=>403]); $owner = $req->get_param('owner'); $repo = $req->get_param('repo'); if ( empty($owner) || empty($repo) ) return new WP_Error('missing','Missing', ['status'=>400]); $token = GHU_Settings::get_token(); $c = new GHU_Client($token); $resp = $c->request('GET', "/repos/{$owner}/{$repo}/hooks"); return rest_ensure_response(['status'=>$resp->status,'body'=>$resp->body]); } ] );

    register_rest_route( 'gh-updater/v1', '/hooks/ping', [ 'methods'=>'POST', 'callback'=>function($req){ if(!current_user_can('manage_options')) return new WP_Error('forbidden','Forbidden', ['status'=>403]); $body = $req->get_json_params(); if(empty($body['owner'])||empty($body['repo'])) return new WP_Error('missing','Missing', ['status'=>400]); $token = GHU_Settings::get_token(); $c = new GHU_Client($token); $resp = $c->request('GET', "/repos/{$body['owner']}/{$body['repo']}/hooks"); if ($resp->status!==200) return rest_ensure_response(['status'=>$resp->status,'body'=>$resp->body]); $hooks = $resp->body; $callback = rest_url('gh-updater/v1/webhook'); $found = null; foreach($hooks as $h) { if(isset($h['config']['url']) && $h['config']['url']==$callback) { $found=$h; break; } } if(!$found) return new WP_Error('no_hook','No hook found', ['status'=>404]); $ping = $c->request('POST', "/repos/{$body['owner']}/{$body['repo']}/hooks/{$found['id']}/pings"); return rest_ensure_response(['status'=>$ping->status,'body'=>$ping->body]); } ] );

    // Activity & processing
    register_rest_route( 'gh-updater/v1', '/activity', [ 'methods'=>'GET', 'callback'=>function(){ if(!current_user_can('manage_options')) return new WP_Error('forbidden','Forbidden', ['status'=>403]); global $wpdb; $table = $wpdb->prefix . 'github_updater_activity'; $rows = $wpdb->get_results( "SELECT * FROM $table ORDER BY created_at DESC LIMIT 100", ARRAY_A ); return rest_ensure_response($rows); } ] );

    register_rest_route( 'gh-updater/v1', '/process-events', [ 'methods'=>'POST', 'callback'=>function(){ if(!current_user_can('manage_options')) return new WP_Error('forbidden','Forbidden', ['status'=>403]); // process unprocessed events
        global $wpdb; $t = $wpdb->prefix . 'github_updater_events'; $a = $wpdb->prefix . 'github_updater_activity'; $rows = $wpdb->get_results( "SELECT * FROM $t WHERE processed = 0 ORDER BY id ASC LIMIT 50" ); foreach($rows as $r){ $p = json_decode($r->payload, true); $rec = ['event_type'=>$r->event_type,'repo_full_name'=>$p['repository']['full_name'] ?? '','actor'=>$p['sender']['login'] ?? ($p['pusher']['name'] ?? ''),'meta'=>isset($p['ref'])?json_encode(['ref'=>$p['ref']]):null,'payload'=>$r->payload,'created_at'=>current_time('mysql',1)]; $wpdb->insert($a, $rec); $wpdb->update($t, ['processed'=>1], ['id'=>$r->id]); } return rest_ensure_response(['processed'=>count($rows)]); } ] );

    // webhook receiver
    register_rest_route( 'gh-updater/v1', '/webhook', [ 'methods'=>'POST', 'callback'=>function($req){ $payload = file_get_contents('php://input'); $sig = isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) ? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : ''; $event = isset($_SERVER['HTTP_X_GITHUB_EVENT']) ? $_SERVER['HTTP_X_GITHUB_EVENT'] : 'unknown'; $secret = GHU_Settings::get_webhook_secret(); if ( $secret ) { $hash = 'sha256=' . hash_hmac('sha256', $payload, $secret); if ( ! hash_equals($hash, $sig) ) { return new WP_Error('invalid_sig','Invalid signature', ['status'=>401]); } }
        $data = json_decode( $payload, true ); global $wpdb; $t = $wpdb->prefix . 'github_updater_events'; $wpdb->insert( $t, ['event_type'=>$event,'repo_full_name'=> $data['repository']['full_name'] ?? '', 'payload'=> wp_json_encode($data), 'processed'=>0 ] ); return rest_ensure_response(['ok'=>true]); } , 'permission_callback' => '__return_true' ] );

} );

// Admin menu & assets
add_action( 'admin_menu', function(){ add_menu_page('GitHub Updater','GitHub Updater','manage_options','github-updater',function(){ echo '<div class="wrap"><h1>GitHub Updater</h1><div id="gh-updater-app">Loading…</div></div>'; }); } );

add_action( 'admin_enqueue_scripts', function($hook){ if($hook!=='toplevel_page_github-updater') return; wp_enqueue_style('ghu-admin', GH_UPDATER_PLUGIN_URL . 'assets/css/admin.css', [], GH_UPDATER_VERSION); wp_enqueue_script('ghu-admin', GH_UPDATER_PLUGIN_URL . 'assets/js/admin.js', [], GH_UPDATER_VERSION, true); wp_localize_script('ghu-admin','GH_UPDATER',['restBase'=>rest_url('gh-updater/v1'),'nonce'=>wp_create_nonce('wp_rest')]); } );

