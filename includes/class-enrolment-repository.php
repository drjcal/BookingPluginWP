<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

class Enrolment_Repository {

	/** Raw insert (seeding rows); status defaults to confirmed, no capacity check. See Seat_Allocation. */
	public static function insert( array $values ): int {
		return Seat_Allocation::insert( $values );
	}

	/** @see Seat_Allocation::enrol() */
	public static function enrol( array $values ): array {
		return Seat_Allocation::enrol( $values );
	}

	/** @see Seat_Allocation::enrol_cycle() */
	public static function enrol_cycle( array $values, array $cycle ): array {
		return Seat_Allocation::enrol_cycle( $values, $cycle );
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
		if ( ! empty( $filters['group'] ) ) {
			$where[] = 'e.group_reference = %s';
			$args[]  = (string) $filters['group'];
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

	/** @see Seat_Allocation::update_admin() */
	public static function update_admin( int $id, string $status, bool $invoiced, string $note ): string {
		return Seat_Allocation::update_admin( $id, $status, $invoiced, $note );
	}

	/** @see Seat_Allocation::promote() */
	public static function promote( int $id ): string {
		return Seat_Allocation::promote( $id );
	}

	/**
	 * Bundle price of a cycle submission: the sum over the group, cancelled enrolments included,
	 * so cancelling the enrolment that carries the price does not hide it. 0 for no group.
	 */
	public static function group_total( string $reference ): int {
		global $wpdb;
		if ( '' === $reference ) {
			return 0;
		}
		$table = Installer::table_name();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(price_cents), 0) FROM {$table} WHERE group_reference = %s", $reference ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- table from Installer.
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
