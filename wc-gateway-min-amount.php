<?php
/**
 * Plugin Name: WC Gateway Minimum Amount
 * Plugin URI:  https://irixsolutions.net
 * Description: Restrict WooCommerce payment gateways by minimum cart total. Configure per-gateway minimums from WooCommerce > Gateway Limits.
 * Version:     1.0.0
 * Author:      Aqeel Husny
 * Text Domain: wc-gateway-min-amount
 * License:      GPL v2 or later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 * WC tested up to: 9.x
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WCGMA_VERSION',    '1.0.0' );
define( 'WCGMA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCGMA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare HPOS compatibility so WooCommerce doesn't flag this plugin.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- private plugin, not distributed via WP.org
final class WC_Gateway_Min_Amount {

	private static ?self $instance = null;

	public const OPTION_KEY = 'wcgma_gateway_limits';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', [ $this, 'init' ] );
	}

	public function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', [ $this, 'notice_woocommerce_missing' ] );
			return;
		}

		// Admin
		add_action( 'admin_menu',              [ $this, 'register_menu' ] );
		add_action( 'admin_post_wcgma_save',   [ $this, 'handle_save' ] );
		add_action( 'admin_enqueue_scripts',   [ $this, 'enqueue_admin_assets' ] );

		// Frontend — filter available gateways & inform the customer
		add_filter( 'woocommerce_available_payment_gateways', [ $this, 'filter_gateways' ] );

		// Classic checkout notice
		add_action( 'woocommerce_before_checkout_form', [ $this, 'print_restriction_notices' ] );

		// Blocks checkout notice — wc_add_notice() enqueues into the Store API notice buffer
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'blocks_checkout_notices' ], 10, 2 );
	}

	// ─── Admin ────────────────────────────────────────────────────────────────

	public function notice_woocommerce_missing(): void {
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'WC Gateway Minimum Amount requires WooCommerce to be active.', 'wc-gateway-min-amount' )
			. '</p></div>';
	}

	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Gateway Limits', 'wc-gateway-min-amount' ),
			__( 'Gateway Limits', 'wc-gateway-min-amount' ),
			'manage_woocommerce',
			'wcgma-settings',
			[ $this, 'render_settings_page' ]
		);
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$gateways = $this->all_registered_gateways();
		$limits   = (array) get_option( self::OPTION_KEY, [] );
		$currency = get_woocommerce_currency_symbol();
		?>
		<div class="wrap wcgma-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Payment Gateway Limits', 'wc-gateway-min-amount' ); ?></h1>
			<p class="wcgma-description">
				<?php esc_html_e( 'Set a minimum cart subtotal for each gateway. Leave blank (or 0) for no minimum. Only gateways enabled inside WooCommerce → Payments are shown here.', 'wc-gateway-min-amount' ); ?>
			</p>

			<?php if ( isset( $_GET['updated'] ) && current_user_can( 'manage_woocommerce' ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved successfully.', 'wc-gateway-min-amount' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wcgma_save_settings', 'wcgma_nonce' ); ?>
				<input type="hidden" name="action" value="wcgma_save">

				<table class="wp-list-table widefat fixed striped wcgma-table">
					<thead>
						<tr>
							<th class="col-gateway"><?php esc_html_e( 'Payment Gateway', 'wc-gateway-min-amount' ); ?></th>
							<th class="col-id"><?php esc_html_e( 'Gateway ID', 'wc-gateway-min-amount' ); ?></th>
							<th class="col-status"><?php esc_html_e( 'Status', 'wc-gateway-min-amount' ); ?></th>
							<th class="col-min"><?php esc_html_e( 'Minimum Cart Amount', 'wc-gateway-min-amount' ); ?></th>
							<th class="col-notice"><?php esc_html_e( 'Customer Notice', 'wc-gateway-min-amount' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $gateways ) ) : ?>
							<tr>
								<td colspan="5">
									<?php esc_html_e( 'No payment gateways are registered. Please configure gateways in WooCommerce → Payments.', 'wc-gateway-min-amount' ); ?>
								</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $gateways as $id => $gateway ) :
								$min    = $limits[ $id ]['min']    ?? '';
								$notice = $limits[ $id ]['notice'] ?? '';
								$active = ( $gateway->enabled === 'yes' );
							?>
							<tr>
								<td class="col-gateway">
									<strong><?php echo esc_html( $gateway->get_method_title() ?: $gateway->get_title() ); ?></strong>
								</td>
								<td class="col-id">
									<code><?php echo esc_html( $id ); ?></code>
								</td>
								<td class="col-status">
									<?php if ( $active ) : ?>
										<span class="wcgma-badge wcgma-badge--active"><?php esc_html_e( 'Enabled', 'wc-gateway-min-amount' ); ?></span>
									<?php else : ?>
										<span class="wcgma-badge wcgma-badge--inactive"><?php esc_html_e( 'Disabled', 'wc-gateway-min-amount' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="col-min">
									<div class="wcgma-amount-wrap">
										<span class="wcgma-currency"><?php echo esc_html( $currency ); ?></span>
										<input
											type="number"
											name="wcgma_limits[<?php echo esc_attr( $id ); ?>][min]"
											value="<?php echo esc_attr( $min ); ?>"
											min="0"
											step="1"
											placeholder="0"
											class="wcgma-input-amount small-text"
										>
									</div>
								</td>
								<td class="col-notice">
									<input
										type="text"
										name="wcgma_limits[<?php echo esc_attr( $id ); ?>][notice]"
										value="<?php echo esc_attr( $notice ); ?>"
										placeholder="<?php esc_attr_e( 'Minimum order is {min} for this method.', 'wc-gateway-min-amount' ); ?>"
										class="wcgma-input-notice regular-text"
									>
									<p class="description">
										<?php esc_html_e( 'Use {min} to insert the formatted minimum amount.', 'wc-gateway-min-amount' ); ?>
									</p>
								</td>
							</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<p class="submit">
					<?php submit_button( __( 'Save Settings', 'wc-gateway-min-amount' ), 'primary', 'submit', false ); ?>
				</p>
			</form>
		</div>
		<?php
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'wc-gateway-min-amount' ), 403 );
		}

		check_admin_referer( 'wcgma_save_settings', 'wcgma_nonce' );

		$raw   = isset( $_POST['wcgma_limits'] ) ? (array) $_POST['wcgma_limits'] : []; // phpcs:ignore
		$clean = [];

		foreach ( $raw as $gateway_id => $values ) {
			$gateway_id = sanitize_key( $gateway_id );
			if ( empty( $gateway_id ) || ! is_array( $values ) ) {
				continue;
			}

			$min    = isset( $values['min'] ) ? wc_format_decimal( $values['min'] ) : '';
			$notice = isset( $values['notice'] ) ? sanitize_text_field( $values['notice'] ) : '';

			// Only persist gateways that have a meaningful minimum configured.
			// Tie notice to the same condition — an orphaned notice (no min) would never display.
			if ( $min !== '' && (float) $min > 0 ) {
				$clean[ $gateway_id ]['min'] = $min;
				if ( ! empty( $notice ) ) {
					$clean[ $gateway_id ]['notice'] = $notice;
				}
			}
		}

		update_option( self::OPTION_KEY, $clean );

		wp_safe_redirect(
			add_query_arg( [ 'page' => 'wcgma-settings', 'updated' => '1' ], admin_url( 'admin.php' ) )
		);
		exit;
	}

	public function enqueue_admin_assets( string $hook ): void {
		if ( $hook !== 'woocommerce_page_wcgma-settings' ) {
			return;
		}
		wp_enqueue_style(
			'wcgma-admin',
			WCGMA_PLUGIN_URL . 'assets/admin.css',
			[],
			WCGMA_VERSION
		);
	}

	// ─── Frontend ─────────────────────────────────────────────────────────────

	/**
	 * Remove gateways that don't meet the configured minimum cart subtotal.
	 *
	 * @param  WC_Payment_Gateway[] $gateways
	 * @return WC_Payment_Gateway[]
	 */
	public function filter_gateways( array $gateways ): array {
		if ( is_admin() || ! WC()->cart ) {
			return $gateways;
		}

		$limits = (array) get_option( self::OPTION_KEY, [] );
		if ( empty( $limits ) ) {
			return $gateways;
		}

		// get_cart_contents_total() is post-coupon, ex-tax — the right basis for a minimum-order check.
		$cart_total = (float) WC()->cart->get_cart_contents_total();

		foreach ( $gateways as $id => $gateway ) {
			if ( ! isset( $limits[ $id ]['min'] ) ) {
				continue;
			}
			$min = (float) $limits[ $id ]['min'];
			if ( $min > 0 && $cart_total < $min ) {
				unset( $gateways[ $id ] );
			}
		}

		return $gateways;
	}

	/**
	 * Show a single notice listing all restricted gateways and their minimums.
	 */
	public function print_restriction_notices(): void {
		if ( ! WC()->cart ) {
			return;
		}

		$limits     = (array) get_option( self::OPTION_KEY, [] );
		$cart_total = (float) WC()->cart->get_cart_contents_total();
		$gateways   = $this->all_registered_gateways();
		$messages   = [];

		foreach ( $limits as $id => $config ) {
			$min = isset( $config['min'] ) ? (float) $config['min'] : 0;
			if ( $min <= 0 || $cart_total >= $min ) {
				continue;
			}
			if ( ! isset( $gateways[ $id ] ) ) {
				continue;
			}

			$title         = esc_html( $gateways[ $id ]->get_title() );
			$min_formatted = wp_kses_post( wc_price( $min ) );

			if ( ! empty( $config['notice'] ) ) {
				// Substitute first, then sanitize the combined string (which contains HTML from wc_price).
				$messages[] = wp_kses_post( str_replace( '{min}', $min_formatted, $config['notice'] ) );
			} else {
				$messages[] = sprintf(
					/* translators: 1: gateway name, 2: formatted minimum amount */
					'<strong>%1$s</strong> ' . esc_html__( 'requires a minimum cart total of %2$s.', 'wc-gateway-min-amount' ),
					$title,
					$min_formatted
				);
			}
		}

		if ( empty( $messages ) ) {
			return;
		}

		$notice = implode( '<br>', $messages );
		wc_print_notice( $notice, 'notice' );
	}

	/**
	 * Surface restriction notices inside the Blocks / Store API checkout flow.
	 * `woocommerce_store_api_checkout_update_order_from_request` fires before
	 * payment processing, so wc_add_notice() messages reach the block UI.
	 *
	 * @param \WC_Order                                                    $order
	 * @param \Automattic\WooCommerce\StoreApi\Routes\V1\CartItems|object  $request
	 */
	public function blocks_checkout_notices( $order, $request ): void {
		if ( ! WC()->cart ) {
			return;
		}

		$limits     = (array) get_option( self::OPTION_KEY, [] );
		$cart_total = (float) WC()->cart->get_cart_contents_total();
		$gateways   = $this->all_registered_gateways();

		foreach ( $limits as $id => $config ) {
			$min = isset( $config['min'] ) ? (float) $config['min'] : 0;
			if ( $min <= 0 || $cart_total >= $min ) {
				continue;
			}
			if ( ! isset( $gateways[ $id ] ) ) {
				continue;
			}

			$title         = esc_html( $gateways[ $id ]->get_title() );
			$min_formatted = wp_kses_post( wc_price( $min ) );

			if ( ! empty( $config['notice'] ) ) {
				$message = wp_kses_post( str_replace( '{min}', $min_formatted, $config['notice'] ) );
			} else {
				$message = sprintf(
					/* translators: 1: gateway name, 2: formatted minimum amount */
					__( '%1$s requires a minimum cart total of %2$s.', 'wc-gateway-min-amount' ),
					$title,
					$min_formatted
				);
			}

			wc_add_notice( $message, 'notice' );
		}
	}

	// ─── Helpers ──────────────────────────────────────────────────────────────

	/** Returns all registered gateways (enabled + disabled). */
	private function all_registered_gateways(): array {
		$wc_gateways = WC()->payment_gateways();
		return $wc_gateways ? $wc_gateways->payment_gateways() : [];
	}
}

/** Singleton accessor */
function wcgma(): WC_Gateway_Min_Amount {
	return WC_Gateway_Min_Amount::instance();
}
wcgma();

/** Clean up on uninstall */
register_uninstall_hook( __FILE__, 'wcgma_uninstall' );
function wcgma_uninstall(): void {
	delete_option( WC_Gateway_Min_Amount::OPTION_KEY );
}
