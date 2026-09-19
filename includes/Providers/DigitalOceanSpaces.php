<?php
/**
 * DigitalOcean Spaces provider.
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
 * Class DigitalOceanSpaces
 *
 * @since 0.1.1
 */
class DigitalOceanSpaces extends AbstractS3Provider {

	/**
	 * Machine key.
	 *
	 * @return string
	 */
	public function get_key() {
		return 'digitalocean_spaces';
	}

	/**
	 * Human name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'DigitalOcean Spaces';
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

		$endpoint = Helpers::normalize_url( $this->credential( 'endpoint' ) );

		if ( '' !== $endpoint ) {
			$host = (string) wp_parse_url( $endpoint, PHP_URL_HOST );
			if ( '' !== $host ) {
				return 'https://' . $bucket . '.' . $host;
			}
		}

		$region = '' !== $this->credential( 'region' ) ? $this->credential( 'region' ) : 'nyc3';

		return 'https://' . $bucket . '.' . $region . '.digitaloceanspaces.com';
	}

	/**
	 * Build the S3 client configured for DigitalOcean Spaces.
	 *
	 * @return S3Client
	 */
	protected function create_client() {
		$endpoint = Helpers::normalize_url( $this->credential( 'endpoint' ) );
		$region   = '' !== $this->credential( 'region' ) ? $this->credential( 'region' ) : 'us-east-1';

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
				'name'        => 'key',
				'label'       => __( 'Spaces Access Key', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => __( 'Your DigitalOcean Spaces Access Key', 'omnioffloader' ),
				'description' => '',
			),
			array(
				'name'        => 'secret',
				'label'       => __( 'Spaces Secret Key', 'omnioffloader' ),
				'type'        => 'password',
				'placeholder' => __( 'Your DigitalOcean Spaces Secret Key', 'omnioffloader' ),
				'description' => '',
			),
			array(
				'name'        => 'endpoint',
				'label'       => __( 'Endpoint URL', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => 'https://nyc3.digitaloceanspaces.com',
				'description' => __( 'Endpoint URL for your Space (e.g. https://nyc3.digitaloceanspaces.com, https://ams3.digitaloceanspaces.com).', 'omnioffloader' ),
			),
			array(
				'name'        => 'region',
				'label'       => __( 'Region', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => 'nyc3',
				'description' => __( 'Region of your Space (e.g. nyc3, ams3, sgp1, sfo3).', 'omnioffloader' ),
			),
			array(
				'name'        => 'bucket',
				'label'       => __( 'Space / Bucket Name', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => __( 'Your Space name', 'omnioffloader' ),
				'description' => '',
			),
			array(
				'name'        => 'domain',
				'label'       => __( 'Custom Domain / Spaces CDN URL (Optional)', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => 'https://media.yourdomain.com',
				'description' => __( 'DigitalOcean CDN endpoint (e.g. https://your-space.nyc3.cdn.digitaloceanspaces.com) or custom subdomain.', 'omnioffloader' ),
			),
		);
	}
}
