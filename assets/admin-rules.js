( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var template = document.getElementById( 'pgma-rule-template' );
		if ( ! template ) {
			return;
		}

		document.querySelectorAll( '.pgma-add-rule' ).forEach( function ( button ) {
			var group = button.closest( '.pgma-rule-group' );

			// Indexes must never be reused: removing a row and adding a new one
			// with the row-count as its index would collide with a surviving
			// row's name, silently overwriting that rule on save. Saved rows
			// render with sequential indexes 0..n-1, so counting once at load
			// gives the next free index; from there we only ever increment.
			group.dataset.pgmaNextIndex = group.querySelectorAll( '.pgma-rule-row' ).length;

			button.addEventListener( 'click', function () {
				var gateway = group.getAttribute( 'data-gateway' );
				var tbody   = group.querySelector( '.pgma-rule-rows' );
				var index   = parseInt( group.dataset.pgmaNextIndex, 10 );

				group.dataset.pgmaNextIndex = index + 1;

				var row = template.content.querySelector( '.pgma-rule-row' ).cloneNode( true );
				row.innerHTML = row.innerHTML
					.split( '__GATEWAY__' ).join( gateway )
					.split( '__INDEX__' ).join( index );

				tbody.appendChild( row );
			} );
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( event.target.classList.contains( 'pgma-remove-rule' ) ) {
				var row = event.target.closest( '.pgma-rule-row' );
				if ( row ) {
					row.remove();
				}
			}
		} );

		// Value field shows "%" for percent rules and the store currency for fixed rules.
		document.addEventListener( 'change', function ( event ) {
			if ( ! event.target.classList.contains( 'pgma-type-select' ) ) {
				return;
			}
			var row    = event.target.closest( '.pgma-rule-row' );
			var symbol = row.querySelector( '.col-value .pgma-symbol' );
			if ( symbol ) {
				symbol.textContent = event.target.value === 'percent' ? '%' : symbol.getAttribute( 'data-currency' );
			}
		} );
	} );
} )();
