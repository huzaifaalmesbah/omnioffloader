<?php
/**
 * Cloudflare R2 provider.
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
 * Class CloudflareR2
 */
class CloudflareR2 extends AbstractS3Provider {

	/**
	 * Machine key.
	 *
	 * @return string
	 */
	public function get_key() {
		return 'cloudflare_r2';
	}

	/**
	 * Human name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'Cloudflare R2';
	}

	/**
	 * Build the S3 client configured for R2.
	 *
	 * R2 requires the account endpoint and accepts region "auto".
	 * No ACLs: public access is via a connected custom domain.
	 *
	 * @return S3Client
	 */
	protected function create_client() {
		$endpoint = Helpers::normalize_url( $this->credential( 'endpoint' ) );

		return new S3Client(
			array(
				'version'                     => 'latest',
				'use_aws_shared_config_files' => false,
				'endpoint'                    => $endpoint,
				'region'                      => 'auto',
				'credentials'                 => array(
					'key'    => $this->credential( 'key' ),
					'secret' => $this->credential( 'secret' ),
				),
			)
		);
	}

	/**
	 * Credential field definitions for the React admin UI.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function credential_fields() {
		return array(
			array(
				'name'        => 'key',
				'label'       => __( 'Access Key ID', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => __( 'Your R2 Access Key ID', 'omnioffloader' ),
				'description' => '',
			),
			array(
				'name'        => 'secret',
				'label'       => __( 'Secret Access Key', 'omnioffloader' ),
				'type'        => 'password',
				'placeholder' => __( 'Your R2 Secret Access Key', 'omnioffloader' ),
				'description' => '',
			),
			array(
				'name'        => 'endpoint',
				'label'       => __( 'Endpoint URL', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => 'https://your-account-id.r2.cloudflarestorage.com',
				'description' => __( 'Find it in Cloudflare Dashboard → R2 → your bucket → Settings.', 'omnioffloader' ),
			),
			array(
				'name'        => 'bucket',
				'label'       => __( 'Bucket Name', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => __( 'Your R2 bucket name', 'omnioffloader' ),
				'description' => '',
			),
			array(
				'name'        => 'domain',
				'label'       => __( 'Custom Domain (CDN URL)', 'omnioffloader' ),
				'type'        => 'text',
				'placeholder' => 'https://media.yourdomain.com',
				'description' => __( 'Required for serving files publicly — connect a custom domain or public r2.dev URL to the bucket.', 'omnioffloader' ),
			),
		);
	}
}
