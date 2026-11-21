<?php
/**
 * Plugin Name: Gezonder in Werk Booking
 * Description: Inschrijfmodule voor nascholingen van Gezonder in Werk B.V.
 * Version:     0.1.0
 * Requires PHP: 8.0
 * Text Domain: gezonder-in-werk-booking
 * Domain Path: /languages
 */

namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.0';

define( 'GZW_BOOKING_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-enrolment-fields.php';
require_once __DIR__ . '/includes/class-installer.php';
require_once __DIR__ . '/includes/class-catalog-repository.php';
require_once __DIR__ . '/includes/class-enrolment-validator.php';
require_once __DIR__ . '/includes/class-enrolment-repository.php';
require_once __DIR__ . '/includes/class-rest-controller.php';
require_once __DIR__ . '/includes/class-form-shortcode.php';
require_once __DIR__ . '/includes/class-admin-page.php';
require_once __DIR__ . '/includes/class-enrolments-admin.php';

register_activation_hook( __FILE__, array( Installer::class, 'activate' ) );

add_action( 'rest_api_init', array( Rest_Controller::class, 'register_routes' ) );
add_action( 'init', array( Form_Shortcode::class, 'register' ) );
// Keep the schema current when the plugin is updated without re-activation.
add_action( 'plugins_loaded', array( Installer::class, 'maybe_upgrade' ) );
add_action( 'admin_menu', array( Admin_Page::class, 'register_menu' ) );
add_action( 'admin_post_gzw_save_course', array( Admin_Page::class, 'handle_save_course' ) );
add_action( 'admin_post_gzw_delete_course', array( Admin_Page::class, 'handle_delete_course' ) );
add_action( 'admin_post_gzw_save_session', array( Admin_Page::class, 'handle_save_session' ) );
add_action( 'admin_post_gzw_delete_session', array( Admin_Page::class, 'handle_delete_session' ) );
add_action( 'admin_menu', array( Enrolments_Admin::class, 'register_menu' ) );
add_action( 'admin_post_gzw_update_enrolment', array( Enrolments_Admin::class, 'handle_update' ) );
