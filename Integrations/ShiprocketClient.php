<?php

namespace Zerohold\Shipping\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shiprocket API Client
 * 
 * Responsible for handling low-level API communication with Shiprocket.
 */
class ShiprocketClient {

	private $baseUrl  = 'https://apiv2.shiprocket.in/v1/external/';
	private $token    = null;
	private $email    = 'Market.zerohold.in@gmail.com';
	private $password = '&aiI$#QUq00rZZZEYT&vYCR3voLgCIb8';

	/**
	 * Login to Shiprocket and obtain an auth token.
	 *
	 * @param string|null $email
	 * @param string|null $password
	 * @return array|\WP_Error
	 */
	public function login( $email = null, $password = null ) {
		$payload = [
			'email'    => $email ?? $this->email,
			'password' => $password ?? $this->password
		];

		$response = $this->post( 'auth/login', $payload, false ); // no token yet

		if ( ! is_wp_error( $response ) && isset( $response['token'] ) ) {
			$this->token = $response['token'];
		}

		return $response;
	}

	/**
	 * Authenticate and store token.
	 *
	 * @param string|null $email
	 * @param string|null $password
	 * @return bool
	 */
	public function authenticate( $email = null, $password = null ) {
		$response = $this->post( 'auth/login', [
			'email'    => $email ?? $this->email,
			'password' => $password ?? $this->password
		], false );

		if ( ! is_wp_error( $response ) && isset( $response['token'] ) ) {
			$this->token = $response['token'];
			return true;
		}

		return false;
	}

	/**
	 * Set the auth token manually (e.g. from cache).
	 *
	 * @param string $token
	 */
	public function set_token( $token ) {
		$this->token = $token;
	}

	/**
	 * Wrapper for GET requests.
	 *
	 * @param string $endpoint
	 * @param array  $query_args
	 * @param bool   $auth
	 * @return array|\WP_Error
	 */
	public function get( $endpoint, $query_args = [], $auth = true ) {
		// Auto-refresh token if needed
		if ( $auth ) {
			$this->token = $this->getValidToken();
			if ( is_wp_error( $this->token ) ) {
				return $this->token;
			}
		}

		$url = $this->baseUrl . $endpoint;

		if ( ! empty( $query_args ) ) {
			$url = add_query_arg( $query_args, $url );
		}

		$args = [
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'timeout' => 30,
		];

		if ( $auth && $this->token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $this->token;
		}

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		return json_decode( $body, true );
	}

	/**
	 * Wrapper for POST requests.
	 *
	 * @param string $endpoint
	 * @param array  $data
	 * @param bool   $auth
	 * @return array|\WP_Error
	 */
	public function post( $endpoint, $data, $auth = true ) {
		// Auto-refresh token if needed
		if ( $auth ) {
			$this->token = $this->getValidToken();
			if ( is_wp_error( $this->token ) ) {
				return $this->token;
			}
		}

		$url = $this->baseUrl . $endpoint;

		$args = [
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'body'    => wp_json_encode( $data ),
			'timeout' => 30,
		];

		if ( $auth && $this->token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $this->token;
		}

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		return json_decode( $body, true );
	}

	/**
	 * Retrieves a valid auth token.
	 * Auto-logs in if token is missing or expired.
	 *
	 * @return string|\WP_Error
	 */
	private function getValidToken() {
		// 1. Check Memory
		if ( ! empty( $this->token ) ) {
			return $this->token;
		}

		// 2. Check Database (Transient)
		$stored_token = get_transient( 'zh_shiprocket_token' );
		if ( $stored_token ) {
			$this->token = $stored_token;
			return $this->token;
		}

		// 3. Re-Login
		$login_response = $this->login();
		
		if ( is_wp_error( $login_response ) ) {
			return $login_response;
		}

		if ( isset( $login_response['token'] ) ) {
			$this->token = $login_response['token'];
			// Store for 24 hours (usually valid for 10 days, but 24h is safe)
			set_transient( 'zh_shiprocket_token', $this->token, 24 * HOUR_IN_SECONDS );
			return $this->token;
		}

		return new \WP_Error( 'sr_auth_failed', 'Shiprocket Login Failed (No Token)' );
	}
}
