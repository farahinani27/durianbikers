<?php
// Minimal WordPress shims for running pure-PHP unit tests without WordPress.
if ( ! defined( 'ABSPATH' ) ) { /* leave undefined; tests define DB_CHAT_TEST instead */ }
if ( ! function_exists( '__' ) )            { function __( $t, $d = null ) { return $t; } }
if ( ! function_exists( 'esc_html' ) )      { function esc_html( $t ) { return $t; } }
if ( ! function_exists( 'esc_attr' ) )      { function esc_attr( $t ) { return $t; } }
if ( ! function_exists( 'esc_url_raw' ) )   { function esc_url_raw( $t ) { return $t; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $t ) { return is_string($t)?trim($t):$t; } }
if ( ! function_exists( 'wp_strip_all_tags' ) )   { function wp_strip_all_tags( $t ) { return is_string($t)?strip_tags($t):$t; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } }
if ( ! function_exists( 'get_option' ) )    { function get_option( $k, $d = false ) { return $d; } }
