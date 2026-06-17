<?php
defined( 'ABSPATH' ) || exit;

class Woo_Image_Optimizer_API_Client {

	private string $api_url;
	private string $api_key;
	private int $timeout = 120;

	public function __construct( string $api_url, string $api_key ) {
		$this->api_url = rtrim( $api_url, '/' );
		$this->api_key = $api_key;
	}

	/**
	 * Send image to Server 2 for WebP conversion.
	 *
	 * @return array{success:bool,webp_file:string,original_size:int,optimized_size:int,saved_bytes:int,width:int,height:int}|WP_Error
	 */
	public function optimize( string $file_path, int $attachment_id, int $quality = 82, int $max_width = 2048, int $max_height = 2048 ) {
		[ 'body' => $body, 'content_type' => $ct ] = $this->build_multipart(
			[
				'attachment_id' => $attachment_id,
				'quality'       => $quality,
				'max_width'     => $max_width,
				'max_height'    => $max_height,
			],
			[ 'file' => $file_path ]
		);

		$response = wp_remote_post(
			$this->api_url . '/optimize',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => $ct,
				],
				'body'    => $body,
				'timeout' => $this->timeout,
			]
		);

		return $this->parse_json_response( $response, 'optimize' );
	}

	/**
	 * Send original image to Server 2 for permanent backup.
	 *
	 * @return array{success:bool,backup_key:string}|WP_Error
	 */
	public function backup( string $file_path, int $attachment_id ) {
		[ 'body' => $body, 'content_type' => $ct ] = $this->build_multipart(
			[ 'attachment_id' => $attachment_id ],
			[ 'file' => $file_path ]
		);

		$response = wp_remote_post(
			$this->api_url . '/backup',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => $ct,
				],
				'body'    => $body,
				'timeout' => $this->timeout,
			]
		);

		return $this->parse_json_response( $response, 'backup' );
	}

	/**
	 * Download original from Server 2 backup.
	 *
	 * @return string|WP_Error Binary file contents.
	 */
	public function get_backup( string $backup_key ) {
		$response = wp_remote_get(
			$this->api_url . '/backup/' . rawurlencode( $backup_key ),
			[
				'headers' => [ 'Authorization' => 'Bearer ' . $this->api_key ],
				'timeout' => $this->timeout,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			return new WP_Error( 'api_error', "GET /backup returned HTTP {$code}" );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return new WP_Error( 'empty_response', 'GET /backup returned empty body' );
		}

		return $body;
	}

	/**
	 * Delete backup from Server 2 (used when retention policy is enforced).
	 *
	 * @return true|WP_Error
	 */
	public function delete_backup( string $backup_key ) {
		$response = wp_remote_request(
			$this->api_url . '/backup/' . rawurlencode( $backup_key ),
			[
				'method'  => 'DELETE',
				'headers' => [ 'Authorization' => 'Bearer ' . $this->api_key ],
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'api_error', "DELETE /backup returned HTTP {$code}" );
		}

		return true;
	}

	// ---------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------

	/**
	 * Build a multipart/form-data body.
	 *
	 * @param array<string,scalar> $fields
	 * @param array<string,string> $files  name → file_path
	 * @return array{body:string,content_type:string}
	 */
	private function build_multipart( array $fields, array $files ): array {
		$boundary = '----WooImgOpt' . wp_generate_password( 16, false );
		$crlf     = "\r\n";
		$body     = '';

		foreach ( $fields as $name => $value ) {
			$body .= "--{$boundary}{$crlf}";
			$body .= "Content-Disposition: form-data; name=\"{$name}\"{$crlf}{$crlf}";
			$body .= $value . $crlf;
		}

		foreach ( $files as $name => $file_path ) {
			if ( ! file_exists( $file_path ) ) {
				continue;
			}
			$filename = basename( $file_path );
			$mime     = $this->file_mime( $file_path );
			$data     = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$body    .= "--{$boundary}{$crlf}";
			$body    .= "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"{$crlf}";
			$body    .= "Content-Type: {$mime}{$crlf}{$crlf}";
			$body    .= $data . $crlf;
		}

		$body .= "--{$boundary}--{$crlf}";

		return [
			'body'         => $body,
			'content_type' => "multipart/form-data; boundary={$boundary}",
		];
	}

	private function file_mime( string $path ): string {
		$ext_map = [
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
		];
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return $ext_map[ $ext ] ?? 'application/octet-stream';
	}

	/** @return array|WP_Error */
	private function parse_json_response( $response, string $context ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : "HTTP {$code}";
			return new WP_Error( 'api_error', "[{$context}] {$msg}" );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_response', "[{$context}] Non-JSON response" );
		}

		if ( empty( $data['success'] ) ) {
			$msg = $data['message'] ?? 'Unknown error';
			return new WP_Error( 'api_failure', "[{$context}] {$msg}" );
		}

		return $data;
	}
}
