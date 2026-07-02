( function () {
	'use strict';

	if ( ! window.PGMA_CHECKOUT ) {
		return;
	}

	var config = window.PGMA_CHECKOUT;

	/**
	 * Classic checkout: force the native totals refresh explicitly.
	 *
	 * WooCommerce core is supposed to do this itself when a payment_method
	 * radio changes, but on this site another gateway plugin's script
	 * interferes with core's own event binding (the active theme already
	 * works around the same conflict for its payment-box toggle — see
	 * checkout-helpers.php). Triggering 'update_checkout' ourselves from a
	 * delegated document-level listener sidesteps whatever is breaking core's
	 * handler, since that listener is confirmed to fire reliably here.
	 */
	function refreshClassicCheckout() {
		if ( ! window.jQuery ) {
			return;
		}
		window.jQuery( document.body ).trigger( 'update_checkout' );
	}

	/**
	 * Blocks checkout: push the selected gateway to the server via the Store
	 * API's cart extensions endpoint. WooCommerce runs our registered
	 * callback (sets the session's chosen_payment_method), recalculates the
	 * cart — which re-runs woocommerce_cart_calculate_fees — and returns the
	 * fresh totals, which extensionCartUpdate() applies into the Blocks
	 * checkout store.
	 */
	function syncBlocksCheckout( gatewayId ) {
		if ( ! window.wc || ! window.wc.blocksCheckout ) {
			return;
		}

		window.wc.blocksCheckout.extensionCartUpdate( {
			namespace: config.namespace,
			data: { gateway: gatewayId },
		} );
	}

	function handleGatewayChange( gatewayId ) {
		refreshClassicCheckout();
		syncBlocksCheckout( gatewayId );
	}

	function handleChange( event ) {
		var target = event.target;

		if ( ! target || target.type !== 'radio' ) {
			return;
		}

		// Identify the payment-method radio by its value matching a registered
		// gateway ID, not by CSS class — keeps this working regardless of the
		// exact markup either checkout type renders.
		if ( config.gateways.indexOf( target.value ) === -1 ) {
			return;
		}

		handleGatewayChange( target.value );
	}

	document.addEventListener( 'change', handleChange );

	// The first checked radio on the page is often NOT a payment method (e.g.
	// classic checkout renders shipping-rate radios above the payment box), so
	// scan every checked radio for one whose value is a registered gateway ID.
	function findCheckedGateway() {
		var radios = document.querySelectorAll( 'input[type="radio"]:checked' );
		for ( var i = 0; i < radios.length; i++ ) {
			if ( config.gateways.indexOf( radios[ i ].value ) !== -1 ) {
				return radios[ i ].value;
			}
		}
		return null;
	}

	function initialSync() {
		var gateway = findCheckedGateway();
		if ( gateway ) {
			handleGatewayChange( gateway );
			return true;
		}
		return false;
	}

	// Sync once on load too: the customer may never touch the radio if the
	// pre-selected default gateway is already the one they want.
	document.addEventListener( 'DOMContentLoaded', function () {
		if ( initialSync() ) {
			return;
		}

		// Blocks checkout renders its payment options with React well after
		// DOMContentLoaded, so wait for a payment radio to appear. Bounded,
		// in case this page never renders one at all.
		if ( ! window.MutationObserver ) {
			return;
		}

		var observer = new MutationObserver( function () {
			if ( initialSync() ) {
				observer.disconnect();
			}
		} );

		observer.observe( document.body, { childList: true, subtree: true } );

		setTimeout( function () {
			observer.disconnect();
		}, 15000 );
	} );
} )();
