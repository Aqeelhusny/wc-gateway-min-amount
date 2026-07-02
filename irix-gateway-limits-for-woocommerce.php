<?php
/**
 * Plugin Name: Irix Gateway Limits for WooCommerce
 * Plugin URI:  https://irixsolutions.net
 * Description: Restrict WooCommerce payment gateways by minimum cart total. Configure per-gateway minimums from WooCommerce > Gateway Limits.
 * Version:     1.3.2
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

define( 'PGMA_VERSION',    '1.3.2' );
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

	public const OPTION_KEY         = 'pgma_gateway_limits';
	public const RULES_OPTION_KEY   = 'pgma_gateway_rules';
	public const SETTINGS_OPTION_KEY = 'pgma_settings';

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
		add_action( 'admin_post_pgma_save_rules', [ $this, 'handle_save_rules' ] );
		add_action( 'admin_enqueue_scripts',   [ $this, 'enqueue_admin_assets' ] );

		// Frontend — filter available gateways & inform the customer
		add_filter( 'woocommerce_available_payment_gateways', [ $this, 'filter_gateways' ] );

		// Classic checkout + cart notices
		add_action( 'woocommerce_before_checkout_form', [ $this, 'print_restriction_notices' ] );
		add_action( 'woocommerce_before_cart',          [ $this, 'print_restriction_notices' ] );

		// Blocks cart/checkout have no PHP template hooks, so prepend the same
		// notice to the block's rendered output instead.
		add_filter( 'render_block', [ $this, 'prepend_blocks_restriction_notice' ], 10, 2 );

		// Blocks checkout notice — wc_add_notice() enqueues into the Store API notice buffer
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'blocks_checkout_notices' ], 10, 2 );

		// Conditional per-gateway fees/discounts
		add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_gateway_adjustment' ] );

		// Live-refresh totals when the customer switches gateways on the checkout page
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_assets' ] );

		// Blocks checkout never writes to the classic 'chosen_payment_method' session key
		// on selection — only at order placement — so we write it ourselves and have the
		// Store API hand back freshly recalculated cart totals in the same round trip.
		add_action( 'woocommerce_blocks_loaded', [ $this, 'register_store_api_gateway_sync' ] );
	}

	/**
	 * Register a Store API "cart extension" callback so the frontend can push the
	 * in-progress gateway selection to the server and get updated totals back in
	 * one request — no page refresh, no polling, no guessing at Blocks' internal
	 * Redux/resolver state.
	 *
	 * Flow: JS calls wc.blocksCheckout.extensionCartUpdate({ namespace: 'pgma-gateway-sync', data: { gateway } }),
	 * which POSTs to /wc/store/v1/cart/extensions. WooCommerce core runs the
	 * registered callback below, then recalculates the cart (firing
	 * woocommerce_cart_calculate_fees) and returns the updated cart schema, which
	 * the Blocks checkout store applies immediately.
	 */
	public function register_store_api_gateway_sync(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}

		woocommerce_store_api_register_update_callback( [
			'namespace' => 'pgma-gateway-sync',
			'callback'  => function ( $data ) {
				$gateway            = isset( $data['gateway'] ) ? sanitize_key( $data['gateway'] ) : '';
				$available_gateways = WC()->payment_gateways() ? WC()->payment_gateways()->get_available_payment_gateways() : [];

				if ( '' !== $gateway && isset( $available_gateways[ $gateway ] ) ) {
					WC()->session->set( 'chosen_payment_method', $gateway );
				}
			},
		] );
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

			<hr class="pgma-section-divider">

			<?php $this->render_rules_section( $gateways ); ?>
		</div>
		<?php
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'irix-gateway-limits-for-woocommerce' ), 403 );
		}

		check_admin_referer( 'pgma_save_settings', 'pgma_nonce' );

		$raw   = isset( $_POST['pgma_limits'] ) ? (array) wp_unslash( $_POST['pgma_limits'] ) : []; // phpcs:ignore
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
		wp_enqueue_script(
			'pgma-rules-admin',
			PGMA_PLUGIN_URL . 'assets/admin-rules.js',
			[],
			PGMA_VERSION,
			true
		);
	}

	/**
	 * Force the checkout totals to refresh the moment the customer switches
	 * gateways, so a fee/discount tied to the newly chosen gateway shows up
	 * immediately instead of only after the next unrelated cart change.
	 *
	 * Classic checkout is *supposed* to handle this natively via WooCommerce
	 * core's own 'update_checkout' trigger, but that can be broken by a
	 * conflicting gateway/theme script (confirmed on this install — see the
	 * active theme's checkout-helpers.php, which already works around the
	 * same conflict for its own payment-box toggle). So this script also
	 * triggers 'update_checkout' itself from a delegated listener, which is
	 * unaffected by whatever breaks core's own binding.
	 *
	 * Blocks checkout doesn't write the in-progress selection to the session
	 * at all — only at order placement — so it needs the officially supported
	 * wc.blocksCheckout.extensionCartUpdate() API (paired with
	 * register_store_api_gateway_sync() below) to round-trip the selection
	 * and get fresh totals back into the Blocks checkout store.
	 *
	 * Loaded on every checkout page load since either checkout type may be in
	 * use; each code path in the script no-ops if its checkout type isn't
	 * present (no jQuery → skip classic trigger; no wc.blocksCheckout → skip
	 * the Store API sync).
	 */
	public function enqueue_checkout_assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		$dependencies = [ 'jquery' ];
		if ( wp_script_is( 'wc-blocks-checkout', 'registered' ) ) {
			$dependencies[] = 'wc-blocks-checkout';
		}

		wp_enqueue_script(
			'pgma-checkout-live-fee',
			PGMA_PLUGIN_URL . 'assets/checkout-live-fee.js',
			$dependencies,
			PGMA_VERSION,
			true
		);

		$available_gateways = WC()->payment_gateways() ? WC()->payment_gateways()->get_available_payment_gateways() : [];

		wp_localize_script( 'pgma-checkout-live-fee', 'PGMA_CHECKOUT', [
			'namespace' => 'pgma-gateway-sync',
			'gateways'  => array_keys( $available_gateways ),
		] );
	}

	/**
	 * Render the "Discounts & Fees" section on the Gateway Limits page.
	 *
	 * Each gateway gets a repeatable list of conditional rules: a minimum cart
	 * total, whether the rule is a fee or a discount, a flat/percent value, and
	 * an optional cap (mainly useful for percentage discounts, e.g. "15% off,
	 * capped at 1000"). Only one rule per gateway is ever applied at checkout —
	 * see best_matching_rule() — and a coupon on the cart always suppresses any
	 * gateway rule, per the "offers don't combine" requirement.
	 *
	 * @param WC_Payment_Gateway[] $gateways
	 */
	private function render_rules_section( array $gateways ): void {
		$rules    = (array) get_option( self::RULES_OPTION_KEY, [] );
		$settings = (array) get_option( self::SETTINGS_OPTION_KEY, [] );
		$enabled  = ( $settings['rules_enabled'] ?? 'no' ) === 'yes';
		?>
		<h2><?php esc_html_e( 'Gateway Discounts & Fees', 'irix-gateway-limits-for-woocommerce' ); ?></h2>
		<p class="pgma-description">
			<?php esc_html_e( 'Apply a conditional fee or discount to a payment gateway based on the cart subtotal. Only the single best-matching rule for a gateway is ever applied, and any rule is skipped automatically if a coupon is already on the cart.', 'irix-gateway-limits-for-woocommerce' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'pgma_save_rules', 'pgma_rules_nonce' ); ?>
				<input type="hidden" name="action" value="pgma_save_rules">

				<label class="pgma-master-toggle">
					<input type="checkbox" name="pgma_rules_enabled" value="yes" <?php checked( $enabled ); ?>>
					<?php esc_html_e( 'Enable payment gateway discounts & fees', 'irix-gateway-limits-for-woocommerce' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Turn this off to stop applying any rules below without losing your configuration.', 'irix-gateway-limits-for-woocommerce' ); ?>
				</p>

				<?php if ( empty( $gateways ) ) : ?>
					<p><?php esc_html_e( 'No payment gateways are registered. Please configure gateways in WooCommerce → Payments.', 'irix-gateway-limits-for-woocommerce' ); ?></p>
				<?php else : ?>
					<?php foreach ( $gateways as $id => $gateway ) :
						$gateway_rules = $rules[ $id ] ?? [];
					?>
					<div class="pgma-rule-group" data-gateway="<?php echo esc_attr( $id ); ?>">
						<h3>
							<?php echo esc_html( $gateway->get_method_title() ?: $gateway->get_title() ); ?>
							<code><?php echo esc_html( $id ); ?></code>
						</h3>

						<div class="pgma-table-scroll">
							<table class="wp-list-table widefat striped pgma-rule-table">
								<thead>
									<tr>
										<th class="col-active"><?php esc_html_e( 'Active', 'irix-gateway-limits-for-woocommerce' ); ?></th>
										<th class="col-min-cart"><?php esc_html_e( 'Cart Total ≥', 'irix-gateway-limits-for-woocommerce' ); ?></th>
										<th class="col-kind"><?php esc_html_e( 'Kind', 'irix-gateway-limits-for-woocommerce' ); ?></th>
										<th class="col-type"><?php esc_html_e( 'Type', 'irix-gateway-limits-for-woocommerce' ); ?></th>
										<th class="col-value"><?php esc_html_e( 'Value', 'irix-gateway-limits-for-woocommerce' ); ?></th>
										<th class="col-cap"><?php esc_html_e( 'Cap', 'irix-gateway-limits-for-woocommerce' ); ?></th>
										<th class="col-label"><?php esc_html_e( 'Label', 'irix-gateway-limits-for-woocommerce' ); ?></th>
										<th class="col-taxable"><?php esc_html_e( 'Taxable', 'irix-gateway-limits-for-woocommerce' ); ?></th>
										<th class="col-remove"></th>
									</tr>
								</thead>
								<tbody class="pgma-rule-rows">
									<?php foreach ( $gateway_rules as $index => $rule ) :
										echo $this->render_rule_row( $id, $index, $rule ); // phpcs:ignore WordPress.Security.EscapeOutput
									endforeach; ?>
								</tbody>
							</table>
						</div>
						<button type="button" class="button pgma-add-rule"><?php esc_html_e( '+ Add rule', 'irix-gateway-limits-for-woocommerce' ); ?></button>
					</div>
					<?php endforeach; ?>

					<template id="pgma-rule-template">
						<table><tbody><?php echo $this->render_rule_row( '__GATEWAY__', '__INDEX__', [] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></tbody></table>
					</template>
				<?php endif; ?>

			<p class="submit">
				<?php submit_button( __( 'Save Settings', 'irix-gateway-limits-for-woocommerce' ), 'primary', 'submit', false ); ?>
			</p>
		</form>
		<?php
	}

	/** Renders a single rule row (also used for the JS clone template). */
	private function render_rule_row( string $gateway_id, $index, array $rule ): string {
		$name     = sprintf( 'pgma_rules[%s][%s]', $gateway_id, $index );
		$active   = ( $rule['active'] ?? 'yes' ) === 'yes';
		$min      = $rule['min_cart'] ?? '';
		$kind     = $rule['kind'] ?? 'discount';
		$type     = $rule['type'] ?? 'percent';
		$value    = $rule['value'] ?? '';
		$cap      = $rule['cap'] ?? '';
		$label    = $rule['label'] ?? '';
		$taxable  = ( $rule['taxable'] ?? '' ) === 'yes';
		$currency = get_woocommerce_currency_symbol();

		ob_start();
		?>
		<tr class="pgma-rule-row">
			<td class="col-active"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[active]" value="yes" <?php checked( $active ); ?>></td>
			<td class="col-min-cart">
				<div class="pgma-amount-wrap">
					<span class="pgma-currency"><?php echo esc_html( $currency ); ?></span>
					<input type="number" min="0" step="0.01" name="<?php echo esc_attr( $name ); ?>[min_cart]" value="<?php echo esc_attr( $min ); ?>" class="pgma-input-amount small-text">
				</div>
			</td>
			<td class="col-kind">
				<select name="<?php echo esc_attr( $name ); ?>[kind]">
					<option value="discount" <?php selected( $kind, 'discount' ); ?>><?php esc_html_e( 'Discount', 'irix-gateway-limits-for-woocommerce' ); ?></option>
					<option value="fee" <?php selected( $kind, 'fee' ); ?>><?php esc_html_e( 'Fee', 'irix-gateway-limits-for-woocommerce' ); ?></option>
				</select>
			</td>
			<td class="col-type">
				<select class="pgma-type-select" name="<?php echo esc_attr( $name ); ?>[type]">
					<option value="percent" <?php selected( $type, 'percent' ); ?>><?php esc_html_e( 'Percent', 'irix-gateway-limits-for-woocommerce' ); ?></option>
					<option value="fixed" <?php selected( $type, 'fixed' ); ?>><?php esc_html_e( 'Fixed', 'irix-gateway-limits-for-woocommerce' ); ?></option>
				</select>
			</td>
			<td class="col-value">
				<div class="pgma-amount-wrap">
					<span class="pgma-symbol" data-currency="<?php echo esc_attr( $currency ); ?>"><?php echo esc_html( $type === 'percent' ? '%' : $currency ); ?></span>
					<input type="number" min="0" step="0.01" name="<?php echo esc_attr( $name ); ?>[value]" value="<?php echo esc_attr( $value ); ?>" class="pgma-input-amount small-text">
				</div>
			</td>
			<td class="col-cap">
				<div class="pgma-amount-wrap">
					<span class="pgma-symbol" data-currency="<?php echo esc_attr( $currency ); ?>"><?php echo esc_html( $currency ); ?></span>
					<input type="number" min="0" step="0.01" name="<?php echo esc_attr( $name ); ?>[cap]" value="<?php echo esc_attr( $cap ); ?>" class="pgma-input-amount small-text" placeholder="<?php esc_attr_e( 'No cap', 'irix-gateway-limits-for-woocommerce' ); ?>">
				</div>
			</td>
			<td class="col-label"><input type="text" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $label ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Shown on the order as a line item', 'irix-gateway-limits-for-woocommerce' ); ?>"></td>
			<td class="col-taxable"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[taxable]" value="yes" <?php checked( $taxable ); ?>></td>
			<td class="col-remove"><button type="button" class="button-link-delete pgma-remove-rule"><?php esc_html_e( 'Remove', 'irix-gateway-limits-for-woocommerce' ); ?></button></td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	public function handle_save_rules(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'irix-gateway-limits-for-woocommerce' ), 403 );
		}

		check_admin_referer( 'pgma_save_rules', 'pgma_rules_nonce' );

		$settings                    = (array) get_option( self::SETTINGS_OPTION_KEY, [] );
		$settings['rules_enabled']   = isset( $_POST['pgma_rules_enabled'] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification
		update_option( self::SETTINGS_OPTION_KEY, $settings );

		$raw   = isset( $_POST['pgma_rules'] ) ? (array) wp_unslash( $_POST['pgma_rules'] ) : []; // phpcs:ignore
		$clean = [];

		foreach ( $raw as $gateway_id => $rows ) {
			$gateway_id = sanitize_key( $gateway_id );
			if ( empty( $gateway_id ) || ! is_array( $rows ) ) {
				continue;
			}

			$gateway_rules = [];
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$min_cart = isset( $row['min_cart'] ) ? wc_format_decimal( $row['min_cart'] ) : '';
				$value    = isset( $row['value'] ) ? wc_format_decimal( $row['value'] ) : '';

				// A rule without a condition or a value does nothing — drop it rather than store dead config.
				if ( $min_cart === '' || $value === '' || (float) $value <= 0 ) {
					continue;
				}

				$cap = isset( $row['cap'] ) ? wc_format_decimal( $row['cap'] ) : '';

				// A cap of 0 would zero out the rule; treat it as "no cap" instead.
				if ( $cap !== '' && (float) $cap <= 0 ) {
					$cap = '';
				}

				$gateway_rules[] = [
					'active'   => isset( $row['active'] ) ? 'yes' : 'no',
					'min_cart' => $min_cart,
					'kind'     => ( ( $row['kind'] ?? '' ) === 'fee' ) ? 'fee' : 'discount',
					'type'     => ( ( $row['type'] ?? '' ) === 'fixed' ) ? 'fixed' : 'percent',
					'value'    => $value,
					'cap'      => $cap,
					'label'    => isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '',
					'taxable'  => isset( $row['taxable'] ) ? 'yes' : 'no',
				];
			}

			if ( empty( $gateway_rules ) ) {
				continue;
			}

			// Sort ascending by threshold so admin display and best_matching_rule() agree on tier order.
			usort( $gateway_rules, fn( $a, $b ) => (float) $a['min_cart'] <=> (float) $b['min_cart'] );

			$clean[ $gateway_id ] = $gateway_rules;
		}

		update_option( self::RULES_OPTION_KEY, $clean );

		wp_safe_redirect(
			add_query_arg( [ 'page' => 'pgma-settings', 'updated' => '1' ], admin_url( 'admin.php' ) )
		);
		exit;
	}

	// ─── Frontend ─────────────────────────────────────────────────────────────

	/**
	 * Remove gateways that don't meet the configured minimum cart subtotal.
	 *
	 * @param  WC_Payment_Gateway[] $gateways
	 * @return WC_Payment_Gateway[]
	 */
	public function filter_gateways( array $gateways ): array {
		if ( is_admin() ) {
			return $gateways;
		}

		$limits = (array) get_option( self::OPTION_KEY, [] );
		if ( empty( $limits ) ) {
			return $gateways;
		}

		$cart_total = $this->minimum_check_basis();
		if ( null === $cart_total ) {
			return $gateways;
		}

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
	 * The amount gateway minimums are compared against, or null when there is
	 * nothing meaningful to compare — in which case callers must fail open
	 * (show the gateway) rather than block payment.
	 *
	 * On the order-pay endpoint the cart is typically empty and the amount
	 * actually being paid is the existing order's total, so use that. Note the
	 * order total includes tax while the cart basis is ex-tax; for a
	 * minimum-order check on an already-placed order that is the sensible
	 * (and only available) number.
	 */
	private function minimum_check_basis(): ?float {
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			$order_id = absint( get_query_var( 'order-pay' ) );
			$order    = $order_id ? wc_get_order( $order_id ) : false;
			return $order ? (float) $order->get_total() : null;
		}

		if ( ! WC()->cart ) {
			return null;
		}

		// get_cart_contents_total() is post-coupon, ex-tax — the right basis for a minimum-order check.
		return (float) WC()->cart->get_cart_contents_total();
	}

	/**
	 * Build the per-gateway "minimum not met" messages for the current cart.
	 * Each message is already escaped/kses-sanitized and safe to output.
	 *
	 * @return string[]
	 */
	private function restriction_messages(): array {
		$limits     = (array) get_option( self::OPTION_KEY, [] );
		$cart_total = $this->minimum_check_basis();
		if ( null === $cart_total ) {
			return [];
		}
		$gateways = $this->all_registered_gateways();
		$messages = [];

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

		return $messages;
	}

	/**
	 * Show a single notice listing all restricted gateways and their minimums.
	 */
	public function print_restriction_notices(): void {
		$messages = $this->restriction_messages();
		if ( empty( $messages ) ) {
			return;
		}

		wc_print_notice( implode( '<br>', $messages ), 'notice' );
	}

	/**
	 * Blocks cart/checkout equivalent of print_restriction_notices().
	 *
	 * The Cart and Checkout blocks render entirely client-side with no PHP
	 * template hooks, so without this a below-minimum gateway just silently
	 * disappears from the payment options. Prepend an info banner (using the
	 * Blocks notice markup so it matches native styling) to the block output.
	 *
	 * Rendered at page load: if the customer edits quantities inside the Cart
	 * block, the banner won't update until the next page render — acceptable
	 * for an informational message, and the gateway list itself always
	 * reflects the live total via filter_gateways().
	 */
	public function prepend_blocks_restriction_notice( $block_content, $block ) {
		$block_name = $block['blockName'] ?? '';
		if ( 'woocommerce/cart' !== $block_name && 'woocommerce/checkout' !== $block_name ) {
			return $block_content;
		}

		if ( is_admin() || ! WC()->cart ) {
			return $block_content;
		}

		$messages = $this->restriction_messages();
		if ( empty( $messages ) ) {
			return $block_content;
		}

		$banner = sprintf(
			'<div class="wc-block-components-notice-banner is-info pgma-blocks-notice" role="status" style="margin-bottom:1em">'
			. '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M12 3.2c-4.8 0-8.8 3.9-8.8 8.8 0 4.8 3.9 8.8 8.8 8.8 4.8 0 8.8-3.9 8.8-8.8 0-4.8-4-8.8-8.8-8.8zm0 16c-4 0-7.2-3.3-7.2-7.2C4.8 8 8 4.8 12 4.8s7.2 3.3 7.2 7.2c0 4-3.2 7.2-7.2 7.2zM11 17h2v-6h-2v6zm0-8h2V7h-2v2z"></path></svg>'
			. '<div class="wc-block-components-notice-banner__content">%s</div>'
			. '</div>',
			implode( '<br>', $messages )
		);

		return $banner . $block_content;
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

	/**
	 * Apply the single best-matching fee/discount rule for the chosen gateway.
	 *
	 * Only ever applies one rule per gateway (the highest threshold met), and
	 * skips entirely if a coupon is already on the cart — offers don't combine.
	 */
	public function apply_gateway_adjustment( WC_Cart $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		// Order-pay: the customer is paying an existing order — cart fees can't
		// attach to it, and the (usually empty) cart isn't what's being paid.
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			return;
		}

		$settings = (array) get_option( self::SETTINGS_OPTION_KEY, [] );
		if ( ( $settings['rules_enabled'] ?? 'no' ) !== 'yes' ) {
			return;
		}

		$chosen = WC()->session ? WC()->session->get( 'chosen_payment_method' ) : '';
		if ( ! $chosen ) {
			return;
		}

		// Coupons and gateway rules are mutually exclusive — a coupon on the cart always wins.
		if ( ! empty( $cart->get_applied_coupons() ) ) {
			return;
		}

		$rules = (array) get_option( self::RULES_OPTION_KEY, [] );
		if ( empty( $rules[ $chosen ] ) ) {
			return;
		}

		$rule = $this->best_matching_rule( $rules[ $chosen ], (float) $cart->get_subtotal() );
		if ( ! $rule ) {
			return;
		}

		$amount = $this->calculate_rule_amount( $rule, (float) $cart->get_subtotal() );
		if ( 0.0 === $amount ) {
			return;
		}

		$label = $rule['label'] !== '' ? $rule['label'] : __( 'Payment Method Adjustment', 'irix-gateway-limits-for-woocommerce' );
		$cart->add_fee( $label, $amount, $rule['taxable'] === 'yes' );
	}

	/**
	 * Pick the single applicable rule for a gateway: the highest cart-total
	 * threshold the cart currently meets. Rules are never stacked.
	 */
	private function best_matching_rule( array $rules, float $cart_total ): ?array {
		$best = null;

		foreach ( $rules as $rule ) {
			if ( ( $rule['active'] ?? 'yes' ) !== 'yes' ) {
				continue;
			}
			if ( $cart_total < (float) $rule['min_cart'] ) {
				continue;
			}
			if ( null === $best || (float) $rule['min_cart'] > (float) $best['min_cart'] ) {
				$best = $rule;
			}
		}

		return $best;
	}

	/** Signed adjustment amount (negative = discount, positive = fee), capped and clamped. */
	private function calculate_rule_amount( array $rule, float $base ): float {
		$value = (float) $rule['value'];
		$raw   = ( $rule['type'] === 'percent' ) ? ( $base * $value / 100 ) : $value;

		if ( $rule['cap'] !== '' && $raw > (float) $rule['cap'] ) {
			$raw = (float) $rule['cap'];
		}

		$amount = ( $rule['kind'] === 'discount' ) ? -$raw : $raw;

		// Never let a discount push the cart subtotal negative.
		return max( $amount, -$base );
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
	delete_option( PGMA_Gateway_Min_Amount::RULES_OPTION_KEY );
	delete_option( PGMA_Gateway_Min_Amount::SETTINGS_OPTION_KEY );
}
