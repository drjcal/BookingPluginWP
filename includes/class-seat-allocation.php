<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * Seat allocation: the one module that decides who holds a seat. It owns capacity, the status
 * transitions (confirmed, waitlisted, cancelled), the per-session lock and all-or-nothing cycle
 * placement. Public submission, admin status edits, promotion, capacity changes and cycle
 * edits all go through here, so "no overbooking" never depends on a caller remembering to lock.
 *
 * Nothing outside this module writes an enrolment's status, so the active-session key (which
 * backs the unique e-mail rule) always matches it. The lock is an internal seam: see Session_Lock.
 *
 * Every operation reports a lock timeout as 'busy': nothing was changed and retrying is safe.
 */
class Seat_Allocation {

	const MYSQL_DUPLICATE_ENTRY = 1062;

	/**
	 * Raw insert; the status defaults to confirmed and no capacity is checked. Use enrol() for
	 * public submissions; this exists for seeding rows (imports, tests).
	 *
	 * @param array<string,string> $values Validated values.
	 * @return int New enrolment id, 0 on failure.
	 */
	public static function insert( array $values ): int {
		global $wpdb;

		$row = $values + array(
			'status'        => Installer::STATUS_CONFIRMED,
			'internal_note' => '',
			'created_at'    => current_time( 'mysql', true ),
		);
		$row['active_session_id'] = self::active_session_id( (string) $row['status'], (int) ( $row['session_id'] ?? 0 ) );

		$wpdb->suppress_errors( true );
		$ok = $wpdb->insert( Installer::table_name(), $row );
		$wpdb->suppress_errors( false );
		if ( false === $ok ) {
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	/** Cancelled enrolments are not "active": they hold no seat and release the e-mail address. */
	private static function active_session_id( string $status, int $session_id ): ?int {
		return Installer::STATUS_CANCELLED === $status ? null : $session_id;
	}

	/**
	 * Store a public submission. Under the session lock: re-check the session is still bookable,
	 * confirm when a seat is free (otherwise waitlist) and insert, so concurrent submissions cannot
	 * exceed capacity and a closing session cannot receive a confirmed enrolment. The unique key
	 * on (active_session_id, email) is the backstop for duplicates.
	 *
	 * @param array<string,string> $values Validated values incl. session_id.
	 * @return array{id:int,status:string,error:?string} error: closed | duplicate | busy | storage.
	 */
	public static function enrol( array $values ): array {
		$session_id = (int) ( $values['session_id'] ?? 0 );
		$result     = Session_Lock::run( $session_id, static fn(): array => self::place( $session_id, $values ) );
		return $result ?? array( 'id' => 0, 'status' => '', 'error' => 'busy' );
	}

	/**
	 * Store a cycle submission: one enrolment per webinar sharing a group reference, all or nothing.
	 * The bundle price is recorded once, on the first webinar; the others carry 0, so the group
	 * totals the bundle price exactly. Each webinar keeps its own seat/waitlist outcome.
	 * All session locks are held for the whole submission; when any webinar fails, the ones
	 * already placed are removed again.
	 *
	 * @param array<string,string> $values Validated values (price_cents and session_id are replaced per webinar).
	 * @param array                $cycle  Cycle row with its 'sessions'.
	 * @return array{id:int,ids:int[],status:string,group_reference:string,error:?string}
	 *         status: waitlisted when any webinar is, otherwise confirmed. error: closed | duplicate | busy | storage.
	 */
	public static function enrol_cycle( array $values, array $cycle ): array {
		$failed = array( 'id' => 0, 'ids' => array(), 'status' => '', 'group_reference' => '', 'error' => 'storage' );
		$ids    = array_map( static fn( array $s ) => (int) $s['id'], $cycle['sessions'] );

		$result = Session_Lock::run_all(
			$ids,
			static function () use ( $values, $cycle, $ids, $failed ): array {
				// The cycle may have been edited since validation: enrol only when it is unchanged.
				$current = Catalog_Repository::get_cycle( (int) $cycle['id'] );
				if ( ! $current || (int) $current['price_cents'] !== (int) $cycle['price_cents'] || array_map( 'intval', array_column( $current['sessions'], 'id' ) ) !== $ids ) {
					return array( 'error' => 'closed' ) + $failed;
				}
				$reference = 'CYC-' . strtoupper( bin2hex( random_bytes( 5 ) ) );
				$created   = array();
				$waitlist  = false;
				foreach ( $ids as $position => $session_id ) {
					$placed = self::place(
						$session_id,
						array(
							'session_id'      => (string) $session_id,
							'price_cents'     => (string) ( 0 === $position ? (int) $cycle['price_cents'] : 0 ),
							'group_reference' => $reference,
						) + $values
					);
					if ( $placed['error'] ) {
						if ( $created ) {
							// Same delete path as staff erasure: the group shares the reference.
							Enrolment_Repository::delete( $created[0] );
						}
						return array( 'error' => $placed['error'] ) + $failed;
					}
					$created[] = $placed['id'];
					$waitlist  = $waitlist || Installer::STATUS_WAITLISTED === $placed['status'];
				}
				return array(
					'id'              => $created[0],
					'ids'             => $created,
					'status'          => $waitlist ? Installer::STATUS_WAITLISTED : Installer::STATUS_CONFIRMED,
					'group_reference' => $reference,
					'error'           => null,
				);
			}
		);
		return $result ?? array( 'error' => 'busy' ) + $failed;
	}

	/**
	 * Insert one enrolment for a session. The caller holds that session's lock.
	 *
	 * @return array{id:int,status:string,error:?string}
	 */
	private static function place( int $session_id, array $values ): array {
		$session = Catalog_Repository::get_session( $session_id );
		if ( ! $session || ! Catalog_Repository::is_bookable( $session ) ) {
			return array( 'id' => 0, 'status' => '', 'error' => 'closed' );
		}
		$status = self::confirmed_count( $session_id ) < self::seats( $session ) ? Installer::STATUS_CONFIRMED : Installer::STATUS_WAITLISTED;
		$id     = self::insert( array( 'status' => $status ) + $values );
		if ( $id ) {
			return array( 'id' => $id, 'status' => $status, 'error' => null );
		}
		return array( 'id' => 0, 'status' => '', 'error' => self::last_write_was_duplicate() ? 'duplicate' : 'storage' );
	}

	/** Duplicate detection reads the database error code, not its (translatable, versioned) text. */
	private static function last_write_was_duplicate(): bool {
		global $wpdb;
		return $wpdb->dbh instanceof \mysqli && self::MYSQL_DUPLICATE_ENTRY === $wpdb->dbh->errno;
	}

	/** @param array<string,mixed> $session Session row. @return int Seats, PHP_INT_MAX when unlimited. */
	private static function seats( array $session ): int {
		return null === $session['capacity'] ? PHP_INT_MAX : (int) $session['capacity'];
	}

	/**
	 * Seat picture of a session for staff: confirmed seats, capacity, waitlist and free seats.
	 * Capacity and free are null when the session is unlimited. Read-only, takes no lock.
	 *
	 * @return array{confirmed:int,capacity:?int,waitlisted:int,free:?int}
	 */
	public static function availability( int $session_id ): array {
		global $wpdb;
		$session   = Catalog_Repository::get_session( $session_id );
		$capacity  = $session && null !== $session['capacity'] ? (int) $session['capacity'] : null;
		$confirmed = self::confirmed_count( $session_id );
		$table     = Installer::table_name();
		$waitlist  = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE session_id = %d AND status = %s", $session_id, Installer::STATUS_WAITLISTED ) // phpcs:ignore WordPress.DB.PreparedSQL -- table from Installer.
		);
		return array(
			'confirmed'  => $confirmed,
			'capacity'   => $capacity,
			'waitlisted' => $waitlist,
			'free'       => null === $capacity ? null : max( 0, $capacity - $confirmed ),
		);
	}

	private static function confirmed_count( int $session_id ): int {
		global $wpdb;
		$table = Installer::table_name();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE session_id = %d AND status = %s", $session_id, Installer::STATUS_CONFIRMED ) // phpcs:ignore WordPress.DB.PreparedSQL -- table from Installer.
		);
	}

