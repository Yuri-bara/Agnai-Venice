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
			add_filter( 'rest_pre_serve_request', array( $this, 'serve_raw_proxy_response' ), 10, 4 );
			add_filter( 'rest_post_dispatch', array( $this, 'add_cors_to_rest_response' ), 10, 3 );
			add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
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
			if ( 'OPTIONS' === strtoupper( $request->get_method() ) ) {
				return true;
			}

			$shared_secret = $this->get_proxy_shared_secret();

			if ( '' === $shared_secret ) {
				return new WP_Error( 'venice_proxy_missing_secret', 'Missing proxy shared secret. Set VENICE_PROXY_SHARED_SECRET constant or save venice_proxy_shared_secret in settings.', array( 'status' => 500 ) );
			}

			$provided_secret = $this->extract_proxy_secret( $request );
			if ( ! hash_equals( $shared_secret, $provided_secret ) ) {
				return new WP_Error( 'venice_proxy_forbidden', 'Invalid or missing proxy shared secret.', array( 'status' => 403 ) );
			}

			return true;
		}

		public function handle_proxy_request( WP_REST_Request $request ) {
			if ( 'OPTIONS' === strtoupper( $request->get_method() ) ) {
				return $this->build_cors_preflight_response( $request );
			}

			$api_key = $this->get_api_key();
			if ( '' === $api_key ) {
				return new WP_Error( 'venice_proxy_missing_api_key', 'Missing Venice API key. Set VENICE_API_KEY constant or save venice_proxy_api_key in settings.', array( 'status' => 500 ) );
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
				return $this->stream_with_curl( $request, $request->get_method(), $target_url, $headers, $body_info['body'], 'HEAD' === strtoupper( $request->get_method() ) );
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

			return $this->build_proxy_response(
				$status_code,
				$this->filter_response_headers( wp_remote_retrieve_headers( $upstream ) ),
				wp_remote_retrieve_body( $upstream ),
				'HEAD' === strtoupper( $request->get_method() )
			);
		}

		private function build_target_url( WP_REST_Request $request ) {
			$base       = $this->get_target_base();
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

		private function stream_with_curl( WP_REST_Request $request, $method, $url, array $headers, $body, $is_head ) {
			if ( ! function_exists( 'curl_init' ) ) {
				return new WP_Error( 'venice_proxy_streaming_unavailable', 'Streaming is unavailable because cURL is not installed.', array( 'status' => 500 ) );
			}

			$curl_headers = array();
			foreach ( $headers as $k => $v ) {
				$curl_headers[] = $k . ': ' . $v;
			}
			$curl_headers[] = 'Expect:';

			$current_headers      = array();
			$current_status_code  = 0;
			$final_headers        = array();
			$final_status_code    = 200;
			$headers_sent         = false;

			$emit_final_headers = function () use ( &$headers_sent, &$final_status_code, &$final_headers ) {
				if ( $headers_sent || headers_sent() ) {
					return;
				}
				status_header( $final_status_code );
				$this->send_headers_from_array( $this->filter_response_headers( $final_headers ) );
				$this->send_cors_headers( $request );
				header( 'X-Venice-Proxy-Streaming: best-effort', true );
				$headers_sent = true;
			};

			$ch = curl_init( $url );
			curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $method );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $curl_headers );
			curl_setopt( $ch, CURLOPT_TIMEOUT, $this->get_timeout() );
			curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, false );
			curl_setopt( $ch, CURLOPT_HEADERFUNCTION, function ( $curl, $header ) use ( &$current_headers, &$current_status_code, &$final_headers, &$final_status_code, &$headers_sent, $emit_final_headers ) {
				$trimmed = trim( $header );
				if ( '' === $trimmed ) {
					if ( $current_status_code >= 100 && $current_status_code < 200 ) {
						$current_headers     = array();
						$current_status_code = 0;
						return strlen( $header );
					}
					if ( $current_status_code >= 200 && ! $headers_sent ) {
						$final_status_code = $current_status_code;
						$final_headers     = $current_headers;
						$emit_final_headers();
					}
					return strlen( $header );
				}
				if ( 0 === stripos( $trimmed, 'HTTP/' ) ) {
					$current_headers = array();
					$parts = explode( ' ', $trimmed );
					$current_status_code = isset( $parts[1] ) ? (int) $parts[1] : 0;
					return strlen( $header );
				}
				$pieces = explode( ':', $trimmed, 2 );
				if ( 2 === count( $pieces ) ) {
					$current_headers[ trim( $pieces[0] ) ] = trim( $pieces[1] );
				}
				return strlen( $header );
			} );
			curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function ( $curl, $chunk ) use ( $is_head, $emit_final_headers ) {
				$emit_final_headers();
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

			$emit_final_headers();

			exit;
		}

		private function build_proxy_response( $status_code, array $headers, $body, $is_head ) {
			$response = new WP_REST_Response(
				array(
					'__venice_proxy_raw' => true,
					'status'             => (int) $status_code,
					'headers'            => $headers,
					'body'               => (string) $body,
					'is_head'            => (bool) $is_head,
				),
				(int) $status_code
			);
			return $response;
		}

		public function serve_raw_proxy_response( $served, $result, $request, $server ) {
			if ( $served || ! ( $result instanceof WP_REST_Response ) ) {
				return $served;
			}

			$data = $result->get_data();
			if ( ! $this->is_proxy_response( $data ) ) {
				return $served;
			}

			if ( ! headers_sent() ) {
				status_header( (int) $data['status'] );
				$this->send_headers_from_array( $data['headers'] );
				$this->send_cors_headers( $request );
			}

			if ( ! $data['is_head'] ) {
				echo (string) $data['body'];
			}

			return true;
		}

		private function is_proxy_response( $data ) {
			return is_array( $data ) && ! empty( $data['__venice_proxy_raw'] ) && isset( $data['status'], $data['headers'], $data['body'], $data['is_head'] );
		}

		public function add_cors_to_rest_response( $response, $server, $request ) {
			if ( ! ( $request instanceof WP_REST_Request ) || ! $this->is_proxy_namespace_request( $request ) ) {
				return $response;
			}

			if ( $response instanceof WP_HTTP_Response ) {
				$this->add_cors_headers_to_response( $response, $request );
			}

			return $response;
		}

		private function send_headers_from_array( array $headers ) {
			foreach ( $headers as $name => $value ) {
				header( $name . ': ' . $value, true );
			}
		}

		private function send_cors_headers( WP_REST_Request $request = null ) {
			$allowed_origin = $this->get_cors_allow_origin_value( $request );
			if ( '' !== $allowed_origin ) {
				header( 'Access-Control-Allow-Origin: ' . $allowed_origin, true );
			}
			header( 'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD', true );
			header( 'Access-Control-Allow-Headers: ' . $this->get_cors_allow_headers_value( $request ), true );
			header( 'Access-Control-Max-Age: 600', true );
			header( 'Vary: Origin, Access-Control-Request-Method, Access-Control-Request-Headers', true );
		}

		private function add_cors_headers_to_response( WP_HTTP_Response $response, WP_REST_Request $request = null ) {
			$allowed_origin = $this->get_cors_allow_origin_value( $request );
			if ( '' !== $allowed_origin ) {
				$response->header( 'Access-Control-Allow-Origin', $allowed_origin );
			}
			$response->header( 'Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD' );
			$response->header( 'Access-Control-Allow-Headers', $this->get_cors_allow_headers_value( $request ) );
			$response->header( 'Access-Control-Max-Age', '600' );
			$response->header( 'Vary', 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers' );
		}

		private function build_cors_preflight_response( WP_REST_Request $request ) {
			$response = new WP_REST_Response( array( 'ok' => true ), 200 );
			$this->add_cors_headers_to_response( $response, $request );
			return $response;
		}


		private function get_cors_allow_headers_value( WP_REST_Request $request = null ) {
			$fallback = 'authorization, content-type, x-venice-proxy-secret, accept';
			if ( ! ( $request instanceof WP_REST_Request ) ) {
				return $fallback;
			}

			$requested_headers = $this->sanitize_cors_request_headers( (string) $request->get_header( 'access-control-request-headers' ) );
			if ( '' === $requested_headers ) {
				return $fallback;
			}

			return $requested_headers;
		}

		private function get_cors_allow_origin_value( WP_REST_Request $request = null ) {
			if ( ! ( $request instanceof WP_REST_Request ) ) {
				return '';
			}

			$origin = $this->safe_header_value( (string) $request->get_header( 'origin' ) );
			$allowed_origins = array(
				'https://agnai.chat',
				'https://hcatoolkit.com',
			);

			if ( in_array( $origin, $allowed_origins, true ) ) {
				return $origin;
			}

			return '';
		}

		private function sanitize_cors_request_headers( $requested_headers ) {
			$raw = $this->safe_header_value( (string) $requested_headers );
			if ( '' === $raw ) {
				return '';
			}

			$tokens = array();
			foreach ( explode( ',', $raw ) as $token ) {
				$name = strtolower( trim( (string) $token ) );
				if ( '' === $name ) {
					continue;
				}
				if ( 1 !== preg_match( '/^[a-z0-9!#$%&\'*+.^_`|~-]+$/', $name ) ) {
					continue;
				}
				$tokens[] = $name;
			}

			if ( empty( $tokens ) ) {
				return '';
			}

			return implode( ', ', $tokens );
		}

		private function is_proxy_namespace_request( WP_REST_Request $request ) {
			return 0 === strpos( $request->get_route(), '/wp-json/' . self::NAMESPACE . '/' ) || 0 === strpos( $request->get_route(), '/' . self::NAMESPACE . '/' );
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
			if ( defined( 'VENICE_PROXY_TIMEOUT' ) ) {
				$timeout = (int) VENICE_PROXY_TIMEOUT;
				return $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT;
			}

			$timeout = (int) get_option( 'venice_proxy_timeout', self::DEFAULT_TIMEOUT );
			return $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT;
		}

		private function get_api_key() {
			if ( defined( 'VENICE_API_KEY' ) ) {
				return trim( (string) VENICE_API_KEY );
			}
			return trim( (string) get_option( 'venice_proxy_api_key', '' ) );
		}

		private function get_proxy_shared_secret() {
			if ( defined( 'VENICE_PROXY_SHARED_SECRET' ) ) {
				return trim( (string) VENICE_PROXY_SHARED_SECRET );
			}
			return trim( (string) get_option( 'venice_proxy_shared_secret', '' ) );
		}

		private function get_target_base() {
			if ( defined( 'VENICE_PROXY_TARGET_BASE' ) ) {
				$constant_url = esc_url_raw( trim( (string) VENICE_PROXY_TARGET_BASE ) );
				return '' !== $constant_url ? $constant_url : self::DEFAULT_TARGET_BASE;
			}
			$option_url = esc_url_raw( trim( (string) get_option( 'venice_proxy_target_base', self::DEFAULT_TARGET_BASE ) ) );
			return '' !== $option_url ? $option_url : self::DEFAULT_TARGET_BASE;
		}

		public function register_settings_page() {
			add_options_page(
				'Venice AI Proxy',
				'Venice AI Proxy',
				'manage_options',
				'venice-ai-proxy',
				array( $this, 'render_settings_page' )
			);
		}

		public function register_settings() {
			register_setting( 'venice_proxy_settings', 'venice_proxy_api_key', array( $this, 'sanitize_secret_text' ) );
			register_setting( 'venice_proxy_settings', 'venice_proxy_shared_secret', array( $this, 'sanitize_secret_text' ) );
			register_setting( 'venice_proxy_settings', 'venice_proxy_target_base', array( $this, 'sanitize_target_base' ) );
			register_setting( 'venice_proxy_settings', 'venice_proxy_timeout', array( $this, 'sanitize_timeout' ) );

			add_settings_section( 'venice_proxy_main', 'Proxy Credentials and Target', '__return_false', 'venice-ai-proxy' );
			add_settings_field( 'venice_proxy_api_key', 'Venice API Key', array( $this, 'render_api_key_field' ), 'venice-ai-proxy', 'venice_proxy_main' );
			add_settings_field( 'venice_proxy_shared_secret', 'Proxy Shared Secret', array( $this, 'render_shared_secret_field' ), 'venice-ai-proxy', 'venice_proxy_main' );
			add_settings_field( 'venice_proxy_target_base', 'Target Base URL', array( $this, 'render_target_base_field' ), 'venice-ai-proxy', 'venice_proxy_main' );
			add_settings_field( 'venice_proxy_timeout', 'Timeout Seconds', array( $this, 'render_timeout_field' ), 'venice-ai-proxy', 'venice_proxy_main' );
		}

		public function render_settings_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			?>
			<div class="wrap">
				<h1>Venice AI Proxy</h1>
				<p>Use a long random proxy shared secret. This is the API key you put into Agnai/Agnaistic. It is not your Venice API key.</p>
				<form method="post" action="options.php">
					<?php
					settings_fields( 'venice_proxy_settings' );
					do_settings_sections( 'venice-ai-proxy' );
					submit_button();
					?>
				</form>
			</div>
			<?php
		}

		public function render_api_key_field() {
			$value = defined( 'VENICE_API_KEY' ) ? '' : (string) get_option( 'venice_proxy_api_key', '' );
			printf( '<input type="password" name="venice_proxy_api_key" value="%s" class="regular-text" autocomplete="new-password" />', esc_attr( $value ) );
			if ( defined( 'VENICE_API_KEY' ) ) {
				echo '<p class="description">VENICE_API_KEY constant is defined and overrides this saved value.</p>';
				return;
			}
			echo '<p class="description">Your real Venice API key used for upstream requests.</p>';
		}

		public function render_shared_secret_field() {
			$value = defined( 'VENICE_PROXY_SHARED_SECRET' ) ? '' : (string) get_option( 'venice_proxy_shared_secret', '' );
			printf( '<input type="password" name="venice_proxy_shared_secret" value="%s" class="regular-text" autocomplete="new-password" />', esc_attr( $value ) );
			if ( defined( 'VENICE_PROXY_SHARED_SECRET' ) ) {
				echo '<p class="description">VENICE_PROXY_SHARED_SECRET constant is defined and overrides this saved value.</p>';
				return;
			}
			echo '<p class="description">Used by Agnai/Agnaistic as the API key for this proxy endpoint.</p>';
		}

		public function render_target_base_field() {
			$value = defined( 'VENICE_PROXY_TARGET_BASE' ) ? (string) VENICE_PROXY_TARGET_BASE : (string) get_option( 'venice_proxy_target_base', self::DEFAULT_TARGET_BASE );
			printf( '<input type="url" name="venice_proxy_target_base" value="%s" class="regular-text code" />', esc_attr( $value ) );
			echo '<p class="description">Default: ' . esc_html( self::DEFAULT_TARGET_BASE ) . '</p>';
		}

		public function render_timeout_field() {
			$value = defined( 'VENICE_PROXY_TIMEOUT' ) ? (int) VENICE_PROXY_TIMEOUT : (int) get_option( 'venice_proxy_timeout', self::DEFAULT_TIMEOUT );
			printf( '<input type="number" min="1" step="1" name="venice_proxy_timeout" value="%d" class="small-text" />', (int) $value );
			echo '<p class="description">Request timeout in seconds. Default: ' . (int) self::DEFAULT_TIMEOUT . '.</p>';
		}

		public function sanitize_secret_text( $value ) {
			return trim( sanitize_text_field( (string) $value ) );
		}

		public function sanitize_target_base( $value ) {
			$url = esc_url_raw( trim( (string) $value ) );
			return '' !== $url ? $url : self::DEFAULT_TARGET_BASE;
		}

		public function sanitize_timeout( $value ) {
			$timeout = absint( $value );
			return $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT;
		}
	}

	new Venice_AI_Reverse_Proxy();
}
