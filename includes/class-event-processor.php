<?php
namespace GH_Updater;

if ( ! defined( 'ABSPATH' ) ) exit;

class Event_Processor {
    public static function register_hooks() {
        add_action( 'gh_updater_process_events', [ __CLASS__, 'process_pending_events' ] );
        add_action( 'gh_updater_poll', [ __CLASS__, 'poll_monitored_repos' ] );
    }

    public static function process_pending_events() {
        global $wpdb;
        $table = $wpdb->prefix . 'github_updater_events';
        $activity_table = $wpdb->prefix . 'github_updater_activity';

        $rows = $wpdb->get_results( "SELECT * FROM $table WHERE processed = 0 ORDER BY id ASC LIMIT 20" );
        if ( empty( $rows ) ) return;

        foreach ( $rows as $row ) {
            $payload = json_decode( $row->event_payload, true );
            $event = $row->event_type;

            $record = [
                'event_type' => $event,
                'payload' => wp_json_encode( $payload ),
                'created_at' => current_time( 'mysql', 1 ),
            ];

            // Enrich record with simple readable fields
            if ( isset( $payload['repository'] ) ) {
                $record['repo_full_name'] = $payload['repository']['full_name'];
            }
            if ( isset( $payload['pusher'] ) ) {
                $record['actor'] = $payload['pusher']['name'];
            } elseif ( isset( $payload['sender'] ) ) {
                $record['actor'] = $payload['sender']['login'];
            }

            // For push events, capture commits count and ref
            if ( $event === 'push' ) {
                $record['meta'] = wp_json_encode([ 'ref' => $payload['ref'], 'commits' => isset( $payload['commits'] ) ? count( $payload['commits'] ) : 0 ]);
            }

            // Insert into activity table
            $wpdb->insert( $activity_table, [
                'event_type' => $record['event_type'],
                'repo_full_name' => isset($record['repo_full_name']) ? $record['repo_full_name'] : '',
                'actor' => isset($record['actor']) ? $record['actor'] : '',
                'meta' => isset($record['meta']) ? $record['meta'] : null,
                'payload' => $record['payload'],
                'created_at' => $record['created_at'],
            ] );

            // Mark processed
            $wpdb->update( $table, [ 'processed' => 1 ], [ 'id' => $row->id ] );
        }
    }

    public static function poll_monitored_repos() {
        // Simple polling: look at monitored table and call list commits for default branch since last_checked.
        global $wpdb;
        $mon_table = $wpdb->prefix . 'github_updater_monitored';
        $rows = $wpdb->get_results( "SELECT * FROM $mon_table WHERE watched = 1 LIMIT 50" );
        if ( empty( $rows ) ) return;

        $token = Settings::decrypt_token();
        if ( empty( $token ) ) return;
        $client = new GitHub_Client( $token );

        foreach ( $rows as $r ) {
            $repo = $r->repo_full_name;
            if ( strpos( $repo, '/' ) === false ) continue;
            list( $owner, $repo_name ) = explode( '/', $repo, 2 );
            $default_branch = 'main';
            // try to get repo metadata
            $resp = $client->rest( 'GET', "/repos/{$owner}/{$repo_name}" );
            if ( $resp->status === 200 && isset( $resp->body['default_branch'] ) ) {
                $default_branch = $resp->body['default_branch'];
            }
            $since = $r->last_checked ? gmdate( 'c', strtotime( $r->last_checked ) ) : null;
            $args = [ 'sha' => $default_branch, 'per_page' => 25 ];
            if ( $since ) $args['since'] = $since;
            $commits = $client->rest( 'GET', "/repos/{$owner}/{$repo_name}/commits", $args );
            if ( $commits->status === 200 && is_array( $commits->body ) && count( $commits->body ) > 0 ) {
                // insert each as activity row
                foreach ( $commits->body as $c ) {
                    $wpdb->insert( $wpdb->prefix . 'github_updater_activity', [
                        'event_type' => 'commit',
                        'repo_full_name' => $r->repo_full_name,
                        'actor' => isset($c['commit']['author']['name']) ? $c['commit']['author']['name'] : '',
                        'meta' => wp_json_encode([ 'sha' => $c['sha'], 'message' => $c['commit']['message'] ]),
                        'payload' => wp_json_encode( $c ),
                        'created_at' => current_time( 'mysql', 1 ),
                    ] );
                }
            }
            // update last_checked
            $wpdb->update( $mon_table, [ 'last_checked' => gmdate( 'Y-m-d H:i:s' ) ], [ 'id' => $r->id ] );
        }
    }
}
