<?php
/**
 * Plugin Name: Venice AI Reverse Proxy
 * Description: Reverse proxy for Venice AI API that strips disallowed fields like `think` from JSON payloads.
 * Version: 0.1.0
 * Author: Agnai Venice Proxy
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Venice_AI_Reverse_Proxy' ) ) {
	class Venice_AI_Reverse_Proxy {
		const NAMESPACE = 'venice-proxy/v1';
		const DEFAULT_TARGET_BASE = 'https://api.venice.ai/api/v1';
		const DEFAULT_TIMEOUT = 120;

		private $blocked_request_headers = array(
			'host',
			'content-length',
			'connection',
			'keep-alive',
			'proxy-authenticate',
			'proxy-authorization',
			'te',
			'trailer',
			'transfer-encoding',
			'upgrade',
			'cookie',
			'authorization',
			'accept-encoding',
			'x-venice-proxy-secret',
		);

		private $blocked_response_headers = array(
			'transfer-encoding',
			'content-length',
			'connection',
			'keep-alive',
			'trailer',
			'upgrade',
			'set-cookie',
		);

		public function __construct() {
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		}

		public function register_routes() {
			register_rest_route(
				self::NAMESPACE,
				'/(?P<proxy_path>.*)',
				array(
					'methods'             => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'callback'            => array( $this, 'handle_proxy_request' ),
				)
			);
		}

		public function permission_check( WP_REST_Request $request ) {
			$shared_secret = defined( 'VENICE_PROXY_SHARED_SECRET' ) ? (string) VENICE_PROXY_SHARED_SECRET : '';

			if ( '' === $shared_secret ) {
				return new WP_Error( 'venice_proxy_missing_secret', 'Missing VENICE_PROXY_SHARED_SECRET constant.', array( 'status' => 500 ) );
			}

			$provided_secret = $this->extract_proxy_secret( $request );
			if ( ! hash_equals( $shared_secret, $provided_secret ) ) {
				return new WP_Error( 'venice_proxy_forbidden', 'Invalid or missing proxy shared secret.', array( 'status' => 403 ) );
			}

			return true;
		}

		public function handle_proxy_request( WP_REST_Request $request ) {
			$api_key = defined( 'VENICE_API_KEY' ) ? (string) VENICE_API_KEY : '';
			if ( '' === $api_key ) {
				return new WP_Error( 'venice_proxy_missing_api_key', 'Missing VENICE_API_KEY constant.', array( 'status' => 500 ) );
			}

			$target_url = $this->build_target_url( $request );
			if ( '' === $target_url ) {
				return new WP_Error( 'venice_proxy_invalid_target_path', 'Invalid target path.', array( 'status' => 400 ) );
			}

			$headers   = $this->build_forward_headers( $request, $api_key );
			$body_info = $this->build_forward_body( $request );

			if ( is_wp_error( $body_info ) ) {
				return $body_info;
			}

			if ( $body_info['is_stream'] ) {
				return $this->stream_with_curl( $request->get_method(), $target_url, $headers, $body_info['body'], 'HEAD' === strtoupper( $request->get_method() ) );
			}

			$args = array(
				'method'      => $request->get_method(),
				'timeout'     => $this->get_timeout(),
				'headers'     => $headers,
				'body'        => $body_info['body'],
				'data_format' => 'body',
			);

			$upstream = wp_remote_request( $target_url, $args );
			if ( is_wp_error( $upstream ) ) {
				return new WP_Error( 'venice_proxy_upstream_failed', 'Failed upstream request: ' . $upstream->get_error_message(), array( 'status' => 502 ) );
			}

			$status_code = (int) wp_remote_retrieve_response_code( $upstream );
			if ( $status_code <= 0 ) {
				return new WP_Error( 'venice_proxy_invalid_upstream_response', 'Invalid upstream response from Venice API.', array( 'status' => 502 ) );
			}

			$this->send_raw_upstream_response(
				$status_code,
				$this->filter_response_headers( wp_remote_retrieve_headers( $upstream ) ),
				wp_remote_retrieve_body( $upstream ),
				'HEAD' === strtoupper( $request->get_method() )
			);
		}

		private function build_target_url( WP_REST_Request $request ) {
			$base       = defined( 'VENICE_PROXY_TARGET_BASE' ) ? (string) VENICE_PROXY_TARGET_BASE : self::DEFAULT_TARGET_BASE;
			$proxy_path = (string) $request->get_param( 'proxy_path' );
			$safe_path  = $this->sanitize_proxy_path( $proxy_path );
			if ( '' === $safe_path && '' !== trim( $proxy_path ) ) {
				return '';
			}

			$url = rtrim( $base, '/' );
			if ( '' !== $safe_path ) {
				$url .= '/' . $safe_path;
			}

			$params = $request->get_query_params();
			unset( $params['proxy_path'], $params['think'], $params['x_venice_proxy_secret'] );

			if ( ! empty( $params ) ) {
				$url .= '?' . http_build_query( $params );
			}

			return $url;
		}

		private function sanitize_proxy_path( $proxy_path ) {
			$trimmed = ltrim( trim( (string) $proxy_path ), '/' );
			if ( '' === $trimmed ) {
				return '';
			}
			if ( preg_match( '#^[a-z][a-z0-9+\-.]*://#i', $trimmed ) ) {
				return '';
			}

			$segments = array_filter(
				explode( '/', $trimmed ),
				function ( $segment ) {
					return '' !== $segment && '.' !== $segment && '..' !== $segment;
				}
			);

			return implode( '/', $segments );
		}

		private function build_forward_headers( WP_REST_Request $request, $api_key ) {
			$headers = array();

			foreach ( $request->get_headers() as $name => $values ) {
				$normalized = strtolower( str_replace( '_', '-', (string) $name ) );
				if ( in_array( $normalized, $this->blocked_request_headers, true ) ) {
					continue;
				}

				$canonical_name = $this->canonical_header_name( $normalized );
				if ( ! $this->is_valid_header_name( $canonical_name ) ) {
					continue;
				}

				$header_value = is_array( $values ) ? implode( ', ', $values ) : (string) $values;
				$safe_value   = $this->safe_header_value( $header_value );
				if ( '' === $safe_value ) {
					continue;
				}

				$headers[ $canonical_name ] = $safe_value;
			}

			$headers['Authorization'] = 'Bearer ' . $api_key;
			$headers['Accept']        = 'application/json';

			return $headers;
		}

		private function build_forward_body( WP_REST_Request $request ) {
			$method = strtoupper( $request->get_method() );
			if ( in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
				return array( 'body' => null, 'is_stream' => false );
			}

			$raw_body = $request->get_body();
			if ( '' === $raw_body ) {
				return array( 'body' => null, 'is_stream' => false );
			}

			if ( ! $this->should_transform_json_body( $request, $raw_body ) ) {
				return array( 'body' => $raw_body, 'is_stream' => false );
			}

			$decoded = json_decode( $raw_body, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error( 'venice_proxy_invalid_json', 'Invalid JSON request body.', array( 'status' => 400 ) );
			}

			$sanitized = $this->remove_disallowed_fields( $decoded );
			$encoded   = wp_json_encode( $sanitized );
			if ( false === $encoded ) {
				return new WP_Error( 'venice_proxy_json_encode_failed', 'Failed to encode sanitized JSON body.', array( 'status' => 500 ) );
			}

			$is_stream = is_array( $sanitized ) && ! empty( $sanitized['stream'] );
			return array( 'body' => $encoded, 'is_stream' => $is_stream );
		}

		private function should_transform_json_body( WP_REST_Request $request, $raw_body ) {
			$content_type = strtolower( (string) $request->get_header( 'content-type' ) );
			if ( false !== strpos( $content_type, 'application/json' ) || false !== strpos( $content_type, '+json' ) ) {
				return true;
			}

			$trimmed_body = ltrim( (string) $raw_body );
			return '' !== $trimmed_body && ( '{' === $trimmed_body[0] || '[' === $trimmed_body[0] );
		}

		private function remove_disallowed_fields( $value ) {
			if ( is_array( $value ) ) {
				$result = array();
				foreach ( $value as $key => $item ) {
					if ( 'think' === (string) $key ) {
						continue;
					}
					$result[ $key ] = $this->remove_disallowed_fields( $item );
				}
				return $result;
			}
			return $value;
		}

		private function stream_with_curl( $method, $url, array $headers, $body, $is_head ) {
			if ( ! function_exists( 'curl_init' ) ) {
				return new WP_Error( 'venice_proxy_streaming_unavailable', 'Streaming is unavailable because cURL is not installed.', array( 'status' => 500 ) );
			}

			$curl_headers = array();
			foreach ( $headers as $k => $v ) {
				$curl_headers[] = $k . ': ' . $v;
			}

			$response_headers = array();
			$status_code      = 200;
			$headers_sent     = false;

			$ch = curl_init( $url );
			curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $method );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $curl_headers );
			curl_setopt( $ch, CURLOPT_TIMEOUT, $this->get_timeout() );
			curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, false );
			curl_setopt( $ch, CURLOPT_HEADERFUNCTION, function ( $curl, $header ) use ( &$response_headers, &$status_code, &$headers_sent ) {
				$trimmed = trim( $header );
				if ( '' === $trimmed ) {
					if ( ! $headers_sent && ! headers_sent() ) {
						status_header( $status_code );
						$this->send_headers_from_array( $this->filter_response_headers( $response_headers ) );
						header( 'X-Venice-Proxy-Streaming: best-effort', true );
						$headers_sent = true;
					}
					return strlen( $header );
				}
				if ( 0 === stripos( $trimmed, 'HTTP/' ) ) {
					$response_headers = array();
					$parts            = explode( ' ', $trimmed );
					if ( isset( $parts[1] ) ) {
						$status_code = (int) $parts[1];
					}
					return strlen( $header );
				}
				$pieces = explode( ':', $trimmed, 2 );
				if ( 2 === count( $pieces ) ) {
					$response_headers[ trim( $pieces[0] ) ] = trim( $pieces[1] );
				}
				return strlen( $header );
			} );
			curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function ( $curl, $chunk ) use ( $is_head ) {
				if ( ! $is_head ) {
					echo $chunk;
					if ( function_exists( 'ob_flush' ) ) {
						@ob_flush();
					}
					flush();
				}
				return strlen( $chunk );
			} );
			if ( null !== $body ) {
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
			}

			$ok = curl_exec( $ch );
			if ( false === $ok ) {
				$error = curl_error( $ch );
				curl_close( $ch );
				return new WP_Error( 'venice_proxy_streaming_failed', 'Streaming request failed: ' . $error, array( 'status' => 502 ) );
			}
			curl_close( $ch );

			if ( ! $headers_sent && ! headers_sent() ) {
				status_header( $status_code );
				$this->send_headers_from_array( $this->filter_response_headers( $response_headers ) );
				header( 'X-Venice-Proxy-Streaming: best-effort', true );
			}

			exit;
		}

		private function send_raw_upstream_response( $status_code, $headers, $body, $is_head ) {
			if ( ! headers_sent() ) {
				status_header( (int) $status_code );
				$this->send_headers_from_array( $headers );
			}

			if ( ! $is_head ) {
				echo (string) $body;
			}
			exit;
		}

		private function send_headers_from_array( array $headers ) {
			foreach ( $headers as $name => $value ) {
				header( $name . ': ' . $value, true );
			}
		}

		private function canonical_header_name( $name ) {
			$parts = explode( '-', strtolower( (string) $name ) );
			$parts = array_map( 'ucfirst', $parts );
			return implode( '-', $parts );
		}

		private function is_valid_header_name( $name ) {
			return 1 === preg_match( '/^[A-Za-z0-9!#$%&\'"*+.^_`|~-]+(?:-[A-Za-z0-9!#$%&\'"*+.^_`|~-]+)*$/', (string) $name );
		}

		private function safe_header_value( $value ) {
			if ( preg_match( '/[\r\n]/', (string) $value ) ) {
				return '';
			}
			return trim( (string) $value );
		}

		private function filter_response_headers( $headers ) {
			$filtered = array();
			foreach ( $headers as $name => $value ) {
				$canonical_name = $this->canonical_header_name( $name );
				$normalized     = strtolower( str_replace( '_', '-', (string) $name ) );
				if ( in_array( $normalized, $this->blocked_response_headers, true ) ) {
					continue;
				}
				if ( ! $this->is_valid_header_name( $canonical_name ) ) {
					continue;
				}
				$joined     = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
				$safe_value = $this->safe_header_value( $joined );
				if ( '' === $safe_value ) {
					continue;
				}
				$filtered[ $canonical_name ] = $safe_value;
			}
			return $filtered;
		}

		private function extract_proxy_secret( WP_REST_Request $request ) {
			$auth = (string) $request->get_header( 'authorization' );
			if ( preg_match( '/^Bearer\s+(.+)$/i', $auth, $matches ) ) {
				return trim( $matches[1] );
			}

			$fallback = (string) $request->get_header( 'x-venice-proxy-secret' );
			if ( '' !== $fallback ) {
				return trim( $fallback );
			}

			if ( isset( $_GET['x_venice_proxy_secret'] ) ) {
				return trim( sanitize_text_field( wp_unslash( $_GET['x_venice_proxy_secret'] ) ) );
			}

			return '';
		}

		private function get_timeout() {
			$timeout = defined( 'VENICE_PROXY_TIMEOUT' ) ? (int) VENICE_PROXY_TIMEOUT : self::DEFAULT_TIMEOUT;
			return $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT;
		}
	}

	new Venice_AI_Reverse_Proxy();
}
