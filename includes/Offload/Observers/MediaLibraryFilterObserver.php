<?php
/**
 * "Offloaded / Local" filter dropdown in the media library.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Offload\Observers;

use OmniOffloader\Offload\ObserverInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MediaLibraryFilterObserver
 */
class MediaLibraryFilterObserver implements ObserverInterface {

	/**
	 * Query-arg name for the filter.
	 */
	private const QUERY_ARG = 'omnioffloader_filter';

	/**
	 * Register hooks (admin list mode).
	 *
	 * @return void
	 */
	public function register() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'restrict_manage_posts', array( $this, 'render_dropdown' ) );
		add_action( 'pre_get_posts', array( $this, 'apply_filter' ) );
	}

	/**
	 * Render the dropdown on the media list screen.
	 *
	 * @param string $post_type Current post type.
	 * @return void
	 */
	public function render_dropdown( $post_type ) {
		if ( 'attachment' !== $post_type ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display state.
		$current = isset( $_GET[ self::QUERY_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_ARG ] ) ) : '';

		?>
		<select name="<?php echo esc_attr( self::QUERY_ARG ); ?>">
			<option value=""><?php esc_html_e( 'All offload states', 'omnioffloader' ); ?></option>
			<option value="offloaded" <?php selected( 'offloaded', $current ); ?>><?php esc_html_e( 'Offloaded', 'omnioffloader' ); ?></option>
			<option value="local" <?php selected( 'local', $current ); ?>><?php esc_html_e( 'Local only', 'omnioffloader' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Apply the filter to the media list query.
	 *
	 * @param \WP_Query $query Current query.
	 * @return void
	 */
	public function apply_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'attachment' !== $query->get( 'post_type' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$current = isset( $_GET[ self::QUERY_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_ARG ] ) ) : '';

		if ( 'offloaded' === $current ) {
			$query->set(
				'meta_query',
				array(
					array(
						'key'   => 'omnioffloader_offloaded',
						'value' => '1',
					),
				)
			);
		} elseif ( 'local' === $current ) {
			$query->set(
				'meta_query',
				array(
					array(
						'key'     => 'omnioffloader_offloaded',
						'compare' => 'NOT EXISTS',
					),
				)
			);
		}
	}
}