	/**
	 * Staff edits: status (ignored when unknown), invoiced flag and internal note.
	 * Moving an enrolment to confirmed needs a free seat.
	 *
	 * @return string ok | full (no free seat) | busy (lock timeout, nothing changed) | error
	 */
	public static function update_admin( int $id, string $status, bool $invoiced, string $note ): string {
		global $wpdb;
		$current = Enrolment_Repository::get( $id );
		if ( ! $current ) {
			return 'error';
		}
		$row = array(
			'invoiced'      => $invoiced ? 1 : 0,
			'internal_note' => $note,
		);
		if ( in_array( $status, Enrolment_Repository::STATUSES, true ) ) {
			$row['status']            = $status;
			$row['active_session_id'] = self::active_session_id( $status, (int) $current['session_id'] );
		}
		$write = static function () use ( $wpdb, $row, $id, $current, $status ): string {
			// Re-read under the lock: the status may have changed since the form was loaded.
			$fresh = Enrolment_Repository::get( $id );
			if ( ! $fresh || (int) $fresh['session_id'] !== (int) $current['session_id'] ) {
				return 'error';
			}
			if ( Installer::STATUS_CONFIRMED === $status && Installer::STATUS_CONFIRMED !== $fresh['status'] ) {
				$session = Catalog_Repository::get_session( (int) $current['session_id'] );
				if ( $session && self::confirmed_count( (int) $session['id'] ) >= self::seats( $session ) ) {
					return 'full';
				}
			}
			$wpdb->suppress_errors( true );
			$ok = $wpdb->update( Installer::table_name(), $row, array( 'id' => $id ) );
			$wpdb->suppress_errors( false );
			return false === $ok ? 'error' : 'ok';
		};
		return Session_Lock::run( (int) $current['session_id'], $write ) ?? 'busy';
	}

