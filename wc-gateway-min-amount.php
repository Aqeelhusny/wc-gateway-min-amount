<?php
/**
 * Plugin Name: WC Gateway Minimum Amount
 * Plugin URI:  https://excellink.lk
 * Description: Restrict WooCommerce payment gateways by minimum cart total. Configure per-gateway minimums from WooCommerce > Gateway Limits.
 * Version:     1.0.0
 * Author:      Aqeel Husny
 * Text Domain: wcgma
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
		add_action( 'woocommerce_before_checkout_form',       [ $this, 'print_restriction_notices' ] );
	}

	// ─── Admin ────────────────────────────────────────────────────────────────

	public function notice_woocommerce_missing(): void {
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'WC Gateway Minimum Amount requires WooCommerce to be active.', 'wcgma' )
			. '</p></div>';
	}

	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Gateway Limits', 'wcgma' ),
			__( 'Gateway Limits', 'wcgma' ),
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
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Payment Gateway Limits', 'wcgma' ); ?></h1>
			<p class="wcgma-description">
				<?php esc_html_e( 'Set a minimum cart subtotal for each gateway. Leave blank (or 0) for no minimum. Only gateways enabled inside WooCommerce → Payments are shown here.', 'wcgma' ); ?>
			</p>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved successfully.', 'wcgma' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wcgma_save_settings', 'wcgma_nonce' ); ?>
				<input type="hidden" name="action" value="wcgma_save">

				<table class="wp-list-table widefat fixed striped wcgma-table">
					<thead>
						<tr>
							<th class="col-gateway"><?php esc_html_e( 'Payment Gateway', 'wcgma' ); ?></th>
							<th class="col-id"><?php esc_html_e( 'Gateway ID', 'wcgma' ); ?></th>
							<th class="col-status"><?php esc_html_e( 'Status', 'wcgma' ); ?></th>
							<th class="col-min"><?php esc_html_e( 'Minimum Cart Amount', 'wcgma' ); ?></th>
							<th class="col-notice"><?php esc_html_e( 'Customer Notice', 'wcgma' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $gateways ) ) : ?>
							<tr>
								<td colspan="5">
									<?php esc_html_e( 'No payment gateways are registered. Please configure gateways in WooCommerce → Payments.', 'wcgma' ); ?>
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
										<span class="wcgma-badge wcgma-badge--active"><?php esc_html_e( 'Enabled', 'wcgma' ); ?></span>
									<?php else : ?>
										<span class="wcgma-badge wcgma-badge--inactive"><?php esc_html_e( 'Disabled', 'wcgma' ); ?></span>
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
										placeholder="<?php esc_attr_e( 'Minimum order is {min} for this method.', 'wcgma' ); ?>"
										class="wcgma-input-notice regular-text"
									>
									<p class="description">
										<?php esc_html_e( 'Use {min} to insert the formatted minimum amount.', 'wcgma' ); ?>
									</p>
								</td>
							</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<p class="submit">
					<?php submit_button( __( 'Save Settings', 'wcgma' ), 'primary', 'submit', false ); ?>
				</p>
			</form>
		</div>
		<?php
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'wcgma' ), 403 );
		}

		check_admin_referer( 'wcgma_save_settings', 'wcgma_nonce' );

		$raw   = isset( $_POST['wcgma_limits'] ) ? (array) $_POST['wcgma_limits'] : []; // phpcs:ignore
		$clean = [];

		foreach ( $raw as $gateway_id => $values ) {
			$gateway_id = sanitize_key( $gateway_id );
			if ( empty( $gateway_id ) ) {
				continue;
			}

			$min    = isset( $values['min'] ) ? wc_format_decimal( sanitize_text_field( $values['min'] ) ) : '';
			$notice = isset( $values['notice'] ) ? sanitize_text_field( $values['notice'] ) : '';

			// Only persist gateways that actually have a minimum configured
			if ( $min !== '' && (float) $min > 0 ) {
				$clean[ $gateway_id ]['min'] = $min;
			}
			if ( ! empty( $notice ) ) {
				$clean[ $gateway_id ]['notice'] = $notice;
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

		$cart_total = (float) WC()->cart->get_subtotal();

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
		$cart_total = (float) WC()->cart->get_subtotal();
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
				$messages[] = str_replace( '{min}', $min_formatted, esc_html( $config['notice'] ) );
			} else {
				$messages[] = sprintf(
					/* translators: 1: gateway name, 2: formatted minimum amount */
					__( '<strong>%1$s</strong> requires a minimum cart total of %2$s.', 'wcgma' ),
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
