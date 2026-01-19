<?php

namespace Zerohold\Shipping\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BigShip API Client
 *
 * Handles authentication and low-level API communication.
 */
class BigShipClient {

	private $base_url = 'https://api.bigship.in/api/';
	private $username;
	private $password;
	private $access_key;

	public function __construct() {
		// Credentials
		$this->username   = 'piyushbiber@gmail.com';
		$this->password   = 'Piyush@9452';
		$this->access_key = '2eb90c694254ea38509d00df79f05c22af33ee259f34eb6b208561bee448006d';
	}

	/**
	 * Authenticate and retrieve token.
	 * 
	 * @return string|WP_Error Token or error.
	 */
	public function get_token() {
		$token = get_transient( 'zh_bigship_token' );

		if ( $token ) {
			return $token;
		}

		return $this->login();
	}

	/**
	 * Login to BigShip.
	 * 
	 * @return string|WP_Error
	 */
	public function login() {
		$url = $this->base_url . 'login/user';

		$payload = [
			'user_name'  => $this->username,
			'password'   => $this->password,
			'access_key' => $this->access_key,
		];

		$response = wp_remote_post( $url, [
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'body'    => wp_json_encode( $payload ),
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		// Token usually in data['token'] or similar.
		// Assuming standard response based on common patterns if not explicit in dump
		// "Token Expiry: 12 Hours" implies a token is returned.
		$token = $data['token'] ?? $data['data']['token'] ?? null;
		
		if ( ! $token ) {
			// Fallback check
			if ( isset( $data['status'] ) && $data['status'] == false ) {
				return new \WP_Error( 'bigship_auth_fail', $data['message'] ?? 'Login failed' );
			}
			return new \WP_Error( 'bigship_no_token', 'Token not found', $data );
		}

		// Store for 11 hours (buffer for 12 hours expiry)
		set_transient( 'zh_bigship_token', $token, 11 * HOUR_IN_SECONDS );

		return $token;
	}

	/**
	 * POST Request
	 */
	public function post( $endpoint, $data = [] ) {
		$token = $this->get_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = $this->base_url . $endpoint;

		$response = wp_remote_post( $url, [
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $token,
			],
			'body'    => wp_json_encode( $data ),
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		return json_decode( $body, true );
	}

	/**
	 * GET Request
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

		$response = wp_remote_get( $url, [
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $token,
			],
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		return json_decode( $body, true );
	}
}
