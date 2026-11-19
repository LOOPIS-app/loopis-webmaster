<?php
/**
 * Plugin Name: LOOPIS Webmaster
 * Description: Plugin providing tools for webmasters of LOOPIS.app
 * Version:     0.01
 * Author:      The Develoopers
 * Author URI:  https://loopis.org
 * License:     GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Run only in admin area
if (!is_admin()) {
    return;
}

add_action('init', 'loopis_webmaster_load_tools');

/** Load administrative tools only for super administrators. */
function loopis_webmaster_load_tools() {
    if (!is_super_admin() || !current_user_can('manage_options')) {
        return;
    }

    require_once __DIR__ . '/includes/image-conversion/functions.php';
    require_once __DIR__ . '/includes/image-conversion/page.php';
    require_once __DIR__ . '/includes/image-cleanup/functions.php';
    require_once __DIR__ . '/includes/image-cleanup/page.php';
    require_once __DIR__ . '/includes/image-conversion/batched-scan.php';
    require_once __DIR__ . '/includes/move-user/functions.php';
    require_once __DIR__ . '/includes/move-user/page.php';
    require_once __DIR__ . '/includes/qr-codes/functions.php';
    require_once __DIR__ . '/includes/qr-codes/page.php';
    add_action('admin_menu', 'loopis_webmaster_menu');
}

/** Register the Webmaster menu and its tools. */
function loopis_webmaster_menu() {
    add_menu_page(
        'LOOPIS Webmaster',
        'Webmaster',
        'manage_options',
        'loopis-webmaster',
        '',
        plugins_url('assets/loopis-icon.png', __FILE__)
    );
    add_submenu_page(
        'loopis-webmaster',
        'LOOPIS Image Conversion',
        'Image Conversion',
        'manage_options',
        'loopis-image-conversion',
        'loopis_image_conversion_render_page'
    );
    add_submenu_page(
        'loopis-webmaster',
        'LOOPIS Image Cleanup',
        'Image Cleanup',
        'manage_options',
        'loopis-image-cleanup',
        'loopis_image_cleanup_render_page'
    );
    add_submenu_page(
        'loopis-webmaster',
        'Move User',
        'Move User',
        'manage_options',
        'move-user',
        'loopis_move_user_page'
    );
    remove_submenu_page('loopis-webmaster', 'loopis-webmaster');
}
