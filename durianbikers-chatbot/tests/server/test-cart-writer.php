<?php
// Run: wp eval-file durianbikers-chatbot/tests/server/test-cart-writer.php --path=<docroot>
// Controller runs this wrapped in the cart snapshot/restore helper (see
// [[chatbot-add-to-cart-sep2026]] — ~/claude-uploads/t21-cart-snapshot.php
// save|restore) to protect the admin's own persistent cart.
require __DIR__ . '/../lib/assert.php';

$cw = new DB_Chat_Cart_Writer();

// --- contents() shape, before anything is added --------------------------
$before = $cw->contents();
t_ok( is_array( $before ) && isset( $before['items'], $before['count'], $before['total'], $before['cart_url'] ), 'contents() has the whitelisted top-level shape' );
t_ok( is_array( $before['items'] ), 'contents() items is an array' );

// --- find an in-stock variation on 59463 (HJC Helmet C10) ----------------
$product      = wc_get_product( 59463 );
$variation_id = 0;

if ( $product instanceof WC_Product_Variable ) {
	foreach ( $product->get_children() as $child_id ) {
		$variation = wc_get_product( $child_id );

		if ( $variation instanceof WC_Product_Variation && $variation->is_in_stock() && $variation->has_enough_stock( 2 ) ) {
			$variation_id = (int) $variation->get_id();
			break;
		}
	}
}

t_ok( $variation_id > 0, 'found an in-stock variation with room for qty 2 on 59463' );

$before_count = (int) $before['count'];

// --- add(), then confirm contents() reflects it ---------------------------
$added = $cw->add( 59463, $variation_id, 1 );
t_ok( ! empty( $added['ok'] ), 'add() succeeded' );

$after_add = $cw->contents();
t_ok( (int) $after_add['count'] >= $before_count + 1, 'contents() count grew after add()' );
t_ok( ! empty( $after_add['cart_url'] ), 'contents() cart_url present' );

// Locate the line we just added.
$key = '';
foreach ( $after_add['items'] as $line ) {
	if ( (int) $line['product_id'] === 59463 && (int) $line['variation_id'] === $variation_id ) {
		$key = (string) $line['key'];
	}
}
t_ok( '' !== $key, 'located the added line by product_id/variation_id' );

// Whitelisted keys only — no raw meta, no sku.
if ( '' !== $key ) {
	foreach ( $after_add['items'] as $line ) {
		if ( $line['key'] === $key ) {
			$allowed = array( 'key', 'name', 'label', 'qty', 'line_price', 'product_id', 'variation_id' );
			$extra   = array_diff( array_keys( $line ), $allowed );
			t_ok( empty( $extra ), 'cart line has only whitelisted keys (extra: ' . implode( ',', $extra ) . ')' );
			t_ok( ! isset( $line['sku'] ) && ! isset( $line['meta'] ), 'cart line has no sku/meta' );
		}
	}
}

// --- update() to qty 2 -----------------------------------------------------
if ( '' !== $key ) {
	$updated = $cw->update( $key, 2 );
	t_ok( ! empty( $updated['ok'] ), 'update() to qty 2 succeeded' );

	$after_update = $cw->contents();
	$qty_now      = 0;
	foreach ( $after_update['items'] as $line ) {
		if ( $line['key'] === $key ) {
			$qty_now = (int) $line['qty'];
		}
	}
	t_eq( $qty_now, 2, 'quantity is 2 after update()' );

	// --- update() to 0 removes the line ------------------------------------
	$zeroed = $cw->update( $key, 0 );
	t_ok( ! empty( $zeroed['ok'] ), 'update() to qty 0 succeeded' );

	$after_zero = $cw->contents();
	$still_there = false;
	foreach ( $after_zero['items'] as $line ) {
		if ( $line['key'] === $key ) {
			$still_there = true;
		}
	}
	t_ok( ! $still_there, 'line is gone after update() to qty 0' );
}

// --- remove() on a re-added item --------------------------------------------
if ( $variation_id > 0 ) {
	$re_added = $cw->add( 59463, $variation_id, 1 );
	t_ok( ! empty( $re_added['ok'] ), 're-add() succeeded' );

	$after_readd = $cw->contents();
	$key2        = '';
	foreach ( $after_readd['items'] as $line ) {
		if ( (int) $line['product_id'] === 59463 && (int) $line['variation_id'] === $variation_id ) {
			$key2 = (string) $line['key'];
		}
	}
	t_ok( '' !== $key2, 'located the re-added line' );

	if ( '' !== $key2 ) {
		$removed = $cw->remove( $key2 );
		t_ok( ! empty( $removed['ok'] ), 'remove() succeeded' );

		$after_remove = $cw->contents();
		$still_there2 = false;
		foreach ( $after_remove['items'] as $line ) {
			if ( $line['key'] === $key2 ) {
				$still_there2 = true;
			}
		}
		t_ok( ! $still_there2, 'line is gone after remove()' );
	}
}

// --- bad input is refused, not silently accepted ---------------------------
$bad_key = $cw->update( 'not-a-real-key', 1 );
t_ok( empty( $bad_key['ok'] ), 'update() on an unknown cart_item_key is refused' );

$bad_remove = $cw->remove( 'not-a-real-key' );
t_ok( empty( $bad_remove['ok'] ), 'remove() on an unknown cart_item_key is refused' );

$bad_qty = ( '' !== $key ) ? null : null; // (negative-quantity check needs a live key; covered above via re-add path if present)
if ( $variation_id > 0 ) {
	$again = $cw->add( 59463, $variation_id, 1 );
	if ( ! empty( $again['ok'] ) ) {
		$c3   = $cw->contents();
		$key3 = '';
		foreach ( $c3['items'] as $line ) {
			if ( (int) $line['product_id'] === 59463 && (int) $line['variation_id'] === $variation_id ) {
				$key3 = (string) $line['key'];
			}
		}
		if ( '' !== $key3 ) {
			$neg = $cw->update( $key3, -1 );
			t_ok( empty( $neg['ok'] ), 'update() refuses a negative quantity' );
			// Clean up.
			$cw->remove( $key3 );
		}
	}
}

t_done();
