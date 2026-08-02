<?php
namespace GH_Updater;

if ( ! defined( 'ABSPATH' ) ) exit;

interface Provider_Interface {
    public function list_repositories( $credentials );
    public function list_branches( $owner, $repo );
    public function get_commit_history( $owner, $repo, $branch, $since = null, $limit = 50 );
    public function get_commit( $owner, $repo, $sha );
    public function compare( $owner, $repo, $base, $head );
    public function create_webhook( $owner, $repo, $callback_url, $events, $secret );
    public function verify_webhook( $payload, $signature, $secret );
}
