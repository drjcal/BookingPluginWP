<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * Courses and their sessions. Prices are integer cents excl. BTW.
 */
class Catalog_Repository {

	/**
	 * @param array $data    title, description, accreditation, price_cents.
	 * @param int   $id      Existing course id to update; 0 to create.
	 * @return int Course id, 0 on failure.
	 */
	public static function save_course( array $data, int $id = 0 ): int {
		global $wpdb;
		$row = array(
			'title'         => (string) ( $data['title'] ?? '' ),
			'description'   => (string) ( $data['description'] ?? '' ),
			'accreditation' => (string) ( $data['accreditation'] ?? '' ),
			'price_cents'   => max( 0, (int) ( $data['price_cents'] ?? 0 ) ),
		);
		if ( $id ) {
			return false === $wpdb->update( Installer::courses_table(), $row, array( 'id' => $id ) ) ? 0 : $id;
		}
		return $wpdb->insert( Installer::courses_table(), $row ) ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param array $data course_id, session_date (Y-m-d), start_time, end_time (H:i), location, is_open,
	 *                    capacity (positive int; empty or missing = unlimited),
	 *                    format (webinar|live; default webinar).
	 * @return int Session id, 0 on failure.
	 */
	public static function save_session( array $data, int $id = 0 ): int {
		return self::save_session_checked( $data, $id )['id'];
	}

	/**
	 * Like save_session(), telling why it failed: capacity_below_confirmed (fewer seats than
	 * confirmed enrolments) | busy (lock timeout, retry) | storage.
	 *
	 * @return array{id:int,error:?string}
	 */
	public static function save_session_checked( array $data, int $id = 0 ): array {
		global $wpdb;
		$row = array(
			'course_id'    => (int) ( $data['course_id'] ?? 0 ),
			'session_date' => (string) ( $data['session_date'] ?? '' ),
			'start_time'   => (string) ( $data['start_time'] ?? '' ),
			'end_time'     => (string) ( $data['end_time'] ?? '' ),
			'location'     => (string) ( $data['location'] ?? '' ),
			'is_open'      => empty( $data['is_open'] ) ? 0 : 1,
			'format'       => Expectation_Texts::normalise_format( $data['format'] ?? null ),
			'capacity'     => ( isset( $data['capacity'] ) && (int) $data['capacity'] > 0 ) ? (int) $data['capacity'] : null,
		);
		if ( $id ) {
			// Seat allocation owns changes to an existing session: capacity and closing must not interleave with a submission in flight.
			return Seat_Allocation::update_session( $row, $id );
		}
		return $wpdb->insert( Installer::sessions_table(), $row )
			? array( 'id' => (int) $wpdb->insert_id, 'error' => null )
			: array( 'id' => 0, 'error' => 'storage' );
	}

	/**
	 * A cycle is a set of sessions sold together at one bundle price (integer cents excl. BTW).
	 *
	 * @param array $data title, price_cents, session_ids (int[]).
	 * @param int   $id   Existing cycle id to update; 0 to create.
	 * @return int Cycle id, 0 on failure.
	 */
	public static function save_cycle( array $data, int $id = 0 ): int {
		return Seat_Allocation::save_cycle( $data, $id );
	}

	/** Cycle with its sessions (each joined with its course), soonest first, under 'sessions'. */
	public static function get_cycle( int $id ): ?array {
		global $wpdb;
		$table = Installer::cycles_table();
		$cycle = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $cycle ) {
			return null;
		}
		$s = Installer::sessions_table();
		$c = Installer::courses_table();
		$l = Installer::cycle_sessions_table();

		$cycle['sessions'] = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, c.title, c.price_cents FROM {$l} l JOIN {$s} s ON s.id = l.session_id JOIN {$c} c ON c.id = s.course_id
				WHERE l.cycle_id = %d ORDER BY s.session_date ASC, s.start_time ASC, s.id ASC",
				$id
			),
			ARRAY_A
		);
		return $cycle;
	}

	public static function cycles(): array {
		global $wpdb;
		$table = Installer::cycles_table();
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY title ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- no user input.
	}

	/** A cycle can be enrolled in when it has sessions and every one of them is bookable. */
	public static function is_cycle_bookable( array $cycle ): bool {
		return $cycle['sessions'] && ! array_filter( $cycle['sessions'], static fn( array $s ) => ! self::is_bookable( $s ) );
	}

	/** Cycles offered on the form (all sessions still bookable). */
	public static function open_cycles(): array {
		$open = array();
		foreach ( self::cycles() as $row ) {
			$cycle = self::get_cycle( (int) $row['id'] );
			if ( $cycle && self::is_cycle_bookable( $cycle ) ) {
				$open[] = $cycle;
			}
		}
		return $open;
	}

	public static function delete_cycle( int $id ): bool {
		return Seat_Allocation::delete_cycle( $id );
	}

	public static function session_in_cycle( int $session_id ): bool {
		global $wpdb;
		$table = Installer::cycle_sessions_table();
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE session_id = %d", $session_id ) );
	}

	/** @see Session_Lock::run_all() */
	public static function with_session_locks( array $session_ids, callable $callback ) {
		return Session_Lock::run_all( $session_ids, $callback );
	}

	/** @see Session_Lock::run() */
	public static function with_session_lock( int $session_id, callable $callback ) {
		return Session_Lock::run( $session_id, $callback );
	}

	/** Open and not yet past (a session today is still bookable). */
	public static function is_bookable( array $session ): bool {
		return (bool) (int) $session['is_open'] && $session['session_date'] >= current_time( 'Y-m-d' );
	}

	public static function get_course( int $id ): ?array {
		global $wpdb;
		$table = Installer::courses_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ) ?: null;
	}

	/** Session joined with its course (title, price_cents, ...). */
	public static function get_session( int $id ): ?array {
		global $wpdb;
		$s = Installer::sessions_table();
		$c = Installer::courses_table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.*, c.title, c.price_cents FROM {$s} s JOIN {$c} c ON c.id = s.course_id WHERE s.id = %d",
				$id
			),
			ARRAY_A
		) ?: null;
	}

	public static function courses(): array {
		global $wpdb;
		$table = Installer::courses_table();
		// No user input: the table name comes from Installer, the rest is static.
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY title ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function sessions_for_course( int $course_id ): array {
		global $wpdb;
		$table = Installer::sessions_table();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE course_id = %d ORDER BY session_date ASC, start_time ASC", $course_id ),
			ARRAY_A
		);
	}

	/** Open sessions that have not passed, of all courses, soonest first. */
	public static function open_sessions(): array {
		global $wpdb;
		$s = Installer::sessions_table();
		$c = Installer::courses_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, c.title, c.price_cents FROM {$s} s JOIN {$c} c ON c.id = s.course_id
				WHERE s.is_open = 1 AND s.session_date >= %s ORDER BY s.session_date ASC, s.start_time ASC",
				current_time( 'Y-m-d' )
			),
			ARRAY_A
		);
	}

	/** All sessions with their course title, latest first (open or closed). */
	public static function all_sessions(): array {
		global $wpdb;
		$s = Installer::sessions_table();
		$c = Installer::courses_table();
		return $wpdb->get_results(
			"SELECT s.*, c.title FROM {$s} s JOIN {$c} c ON c.id = s.course_id ORDER BY s.session_date DESC, s.start_time ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- no user input.
			ARRAY_A
		);
	}

	/** Cancelled enrolments hold no seat and do not keep a session or course in place. */
	public static function session_has_active_enrolments( int $session_id ): bool {
		global $wpdb;
		$table = Installer::table_name();
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE session_id = %d AND status <> %s", $session_id, Installer::STATUS_CANCELLED ) );
	}

	/** Why a session cannot be deleted: it has non-cancelled enrolments or belongs to a cycle (close it instead). */
	private static function session_is_kept( int $session_id ): bool {
		return self::session_has_active_enrolments( $session_id ) || self::session_in_cycle( $session_id );
	}

	public static function delete_session( int $id ): bool {
		global $wpdb;
		$deleted = self::with_session_lock(
			$id,
			static function () use ( $id, $wpdb ): bool {
				if ( self::session_is_kept( $id ) ) {
					return false;
				}
				return false !== $wpdb->delete( Installer::sessions_table(), array( 'id' => $id ) );
			}
		);
		return (bool) $deleted;
	}

	/**
	 * Deletes the course with its sessions. The course row goes first: when that fails nothing
	 * changed, and a failure afterwards can only leave unreachable sessions, never a visible
	 * course without sessions.
	 */
	public static function delete_course( int $id ): bool {
		global $wpdb;
		$session_ids = array_map( static fn( array $s ) => (int) $s['id'], self::sessions_for_course( $id ) );
		$deleted     = self::with_session_locks(
			$session_ids,
			static function () use ( $id, $session_ids, $wpdb ): bool {
				foreach ( $session_ids as $session_id ) {
					if ( self::session_is_kept( $session_id ) ) {
						return false;
					}
				}
				if ( false === $wpdb->delete( Installer::courses_table(), array( 'id' => $id ) ) ) {
					return false;
				}
				$wpdb->delete( Installer::sessions_table(), array( 'course_id' => $id ) );
				return true;
			}
		);
		return (bool) $deleted;
	}
}
