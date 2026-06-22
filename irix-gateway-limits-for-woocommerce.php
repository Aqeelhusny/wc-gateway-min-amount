<?php
/**
 * Plugin Name: Irix Gateway Limits for WooCommerce
 * Plugin URI:  https://irixsolutions.net
 * Description: Restrict WooCommerce payment gateways by minimum cart total. Configure per-gateway minimums from WooCommerce > Gateway Limits.
 * Version:     1.0.0
 * Author:      Aqeel Husny
 * Text Domain: irix-gateway-limits-for-woocommerce
 * License:         GPLv2 or later
 * License URI:      https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP:     8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 * WC tested up to:  9.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PGMA_VERSION',    '1.0.0' );
define( 'PGMA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PGMA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare HPOS compatibility so WooCommerce doesn't flag this plugin.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

final class PGMA_Gateway_Min_Amount {

	private static ?self $instance = null;

	public const OPTION_KEY = 'pgma_gateway_limits';

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
		add_action( 'admin_post_pgma_save',   [ $this, 'handle_save' ] );
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
		echo '<div class="notice notice-error is-dismissible"><p>'
			. esc_html__( 'Irix Gateway Limits for WooCommerce requires WooCommerce to be active.', 'irix-gateway-limits-for-woocommerce' )
			. '</p></div>';
	}

	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Gateway Limits', 'irix-gateway-limits-for-woocommerce' ),
			__( 'Gateway Limits', 'irix-gateway-limits-for-woocommerce' ),
			'manage_woocommerce',
			'pgma-settings',
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
		<div class="wrap pgma-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Payment Gateway Limits', 'irix-gateway-limits-for-woocommerce' ); ?></h1>
			<p class="pgma-description">
				<?php esc_html_e( 'Set a minimum cart subtotal for each gateway. Leave blank (or 0) for no minimum. Only gateways enabled inside WooCommerce → Payments are shown here.', 'irix-gateway-limits-for-woocommerce' ); ?>
			</p>

			<?php if ( isset( $_GET['updated'] ) && current_user_can( 'manage_woocommerce' ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved successfully.', 'irix-gateway-limits-for-woocommerce' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'pgma_save_settings', 'pgma_nonce' ); ?>
				<input type="hidden" name="action" value="pgma_save">

				<table class="wp-list-table widefat fixed striped pgma-table">
					<thead>
						<tr>
							<th class="col-gateway"><?php esc_html_e( 'Payment Gateway', 'irix-gateway-limits-for-woocommerce' ); ?></th>
							<th class="col-id"><?php esc_html_e( 'Gateway ID', 'irix-gateway-limits-for-woocommerce' ); ?></th>
							<th class="col-status"><?php esc_html_e( 'Status', 'irix-gateway-limits-for-woocommerce' ); ?></th>
							<th class="col-min"><?php esc_html_e( 'Minimum Cart Amount', 'irix-gateway-limits-for-woocommerce' ); ?></th>
							<th class="col-notice"><?php esc_html_e( 'Customer Notice', 'irix-gateway-limits-for-woocommerce' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $gateways ) ) : ?>
							<tr>
								<td colspan="5">
									<?php esc_html_e( 'No payment gateways are registered. Please configure gateways in WooCommerce → Payments.', 'irix-gateway-limits-for-woocommerce' ); ?>
								</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $gateways as $id => $gateway ) :
								$min    = $limits[ $id ]['min']    ?? '';
								$notice = $limits[ $id ]['notice'] ?? '';
								$active = ( $gateway->get_option( 'enabled' ) === 'yes' );
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
										<span class="pgma-badge pgma-badge--active"><?php esc_html_e( 'Enabled', 'irix-gateway-limits-for-woocommerce' ); ?></span>
									<?php else : ?>
										<span class="pgma-badge pgma-badge--inactive"><?php esc_html_e( 'Disabled', 'irix-gateway-limits-for-woocommerce' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="col-min">
									<div class="pgma-amount-wrap">
										<span class="pgma-currency"><?php echo esc_html( $currency ); ?></span>
										<input
											type="number"
											name="pgma_limits[<?php echo esc_attr( $id ); ?>][min]"
											value="<?php echo esc_attr( $min ); ?>"
											min="0"
											step="1"
											placeholder="0"
											class="pgma-input-amount small-text"
										>
									</div>
								</td>
								<td class="col-notice">
									<input
										type="text"
										name="pgma_limits[<?php echo esc_attr( $id ); ?>][notice]"
										value="<?php echo esc_attr( $notice ); ?>"
										placeholder="<?php esc_attr_e( 'Minimum order is {min} for this method.', 'irix-gateway-limits-for-woocommerce' ); ?>"
										class="pgma-input-notice regular-text"
									>
									<p class="description">
										<?php esc_html_e( 'Use {min} to insert the formatted minimum amount.', 'irix-gateway-limits-for-woocommerce' ); ?>
									</p>
								</td>
							</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<p class="submit">
					<?php submit_button( __( 'Save Settings', 'irix-gateway-limits-for-woocommerce' ), 'primary', 'submit', false ); ?>
				</p>
			</form>
		</div>
		<?php
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'irix-gateway-limits-for-woocommerce' ), 403 );
		}

		check_admin_referer( 'pgma_save_settings', 'pgma_nonce' );

		$raw   = isset( $_POST['pgma_limits'] ) ? (array) $_POST['pgma_limits'] : []; // phpcs:ignore
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
			add_query_arg( [ 'page' => 'pgma-settings', 'updated' => '1' ], admin_url( 'admin.php' ) )
		);
		exit;
	}

	public function enqueue_admin_assets( string $hook ): void {
		if ( $hook !== 'woocommerce_page_pgma-settings' ) {
			return;
		}
		wp_enqueue_style(
			'pgma-admin',
			PGMA_PLUGIN_URL . 'assets/admin.css',
			[],
			PGMA_VERSION
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
					'<strong>%1$s</strong> ' . esc_html__( 'requires a minimum cart total of %2$s.', 'irix-gateway-limits-for-woocommerce' ),
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
	 * Enforce gateway minimums in the Blocks / Store API checkout flow.
	 *
	 * The hook fires before payment processing. We read the payment method
	 * the customer chose from the order (already set by this point), check only
	 * that one gateway, and throw RouteException to hard-block the request with
	 * an HTTP 400 if the cart total is below the configured minimum.
	 *
	 * wc_add_notice() is intentionally NOT used here — it queues a UI message
	 * but does not prevent the order from being placed.
	 *
	 * @param \WC_Order                                                   $order
	 * @param \Automattic\WooCommerce\StoreApi\Routes\V1\CartItems|object $request
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException
	 */
	public function blocks_checkout_notices( $order, $request ): void {
		if ( ! WC()->cart ) {
			return;
		}

		$payment_method = $order->get_payment_method();
		if ( ! $payment_method ) {
			return;
		}

		$limits = (array) get_option( self::OPTION_KEY, [] );
		if ( ! isset( $limits[ $payment_method ] ) ) {
			return;
		}

		$config = $limits[ $payment_method ];
		$min    = isset( $config['min'] ) ? (float) $config['min'] : 0;
		if ( $min <= 0 ) {
			return;
		}

		$cart_total = (float) WC()->cart->get_cart_contents_total();
		if ( $cart_total >= $min ) {
			return;
		}

		// Build the human-readable message.
		$gateways      = $this->all_registered_gateways();
		$title         = isset( $gateways[ $payment_method ] )
			? $gateways[ $payment_method ]->get_title()
			: $payment_method;
		$min_formatted = wc_price( $min );

		if ( ! empty( $config['notice'] ) ) {
			$message = str_replace( '{min}', wp_strip_all_tags( $min_formatted ), $config['notice'] );
		} else {
			$message = sprintf(
				/* translators: 1: gateway name, 2: formatted minimum amount */
				__( '%1$s requires a minimum cart total of %2$s.', 'irix-gateway-limits-for-woocommerce' ),
				$title,
				wp_strip_all_tags( $min_formatted )
			);
		}

		// Throw RouteException — this returns HTTP 400 and surfaces the message
		// in the Blocks checkout UI, and genuinely prevents order placement.
		if ( class_exists( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'pgma_gateway_restricted',
				esc_html( $message ),
				400
			);
		}

		// Fallback for environments where the Store API exception class is absent
		// (e.g. very old WC Blocks). This won't block, but at least informs the user.
		wc_add_notice( esc_html( $message ), 'error' );
	}

	// ─── Helpers ──────────────────────────────────────────────────────────────

	/** Returns all registered gateways (enabled + disabled). */
	private function all_registered_gateways(): array {
		$wc_gateways = WC()->payment_gateways();
		return $wc_gateways ? $wc_gateways->payment_gateways() : [];
	}
}

/** Singleton accessor */
function pgma(): PGMA_Gateway_Min_Amount {
	return PGMA_Gateway_Min_Amount::instance();
}
pgma();

/** Clean up on uninstall */
register_uninstall_hook( __FILE__, 'pgma_uninstall' );
function pgma_uninstall(): void {
	delete_option( PGMA_Gateway_Min_Amount::OPTION_KEY );
}
