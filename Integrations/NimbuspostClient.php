<?php

namespace Zerohold\Shipping\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NimbusPost API Client
 *
 * Handles authentication and low-level API communication.
 */
class NimbuspostClient {

	private $base_url = 'https://api.nimbuspost.com/v1/';
	private $email;
	private $password;

	public function __construct() {
		// Hardcoded credentials as provided
		$this->email    = 'piyushbiber+3122@gmail.com';
		$this->password = 'jB3uVerJNW';
	}

	/**
	 * Authenticate and retrieve token.
	 * Uses WordPress transients to cache the token.
	 *
	 * @return string|WP_Error Token or error.
	 */
	public function get_token() {
		$token = get_transient( 'zh_nimbus_token' );

		if ( $token ) {
			return $token;
		}

		return $this->login();
	}

	/**
	 * Perform login request to get a new token.
	 *
	 * @return string|WP_Error
	 */
	public function login() {
		$url = $this->base_url . 'users/login';

		$body = [
			'email'    => $this->email,
			'password' => $this->password,
		];

		$response = wp_remote_post( $url, [
			'body'    => $body, // Unencoded body for standard POST? Docs say JSON usually. Let's try standard body first or verify.
			// Usually modern APIs expect JSON. Shiprocket used JSON. 
			// Let's assume JSON for safety given "JWT login" usually implies JSON payload.
			// Re-reading snippet: wp_remote_post default sends form-data unless headers set.
			// Let's check Shiprocket client implementation again? 
			// Shiprocket used wp_json_encode. I will use that here too.
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		if ( isset( $data['status'] ) && $data['status'] === false ) {
			return new \WP_Error( 'nimbus_auth_failed', $data['message'] ?? 'Authentication failed' );
		}

		// Check where the token is. Docs usually return { status: true, data: token } or simply { token: ... }
		// Based on user pseudo: $response['token']
		// Assuming structure typically: { status: true, data: { token: "..." } } or just top level.
		// Let's look for 'token' or 'data' -> 'token'.
		// User said: $token = $response['token']
		
		$token = $data['data']['token'] ?? $data['token'] ?? null;

		if ( ! $token ) {
			return new \WP_Error( 'nimbus_auth_no_token', 'Token not found in response', $data );
		}

		// Store in transient for 1 hour (3600 seconds) - buffer
		set_transient( 'zh_nimbus_token', $token, 3600 );

		return $token;
	}

	/**
	 * POST request wrapper
	 */
	public function post( $endpoint, $data = [] ) {
		$token = $this->get_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = $this->base_url . $endpoint;

		$args = [
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $token,
			],
			'body'    => wp_json_encode( $data ),
			'timeout' => 30,
		];

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		return json_decode( $body, true );
	}

	/**
	 * GET request wrapper
	 */
	public function get( $endpoint, $query_args = [] ) {
		$token = $this->get_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = $this->base_url . $endpoint;

		if ( ! empty( $query_args ) ) {
			$url = add_query_arg( $query_args, $url );
		}

		$args = [
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $token,
			],
			'timeout' => 30,
		];

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		return json_decode( $body, true );
	}
}
