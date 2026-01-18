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
}
