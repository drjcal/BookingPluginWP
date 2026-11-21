<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

class Installer {

	const DB_VERSION = '1';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'gzw_enrolments';
	}

	public static function activate(): void {
		self::create_tables();
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'gzw_booking_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
		}
	}

	private static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				first_name varchar(100) NOT NULL,
				last_name varchar(100) NOT NULL,
				email varchar(190) NOT NULL,
				employer varchar(190) NOT NULL,
				big_number varchar(11) NOT NULL DEFAULT '',
				invoice_name varchar(190) NOT NULL,
				invoice_street varchar(190) NOT NULL,
				invoice_postcode varchar(20) NOT NULL,
				invoice_city varchar(100) NOT NULL,
				invoice_email varchar(190) NOT NULL DEFAULT '',
				invoice_reference varchar(190) NOT NULL DEFAULT '',
				invoice_kvk varchar(50) NOT NULL DEFAULT '',
				invoice_btw varchar(50) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'confirmed',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY email (email),
				KEY status (status)
			) {$charset};"
		);

		update_option( 'gzw_booking_db_version', self::DB_VERSION );
	}
}