	/** Staff promote a waitlisted enrolment to confirmed, keeping every other field. */
	public static function promote( int $id ): string {
		$row = Enrolment_Repository::get( $id );
		if ( ! $row || Installer::STATUS_WAITLISTED !== $row['status'] ) {
			return 'error';
		}
		return self::update_admin( $id, Installer::STATUS_CONFIRMED, (bool) (int) $row['invoiced'], (string) $row['internal_note'] );
	}

	/**
	 * Write a session row under its lock. Lowering the capacity below the number of confirmed
	 * enrolments is refused: nobody is silently bumped to the waitlist, staff move people first.
	 *
	 * @param array<string,mixed> $row Session columns (see Catalog_Repository::session_row()).
	 * @return array{id:int,error:?string} error: capacity_below_confirmed | busy | storage.
	 */
	public static function update_session( array $row, int $id ): array {
		global $wpdb;
		$result = Session_Lock::run(
			$id,
			static function () use ( $row, $id, $wpdb ): array {
				if ( null !== $row['capacity'] && self::confirmed_count( $id ) > (int) $row['capacity'] ) {
					return array( 'id' => 0, 'error' => 'capacity_below_confirmed' );
				}
				return false === $wpdb->update( Installer::sessions_table(), $row, array( 'id' => $id ) )
					? array( 'id' => 0, 'error' => 'storage' )
					: array( 'id' => $id, 'error' => null );
			}
		);
		return $result ?? array( 'id' => 0, 'error' => 'busy' );
	}

	/**
	 * Save a cycle with its sessions, all or nothing. Held under the locks of every session the
	 * cycle had and will have, so a submission in flight never sees a half-edited cycle. A cycle
	 * needs at least two distinct sessions. On failure the previous cycle is restored.
	 *
	 * @param array $data title, price_cents, session_ids (int[]).
	 * @return int Cycle id, 0 on failure (invalid, busy or storage).
	 */
	public static function save_cycle( array $data, int $id = 0 ): int {
		global $wpdb;
		$session_ids = array_values( array_unique( array_map( 'intval', (array) ( $data['session_ids'] ?? array() ) ) ) );
		if ( count( $session_ids ) < 2 ) {
			return 0;
		}
		$row = array(
			'title'       => (string) ( $data['title'] ?? '' ),
			'price_cents' => max( 0, (int) ( $data['price_cents'] ?? 0 ) ),
		);

		$previous = $id ? Catalog_Repository::get_cycle( $id ) : null;
		if ( $id && ! $previous ) {
			return 0;
		}
		$old_ids = $previous ? array_map( static fn( array $s ) => (int) $s['id'], $previous['sessions'] ) : array();

		$saved = Session_Lock::run_all(
			array_merge( $old_ids, $session_ids ),
			static function () use ( $wpdb, $row, $id, $previous, $old_ids, $session_ids ): int {
				$cycles = Installer::cycles_table();
				$links  = Installer::cycle_sessions_table();
				if ( $id ) {
					if ( false === $wpdb->update( $cycles, $row, array( 'id' => $id ) ) ) {
						return 0;
					}
					$wpdb->delete( $links, array( 'cycle_id' => $id ) );
				} elseif ( $wpdb->insert( $cycles, $row ) ) {
					$id = (int) $wpdb->insert_id;
				} else {
					return 0;
				}
				foreach ( $session_ids as $session_id ) {
					if ( ! $wpdb->insert( $links, array( 'cycle_id' => $id, 'session_id' => $session_id ) ) ) {
						self::restore_cycle( $id, $previous, $old_ids );
						return 0;
					}
				}
				return $id;
			}
		);
		return (int) $saved;
	}

	/** Put a cycle back as it was; a cycle that did not exist before is removed again. */
	private static function restore_cycle( int $id, ?array $previous, array $old_ids ): void {
		global $wpdb;
		$links = Installer::cycle_sessions_table();
		$wpdb->delete( $links, array( 'cycle_id' => $id ) );
		if ( ! $previous ) {
			$wpdb->delete( Installer::cycles_table(), array( 'id' => $id ) );
			return;
		}
		$wpdb->update( Installer::cycles_table(), array( 'title' => $previous['title'], 'price_cents' => (int) $previous['price_cents'] ), array( 'id' => $id ) );
		foreach ( $old_ids as $session_id ) {
			$wpdb->insert( $links, array( 'cycle_id' => $id, 'session_id' => $session_id ) );
		}
	}

	/** Delete a cycle under its sessions' locks; the links come back when the cycle row cannot go. */
	public static function delete_cycle( int $id ): bool {
		global $wpdb;
		$cycle = Catalog_Repository::get_cycle( $id );
		if ( ! $cycle ) {
			return false;
		}
		$ids     = array_map( static fn( array $s ) => (int) $s['id'], $cycle['sessions'] );
		$deleted = Session_Lock::run_all(
			$ids,
			static function () use ( $wpdb, $id, $cycle, $ids ): bool {
				$links = Installer::cycle_sessions_table();
				$wpdb->delete( $links, array( 'cycle_id' => $id ) );
				if ( false === $wpdb->delete( Installer::cycles_table(), array( 'id' => $id ) ) ) {
					self::restore_cycle( $id, $cycle, $ids );
					return false;
				}
				return true;
			}
		);
		return (bool) $deleted;
	}
}
