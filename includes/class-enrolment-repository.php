<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

class Enrolment_Repository {

	/**
	 * Raw insert; the status defaults to confirmed. Use enrol() for public submissions.
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
	 * @return array{id:int,status:string,error:?string} error: closed | duplicate | storage.
	 */
	public static function enrol( array $values ): array {
		$session_id = (int) ( $values['session_id'] ?? 0 );
		$result     = Catalog_Repository::with_session_lock(
			$session_id,
			static function () use ( $values, $session_id ): array {
				$session = Catalog_Repository::get_session( $session_id );
				if ( ! $session || ! Catalog_Repository::is_bookable( $session ) ) {
					return array( 'id' => 0, 'status' => '', 'error' => 'closed' );
				}
				$status = self::has_free_seat( $session ) ? Installer::STATUS_CONFIRMED : Installer::STATUS_WAITLISTED;
				$id     = self::insert( array( 'status' => $status ) + $values );
				if ( $id ) {
					return array( 'id' => $id, 'status' => $status, 'error' => null );
				}
				global $wpdb;
				return array( 'id' => 0, 'status' => '', 'error' => false !== stripos( (string) $wpdb->last_error, 'duplicate entry' ) ? 'duplicate' : 'storage' );
			}
		);
		return $result ?? array( 'id' => 0, 'status' => '', 'error' => 'storage' );
	}

	/** @param array<string,mixed> $session Session row incl. id and capacity. */
	private static function has_free_seat( array $session ): bool {
		if ( null === $session['capacity'] ) {
			return true;
		}
		global $wpdb;
		$table     = Installer::table_name();
		$confirmed = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE session_id = %d AND status = %s", (int) $session['id'], Installer::STATUS_CONFIRMED )
		);
		return $confirmed < (int) $session['capacity'];
	}

	/** An enrolment is active unless cancelled. */
	public static function has_active( int $session_id, string $email ): bool {
		global $wpdb;
		$table = Installer::table_name();
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE session_id = %d AND email = %s AND status <> %s",
				$session_id,
				$email,
				Installer::STATUS_CANCELLED
			)
		);
	}

	const STATUSES = array( Installer::STATUS_CONFIRMED, Installer::STATUS_WAITLISTED, Installer::STATUS_CANCELLED );

	public static function get( int $id ): ?array {
		return self::select_with_session( 'WHERE e.id = %d', array( $id ), '' )[0] ?? null;
	}

	/**
	 * Enrolments with their session and course, newest first.
	 *
	 * @param array{course?:int,session?:int,status?:string,search?:string} $filters
	 * @return array{rows:array<int,array<string,mixed>>,total:int}
	 */
	public static function query( array $filters, int $page, int $per_page ): array {
		list( $clause, $args ) = self::filter_clause( $filters );
		$offset                = max( 0, ( $page - 1 ) * $per_page );

		$rows  = self::select_with_session( $clause, $args, sprintf( 'ORDER BY e.created_at DESC, e.id DESC LIMIT %d OFFSET %d', $per_page, $offset ) );
		$total = (int) self::scalar( $clause, $args );

		return array( 'rows' => $rows, 'total' => $total );
	}

	/**
	 * Every enrolment matching the filters (no pagination), newest first.
	 * Used by the CSV export so it always matches the admin list.
	 *
	 * @param array{course?:int,session?:int,status?:string,search?:string} $filters
	 * @return array<int,array<string,mixed>>
	 */
	public static function all( array $filters ): array {
		list( $clause, $args ) = self::filter_clause( $filters );
		return self::select_with_session( $clause, $args, 'ORDER BY e.created_at DESC, e.id DESC' );
	}

	/** @return array{0:string,1:array<int,mixed>} WHERE clause and its prepare() arguments. */
	private static function filter_clause( array $filters ): array {
		global $wpdb;
		$where = array();
		$args  = array();

		if ( ! empty( $filters['course'] ) ) {
			$where[] = 's.course_id = %d';
			$args[]  = (int) $filters['course'];
		}
		if ( ! empty( $filters['session'] ) ) {
			$where[] = 'e.session_id = %d';
			$args[]  = (int) $filters['session'];
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'e.status = %s';
			$args[]  = (string) $filters['status'];
		}
		$search = trim( (string) ( $filters['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$parts  = array( "CONCAT(e.first_name, ' ', e.last_name) LIKE %s" );
			$args[] = $like;
			foreach ( Enrolment_Fields::search_fields() as $name ) {
				$parts[] = "e.{$name} LIKE %s";
				$args[]  = $like;
			}
			$where[] = '(' . implode( ' OR ', $parts ) . ')';
		}

		return array( $where ? 'WHERE ' . implode( ' AND ', $where ) : '', $args );
	}

	/** @return array<int,array<string,mixed>> */
	private static function select_with_session( string $clause, array $args, string $tail ): array {
		global $wpdb;
		$e   = Installer::table_name();
		$s   = Installer::sessions_table();
		$c   = Installer::courses_table();
		$sql = "SELECT e.*, s.course_id, s.session_date, s.start_time, s.end_time, s.location, c.title AS course_title
			FROM {$e} e LEFT JOIN {$s} s ON s.id = e.session_id LEFT JOIN {$c} c ON c.id = s.course_id {$clause} {$tail}";
		return $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- tables from Installer, values prepared.
	}

	private static function scalar( string $clause, array $args ): string {
		global $wpdb;
		$e   = Installer::table_name();
		$s   = Installer::sessions_table();
		$sql = "SELECT COUNT(*) FROM {$e} e LEFT JOIN {$s} s ON s.id = e.session_id {$clause}";
		return (string) $wpdb->get_var( $args ? $wpdb->prepare( $sql, $args ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Staff edits: status (ignored when unknown), invoiced flag and internal note.
	 * Moving an enrolment to confirmed needs a free seat.
	 *
	 * @return string ok | full (no free seat) | error
	 */
	public static function update_admin( int $id, string $status, bool $invoiced, string $note ): string {
		global $wpdb;
		$current = self::get( $id );
		if ( ! $current ) {
			return 'error';
		}
		$row = array(
			'invoiced'      => $invoiced ? 1 : 0,
			'internal_note' => $note,
		);
		if ( in_array( $status, self::STATUSES, true ) ) {
			$row['status']            = $status;
			$row['active_session_id'] = self::active_session_id( $status, (int) $current['session_id'] );
		}
		$write = static function () use ( $wpdb, $row, $id, $current, $status ): string {
			if ( Installer::STATUS_CONFIRMED === $status && Installer::STATUS_CONFIRMED !== $current['status'] ) {
				$session = Catalog_Repository::get_session( (int) $current['session_id'] );
				if ( $session && ! self::has_free_seat( $session ) ) {
					return 'full';
				}
			}
			$wpdb->suppress_errors( true );
			$ok = $wpdb->update( Installer::table_name(), $row, array( 'id' => $id ) );
			$wpdb->suppress_errors( false );
			return false === $ok ? 'error' : 'ok';
		};
		$result = Catalog_Repository::with_session_lock( (int) $current['session_id'], $write );
		return $result ?? 'error';
	}

	/** Staff promote a waitlisted enrolment to confirmed, keeping every other field. */
	public static function promote( int $id ): string {
		$row = self::get( $id );
		if ( ! $row || Installer::STATUS_WAITLISTED !== $row['status'] ) {
			return 'error';
		}
		return self::update_admin( $id, Installer::STATUS_CONFIRMED, (bool) (int) $row['invoiced'], (string) $row['internal_note'] );
	}
}
