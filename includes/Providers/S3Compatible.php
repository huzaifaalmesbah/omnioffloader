<?php
/**
 * Generic S3-compatible storage provider (MinIO, OVHcloud, Scaleway, etc.).
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Providers;

use Aws\S3\S3Client;
use OmniOffloader\Core\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class S3Compatible
 */
class S3Compatible extends AbstractS3Provider {

	/**
	 * Machine key.
	 *
	 * @return string
	 */
	public function get_key() {
		return 's3_compatible';
	}

	/**
	 * Human name.
	 *
	 * @return string
	 */
	public function get_name() {
		$custom_name = trim( $this->credential( 'name' ) );

		if ( '' !== $custom_name ) {
			return $custom_name;
		}

		return 'Any S3-Compatible Storage (MinIO, OVHcloud, ...)';
	}

	/**
	 * Public/CDN base URL used for rewrites.
	 *
	 * @return string
	 */
	public function get_domain() {
		$custom = Helpers::normalize_url( $this->credential( 'domain' ) );

		if ( '' !== $custom ) {
			return $custom;
		}

		$bucket   = $this->get_bucket();
		$endpoint = Helpers::normalize_url( $this->credential( 'endpoint' ) );

		if ( empty( $bucket ) || '' === $endpoint ) {
			return '';
		}

		$path_style = in_array( $this->credential( 'path_style' ), array( '1', 'true', true ), true );

		if ( $path_style ) {
			return $endpoint . '/' . $bucket;
		}

		$host        = (string) wp_parse_url( $endpoint, PHP_URL_HOST );
		$port        = wp_parse_url( $endpoint, PHP_URL_PORT );
		$port_suffix = ! empty( $port ) ? ':' . $port : '';

		return 'https://' . $bucket . '.' . $host . $port_suffix;
	}

	/**
	 * Build the S3 client configured for generic S3-compatible endpoints.
	 *
	 * @return S3Client
	 */
	protected function create_client() {
		$endpoint   = Helpers::normalize_url( $this->credential( 'endpoint' ) );
		$region     = '' !== $this->credential( 'region' ) ? $this->credential( 'region' ) : 'us-east-1';
		$path_style = in_array( $this->credential( 'path_style' ), array( '1', 'true', true ), true );

		$config = array(
			'version'                     => 'latest',
			'use_aws_shared_config_files' => false,
			'region'                      => $region,
			'use_path_style_endpoint'     => $path_style,
			'credentials'                 => array(
				'key'    => $this->credential( 'key' ),
				'secret' => $this->credential( 'secret' ),
			),
		);

		if ( '' !== $endpoint ) {
			$config['endpoint'] = $endpoint;
		}

		return new S3Client( $config );
	}

	/**
	 * Credential field definitions for the admin UI.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function credential_fields() {
		return array(
			array(
				'name'        => 'name',
				'label'       => __( 'Provider Label', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => 'MinIO',
				'description' => __( 'Custom name to identify this storage (e.g. MinIO, OVHcloud, Scaleway, Linode).', 'omnioffloader' ),
			),
			array(
				'name'        => 'key',
				'label'       => __( 'Access Key ID', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => __( 'Your Access Key ID', 'omnioffloader' ),
				'description' => '',
			),
			array(
				'name'        => 'secret',
				'label'       => __( 'Secret Access Key', 'omnioffloader' ),
				'type'        => 'password',
				'placeholder' => __( 'Your Secret Access Key', 'omnioffloader' ),
				'description' => '',
			),
			array(
				'name'        => 'endpoint',
				'label'       => __( 'S3 Endpoint URL', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => 'https://minio.yourdomain.com:9000',
				'description' => __( 'Endpoint URL of your S3-compatible service.', 'omnioffloader' ),
			),
			array(
				'name'        => 'region',
				'label'       => __( 'Region', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => 'us-east-1',
				'description' => __( 'Region (default: us-east-1).', 'omnioffloader' ),
			),
			array(
				'name'        => 'path_style',
				'label'       => __( 'Use Path-Style Endpoint', 'omnioffloader' ),
				'type'        => 'checkbox',
				'placeholder' => '',
				'description' => __( 'Enable if your storage requires path-style URLs (recommended for MinIO and self-hosted instances).', 'omnioffloader' ),
			),
			array(
				'name'        => 'bucket',
				'label'       => __( 'Bucket Name', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => __( 'Your bucket name', 'omnioffloader' ),
				'description' => '',
			),
			array(
				'name'        => 'domain',
				'label'       => __( 'Custom Domain / CDN URL (Optional)', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => 'https://media.yourdomain.com',
				'description' => __( 'Custom CDN URL or public domain mapped to this bucket.', 'omnioffloader' ),
			),
		);
	}
}
