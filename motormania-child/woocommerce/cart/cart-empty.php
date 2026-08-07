<?php

defined( 'ABSPATH' ) || exit;

remove_action( 'woocommerce_cart_is_empty', 'wc_empty_cart_message', 10 );

/*
 * @hooked wc_empty_cart_message - 10
 */
do_action( 'woocommerce_cart_is_empty' );

$return_to_shop_button = '';

if ( wc_get_page_id( 'shop' ) > 0 ) {
	$return_to_shop_button = ' <a class="button wc-forward" href="' . esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ) . '">' . esc_html( apply_filters( 'woocommerce_return_to_shop_text', __( 'Return to shop', 'woocommerce' ) ) ) . '</a>';
}

wc_print_notice(
	esc_html__( 'Your cart is currently empty.', 'woocommerce' ) . $return_to_shop_button,
	'notice'
);