<?php
namespace GH_Updater;

if ( ! defined( 'ABSPATH' ) ) exit;

class GitHub_Client {
    private $token;
    private $base = 'https://api.github.com';

    public function __construct( $token = '' ) {
        $this->token = $token;
    }

    private function build_headers() {
        $headers = [
            'Accept: application/vnd.github.v3+json',
            'User-Agent: GitHub-Updater-Plugin/1.0',
        ];
        if ( $this->token ) {
            $headers[] = 'Authorization: token ' . $this->token;
        }
        return $headers;
    }

    private function request( $method, $path_or_full_url, $args = [], $is_graphql = false ) {
        $url = $is_graphql ? ($this->base . '/graphql') : ( strpos( $path_or_full_url, 'http' ) === 0 ? $path_or_full_url : $this->base . $path_or_full_url );

        $headers = $this->build_headers();
        $ch = curl_init();

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper( $method ),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_URL            => $url,
            CURLOPT_TIMEOUT        => 20,
        ];

        if ( in_array( strtoupper($method), [ 'POST', 'PUT', 'PATCH' ], true ) ) {
            $body = is_array( $args ) ? json_encode( $args ) : $args;
            $opts[CURLOPT_POSTFIELDS] = $body;
            $opts[CURLOPT_HTTPHEADER] = array_merge( $headers, [ 'Content-Type: application/json' ] );
        } elseif ( ! empty( $args ) && strtoupper($method) === 'GET' && is_array( $args ) ) {
            // query params
            $url .= ( strpos( $url, '?' ) === false ? '?' : '&' ) . http_build_query( $args );
            $opts[CURLOPT_URL] = $url;
        }

        curl_setopt_array( $ch, $opts );
        $resp = curl_exec( $ch );
        $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

        if ( curl_errno( $ch ) ) {
            $err = curl_error( $ch );
            curl_close( $ch );
            throw new \Exception( "Network error: $err" );
        }

        // Collect response headers from curl (if needed)
        $header_size = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );

        $response_headers = [];
        $raw_header = '';
        // try to get headers using CURLINFO_HEADER_OUT is request header, not response; skip detailed headers for now

        curl_close( $ch );

        $decoded = json_decode( $resp, true );
        return (object) [
            'status' => $code,
            'body'   => $decoded,
            'raw'    => $resp,
        ];
    }

    public function rest( $method, $path, $args = [] ) {
        return $this->request( $method, $path, $args, false );
    }

    public function graphql( $query, $variables = [] ) {
        $payload = [ 'query' => $query, 'variables' => $variables ];
        return $this->request( 'POST', '/graphql', $payload, true );
    }
}
