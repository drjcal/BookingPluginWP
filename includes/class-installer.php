<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

class Installer {

	const DB_VERSION = '3';

	const OPTION_DB_VERSION          = 'gzw_booking_db_version';
	const OPTION_REMOVE_ON_UNINSTALL = 'gzw_booking_remove_data_on_uninstall';

	const STATUS_CONFIRMED = 'confirmed';
	const STATUS_CANCELLED = 'cancelled';
	/** Status given to a new enrolment. */
	const STATUS_DEFAULT = self::STATUS_CONFIRMED;

	/** Capability to view and manage enrolments; granted to administrators. */
	const CAP_MANAGE_ENROLMENTS = 'gzw_manage_enrolments';

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

	/** Give administrators the enrolment capability (idempotent). */
	public static function grant_capabilities(): void {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::CAP_MANAGE_ENROLMENTS ) ) {
			$role->add_cap( self::CAP_MANAGE_ENROLMENTS );
		}
	}

	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION_DB_VERSION ) !== self::DB_VERSION ) {
			self::create_tables();
		}
		self::grant_capabilities();
	}

	/** Drop all plugin data. Called from uninstall.php only when the owner opted in. */
	public static function uninstall(): void {
		global $wpdb;
		foreach ( array( self::table_name(), self::sessions_table(), self::courses_table() ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL -- table names come from this class.
		}
		delete_option( self::OPTION_DB_VERSION );
		delete_option( self::OPTION_REMOVE_ON_UNINSTALL );
	}

	private static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$field_columns = implode( ",\n\t\t\t\t", Enrolment_Fields::column_sql() ) . ',';
		$status        = self::STATUS_DEFAULT;
		$courses       = self::courses_table();
		$sessions      = self::sessions_table();

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
				{$field_columns}
				status varchar(20) NOT NULL DEFAULT '{$status}',
				invoiced tinyint(1) NOT NULL DEFAULT 0,
				internal_note text NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY email (email),
				KEY session_id (session_id),
				KEY status (status)
			) {$charset};"
		);

		self::grant_capabilities();
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
	}
}
