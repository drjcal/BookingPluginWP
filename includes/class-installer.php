<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

class Installer {

	const DB_VERSION = '5';

	const OPTION_DB_VERSION          = 'gzw_booking_db_version';
	const OPTION_REMOVE_ON_UNINSTALL = 'gzw_booking_remove_data_on_uninstall';

	const STATUS_CONFIRMED = 'confirmed';
	const STATUS_WAITLISTED = 'waitlisted';
	const STATUS_CANCELLED  = 'cancelled';

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

	public static function cycles_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'gzw_cycles';
	}

	public static function cycle_sessions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'gzw_cycle_sessions';
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
		$installed = get_option( self::OPTION_DB_VERSION );
		if ( $installed !== self::DB_VERSION ) {
			self::create_tables();
		}
		self::grant_capabilities();
	}

	/** Drop all plugin data. Called from uninstall.php only when the owner opted in. */
	public static function uninstall(): void {
		global $wpdb;
		foreach ( array( self::table_name(), self::cycle_sessions_table(), self::cycles_table(), self::sessions_table(), self::courses_table() ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL -- table names come from this class.
		}
		delete_option( self::OPTION_DB_VERSION );
		delete_option( self::OPTION_REMOVE_ON_UNINSTALL );
		delete_option( Settings_Page::OPTION_NOTIFICATION_EMAIL );
	}

	/**
	 * The unique key on (active_session_id, email) enforces one active enrolment per
	 * e-mail address and session; cancelled rows have NULL and so never collide.
	 * Existing rows get it set once, keeping the oldest of any pre-existing duplicates protected.
	 */
	private static function backfill_active_session( string $installed ): void {
		global $wpdb;
		if ( '' === $installed || version_compare( $installed, '4', '>=' ) ) {
			return;
		}
		$t = self::table_name();
		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL -- table from this class.
			$wpdb->prepare(
				"UPDATE {$t} e JOIN (SELECT MIN(id) AS id FROM {$t} WHERE status <> %s GROUP BY session_id, email) k ON k.id = e.id
				SET e.active_session_id = e.session_id",
				self::STATUS_CANCELLED
			)
		);
	}

	private static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$installed = (string) get_option( self::OPTION_DB_VERSION, '' );
		$table     = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$field_columns = implode( ",\n\t\t\t\t", Enrolment_Fields::column_sql() ) . ',';
		$status        = self::STATUS_CONFIRMED;
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

		$cycles         = self::cycles_table();
		$cycle_sessions = self::cycle_sessions_table();

		dbDelta(
			"CREATE TABLE {$cycles} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				title varchar(190) NOT NULL,
				price_cents int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$cycle_sessions} (
				cycle_id bigint(20) unsigned NOT NULL,
				session_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (cycle_id,session_id),
				KEY session_id (session_id)
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
				capacity int(10) unsigned DEFAULT NULL,
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
				active_session_id bigint(20) unsigned DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY active_email (active_session_id,email),
				KEY email (email),
				KEY session_id (session_id),
				KEY status (status)
			) {$charset};"
		);

		// Before the version is stored, so a failed backfill is retried on the next load.
		self::backfill_active_session( $installed );
		self::grant_capabilities();
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
	}
}
