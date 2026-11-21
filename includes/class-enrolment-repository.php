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
			static fn(): array => self::place( $session_id, $values )
		);
		return $result ?? array( 'id' => 0, 'status' => '', 'error' => 'storage' );
	}

	/**
	 * Store a cycle submission: one enrolment per webinar sharing a group reference, all or nothing.
	 * The bundle price is recorded once, on the first webinar; the others carry 0, so the group
	 * totals the bundle price exactly. Each webinar keeps its own seat/waitlist outcome.
	 * All session locks are held for the whole submission.
	 *
	 * @param array<string,string> $values Validated values (price_cents and session_id are replaced per webinar).
	 * @param array                $cycle  Cycle row with its 'sessions'.
	 * @return array{id:int,ids:int[],status:string,group_reference:string,error:?string}
	 *         status: waitlisted when any webinar is, otherwise confirmed. error: closed | duplicate | storage.
	 */
	public static function enrol_cycle( array $values, array $cycle ): array {
		$failed = array( 'id' => 0, 'ids' => array(), 'status' => '', 'group_reference' => '', 'error' => 'storage' );
		$ids    = array_map( static fn( array $s ) => (int) $s['id'], $cycle['sessions'] );

		$result = Catalog_Repository::with_session_locks(
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
					$result = self::place(
						$session_id,
						array(
							'session_id'      => (string) $session_id,
							'price_cents'     => (string) ( 0 === $position ? (int) $cycle['price_cents'] : 0 ),
							'group_reference' => $reference,
						) + $values
					);
					if ( $result['error'] ) {
						global $wpdb;
						if ( $created ) {
							$table = Installer::table_name();
							$wpdb->query( "DELETE FROM {$table} WHERE id IN (" . implode( ',', array_map( 'intval', $created ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL -- integers only.
						}
						return array( 'error' => $result['error'] ) + $failed;
					}
					$created[] = $result['id'];
					$waitlist  = $waitlist || Installer::STATUS_WAITLISTED === $result['status'];
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
		return $result ?? $failed;
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
		$status = self::has_free_seat( $session ) ? Installer::STATUS_CONFIRMED : Installer::STATUS_WAITLISTED;
		$id     = self::insert( array( 'status' => $status ) + $values );
		if ( $id ) {
			return array( 'id' => $id, 'status' => $status, 'error' => null );
		}
		global $wpdb;
		return array( 'id' => 0, 'status' => '', 'error' => false !== stripos( (string) $wpdb->last_error, 'duplicate entry' ) ? 'duplicate' : 'storage' );
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

	/** Ids of the enrolments that belong together: a cycle submission shares a group reference. */
	private static function group_ids( int $id ): array {
		global $wpdb;
		$table = Installer::table_name();
		$ref   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT group_reference FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- table from Installer.
		if ( '' === $ref ) {
			return array( $id );
		}
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE group_reference = %s", $ref ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Staff erase an enrolment and its personal data. A cycle submission is one person's data
	 * across several rows, so the whole group goes together.
	 *
	 * @return int Number of rows removed (0 when the enrolment does not exist or the delete failed).
	 */
	public static function delete( int $id ): int {
		global $wpdb;
		if ( ! self::get( $id ) ) {
			return 0;
		}
		$ids   = self::group_ids( $id );
		$table = Installer::table_name();
		$done  = $wpdb->query( "DELETE FROM {$table} WHERE id IN (" . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL -- integers only.
		return false === $done ? 0 : (int) $done;
	}

	/**
	 * Strip the personal data of an enrolment (and its cycle group), keeping what reporting
	 * needs: session, price, status, invoiced flag, group reference and the consent record.
	 * The unique e-mail value is replaced per row and the active-session key released, so
	 * anonymised rows can never collide on (session, email).
	 *
	 * @return int Number of rows anonymised.
	 */
	public static function anonymise( int $id ): int {
		global $wpdb;
		$count = 0;
		$wpdb->suppress_errors( true );
		foreach ( self::group_ids( $id ) as $row_id ) {
			$row = array(
				'internal_note'     => '',
				'active_session_id' => null,
				'anonymised_at'     => current_time( 'mysql', true ),
			);
			foreach ( Enrolment_Fields::all() as $name => $field ) {
				if ( empty( $field['retain'] ) ) {
					$row[ $name ] = 'email' === $name ? 'anonymised-' . $row_id . '@anonymised.invalid' : '';
				}
			}
			if ( false !== $wpdb->update( Installer::table_name(), $row, array( 'id' => $row_id ) ) ) {
				++$count;
			}
		}
		$wpdb->suppress_errors( false );
		return $count;
	}

	/**
	 * Anonymise every enrolment whose data is older than the cut-off. Age is the date of the
	 * session (the creation date when the session is gone); a cycle group is as old as its
	 * latest webinar, so its rows expire together.
	 *
	 * @param string $cutoff Y-m-d; enrolments dated before it expire.
	 * @return int Rows anonymised.
	 */
	public static function anonymise_expired( string $cutoff ): int {
		global $wpdb;
		$e   = Installer::table_name();
		$s   = Installer::sessions_table();
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.PreparedSQL -- tables from Installer.
			$wpdb->prepare(
				"SELECT MIN(e.id) FROM {$e} e LEFT JOIN {$s} s ON s.id = e.session_id
				WHERE e.anonymised_at IS NULL
				GROUP BY IF(e.group_reference <> '', e.group_reference, CONCAT('id-', e.id))
				HAVING MAX(COALESCE(s.session_date, DATE(e.created_at))) < %s",
				$cutoff
			)
		);
		$count = 0;
		foreach ( $ids as $id ) {
			$count += self::anonymise( (int) $id );
		}
		return $count;
	}
}
