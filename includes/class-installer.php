<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

class Installer {

	const DB_VERSION = '2';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'gzw_enrolments';
	}

	public static function courses_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'gzw_courses';
	}

	public static function sessions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'gzw_sessions';
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

		$courses  = self::courses_table();
		$sessions = self::sessions_table();

		dbDelta(
			"CREATE TABLE {$courses} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				title varchar(190) NOT NULL,
				description text NOT NULL,
				accreditation text NOT NULL,
				price_cents int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$sessions} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				course_id bigint(20) unsigned NOT NULL,
				session_date date NOT NULL,
				start_time time NOT NULL,
				end_time time NOT NULL,
				location varchar(190) NOT NULL,
				is_open tinyint(1) NOT NULL DEFAULT 1,
				PRIMARY KEY  (id),
				KEY course_id (course_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session_id bigint(20) unsigned NOT NULL DEFAULT 0,
				price_cents int(10) unsigned NOT NULL DEFAULT 0,
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
				KEY session_id (session_id),
				KEY status (status)
			) {$charset};"
		);

		update_option( 'gzw_booking_db_version', self::DB_VERSION );
	}
}
