<?php
/**
 * Backblaze B2 provider.
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
 * Class BackblazeB2
 *
 * @since 0.1.1
 */
class BackblazeB2 extends AbstractS3Provider {

	/**
	 * Machine key.
	 *
	 * @return string
	 */
	public function get_key() {
		return 'backblaze_b2';
	}

	/**
	 * Human name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'Backblaze B2';
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

		$bucket = $this->get_bucket();

		if ( empty( $bucket ) ) {
			return '';
		}

		$region = '' !== $this->credential( 'region' ) ? $this->credential( 'region' ) : 'us-west-004';

		return 'https://' . $bucket . '.s3.' . $region . '.backblazeb2.com';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.4
	 *
	 * @return array<string, string>
	 */
	protected function rclone_backend() {
		return array(
			'provider' => 'Other',
			'region'   => '' !== $this->credential( 'region' ) ? $this->credential( 'region' ) : 'us-west-004',
			'endpoint' => Helpers::normalize_url( $this->credential( 'endpoint' ) ),
		);
	}

	/**
	 * Build the S3 client configured for Backblaze B2.
	 *
	 * @return S3Client
	 */
	protected function create_client() {
		$endpoint = Helpers::normalize_url( $this->credential( 'endpoint' ) );
		$region   = '' !== $this->credential( 'region' ) ? $this->credential( 'region' ) : 'us-west-004';

		$config = array(
			'version'                     => 'latest',
			'use_aws_shared_config_files' => false,
			'region'                      => $region,
			'credentials'                 => array(
				'key'    => $this->credential( 'key' ),
				'secret' => $this->credential( 'secret' ),
			),
		);

		if ( '' !== $endpoint ) {
			$config['endpoint'] = $endpoint;
		}

		return new S3Client( $this->client_config( $config ) );
	}

	/**
	 * Credential field definitions for the admin UI.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function credential_fields() {
		return array(
			array(
				'name'        => 'key',
				'label'       => __( 'Application Key ID', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => __( 'Your Backblaze B2 Application Key ID', 'omnioffloader' ),
				'description' => '',
			),
			array(
				'name'        => 'secret',
				'label'       => __( 'Application Key', 'omnioffloader' ),
				'type'        => 'password',
				'placeholder' => __( 'Your Backblaze B2 Application Key', 'omnioffloader' ),
				'description' => '',
			),
			array(
				'name'        => 'endpoint',
				'label'       => __( 'S3 Endpoint URL', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => 'https://s3.us-west-004.backblazeb2.com',
				'description' => __( 'Endpoint from your Backblaze B2 Bucket Details.', 'omnioffloader' ),
			),
			array(
				'name'        => 'region',
				'label'       => __( 'Region', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => 'us-west-004',
				'description' => __( 'Region derived from your endpoint (e.g. us-west-004, eu-central-003). Default: us-west-004.', 'omnioffloader' ),
			),
			array(
				'name'        => 'bucket',
				'label'       => __( 'Bucket Name', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => __( 'Your Backblaze B2 bucket name', 'omnioffloader' ),
				'description' => '',
			),
			array(
				'name'        => 'domain',
				'label'       => __( 'Custom Domain / CDN URL (Optional)', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => 'https://media.yourdomain.com',
				'description' => __( 'Optional Cloudflare CDN or custom domain. Leave blank to use the S3 endpoint URL.', 'omnioffloader' ),
			),
		);
	}
}
