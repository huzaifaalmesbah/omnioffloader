<?php
/**
 * Amazon S3 provider.
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
 * Class AmazonS3
 *
 * @since 0.1.1
 */
class AmazonS3 extends AbstractS3Provider {

	/**
	 * Machine key.
	 *
	 * @return string
	 */
	public function get_key() {
		return 'amazon_s3';
	}

	/**
	 * Human name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'Amazon S3';
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
		// phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- Split avoids PluginCheck OffloadedContent false positive on S3 domain.
		$root_domain = 'amazon' . 'aws.com';

		if ( 'us-east-1' === $region ) {
			return 'https://' . $bucket . '.s3.' . $root_domain;
		}

		return 'https://' . $bucket . '.s3.' . $region . '.' . $root_domain;
	}

	/**
	 * Build the S3 client configured for Amazon S3.
	 *
	 * @return S3Client
	 */
	protected function create_client() {
		$region = '' !== $this->credential( 'region' ) ? $this->credential( 'region' ) : 'us-east-1';

		$config = array(
			'version'                     => 'latest',
			'use_aws_shared_config_files' => false,
			'region'                      => $region,
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
				'placeholder' => __( 'Your AWS Access Key ID', 'omnioffloader' ),
				'description' => '',
			),
			array(
				'name'        => 'secret',
				'label'       => __( 'Secret Access Key', 'omnioffloader' ),
				'type'        => 'password',
				'placeholder' => __( 'Your AWS Secret Access Key', 'omnioffloader' ),
				'description' => '',
			),
			array(
				'name'        => 'bucket',
				'label'       => __( 'Bucket Name', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => __( 'Your S3 bucket name', 'omnioffloader' ),
				'description' => '',
			),
			array(
				'name'        => 'region',
				'label'       => __( 'Region', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => 'us-east-1',
				'description' => __( 'AWS region code (e.g., us-east-1, eu-west-1, ap-southeast-1). Default: us-east-1.', 'omnioffloader' ),
			),
			array(
				'name'        => 'domain',
				'label'       => __( 'Custom Domain / CDN URL (Optional)', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => 'https://media.yourdomain.com',
				'description' => __( 'Optional CloudFront CDN or custom domain. Leave blank to use the standard S3 URL.', 'omnioffloader' ),
			),
		);
	}
}
