<?php
/**
 * Register the QR-code table editor and its page extension.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../reusable/table-menu.php';

global $wpdb;

new table_menu(
	$wpdb->base_prefix . 'loopis_qr_codes',
	array(
		'id' => '%d',
	),
	array(
		'name'     => '%s',
		'uid'      => '%s',
		'blog_id'  => '%d',
		'redirect' => '%s',
	),
	array(
		'menu_name'   => 'QR codes',
		'parent_slug' => 'loopis-webmaster',
		'hook_prefix' => 'loopis_qr_codes',
		'page_name'   => 'loopis-qrs',
	)
);

add_action( 'loopis_qr_codes_after_page', 'loopis_qr_codes_render_stats' );
