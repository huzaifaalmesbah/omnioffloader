<?php
/**
 * Wasabi cloud storage provider.
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
 * Class Wasabi
 *
 * @since 0.1.1
 */
class Wasabi extends AbstractS3Provider {

	/**
	 * Machine key.
	 *
	 * @return string
	 */
	public function get_key() {
		return 'wasabi';
	}

	/**
	 * Human name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'Wasabi';
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

		$region = '' !== $this->credential( 'region' ) ? $this->credential( 'region' ) : 'us-east-1';
		$prefix = 'us-east-1' === $region ? '' : $region . '.';

		return 'https://s3.' . $prefix . 'wasabisys.com/' . $bucket;
	}

	/**
	 * Build the S3 client configured for Wasabi.
	 *
	 * @return S3Client
	 */
	protected function create_client() {
		$region   = '' !== $this->credential( 'region' ) ? $this->credential( 'region' ) : 'us-east-1';
		$prefix   = 'us-east-1' === $region ? '' : $region . '.';
		$endpoint = 'https://s3.' . $prefix . 'wasabisys.com';

		$config = array(
			'version'                     => 'latest',
			'use_aws_shared_config_files' => false,
			'endpoint'                    => $endpoint,
			'region'                      => $region,
			'use_path_style_endpoint'     => true,
			'credentials'                 => array(
				'key'    => $this->credential( 'key' ),
				'secret' => $this->credential( 'secret' ),
			),
		);

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
				'label'       => __( 'Access Key ID', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => __( 'Your Wasabi Access Key', 'omnioffloader' ),
				'description' => '',
			),
			array(
				'name'        => 'secret',
				'label'       => __( 'Secret Access Key', 'omnioffloader' ),
				'type'        => 'password',
				'placeholder' => __( 'Your Wasabi Secret Key', 'omnioffloader' ),
				'description' => '',
			),
			array(
				'name'        => 'bucket',
				'label'       => __( 'Bucket Name', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => __( 'Your Wasabi bucket name', 'omnioffloader' ),
				'description' => '',
			),
			array(
				'name'        => 'region',
				'label'       => __( 'Region', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => 'us-east-1',
				'description' => __( 'Wasabi region (e.g. us-east-1, us-central-1, eu-central-1, ap-northeast-1). Default: us-east-1.', 'omnioffloader' ),
			),
			array(
				'name'        => 'domain',
				'label'       => __( 'Custom Domain / CDN URL (Optional)', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => 'https://media.yourdomain.com',
				'description' => __( 'Optional custom domain or CDN mapped to your Wasabi bucket.', 'omnioffloader' ),
			),
		);
	}
}
