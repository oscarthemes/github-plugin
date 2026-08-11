<?php
namespace GH_Updater;

if ( ! defined( 'ABSPATH' ) ) exit;

class Webhook_Manager {
    private $client;

    public function __construct( $token = '' ) {
        $this->client = new GitHub_Client( $token );
    }

    public function create_repo_webhook( $owner, $repo, $callback_url, $secret, $events = [ 'push', 'pull_request', 'release', 'create', 'delete' ] ) {
        $path = "/repos/{$owner}/{$repo}/hooks";
        $payload = [
            'name' => 'web',
            'active' => true,
            'events' => $events,
            'config' => [
                'url' => $callback_url,
                'content_type' => 'json',
                'secret' => $secret,
                'insecure_ssl' => '0'
            ]
        ];
        return $this->client->rest( 'POST', $path, $payload );
    }

    public function list_repo_hooks( $owner, $repo ) {
        return $this->client->rest( 'GET', "/repos/{$owner}/{$repo}/hooks" );
    }

    public function delete_hook( $owner, $repo, $hook_id ) {
        return $this->client->rest( 'DELETE', "/repos/{$owner}/{$repo}/hooks/{$hook_id}" );
    }
}
