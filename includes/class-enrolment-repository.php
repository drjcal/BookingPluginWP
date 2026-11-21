<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

class Enrolment_Repository {

	/**
	 * @param array<string,string> $values Validated values.
	 * @return int New enrolment id.
	 */
	public static function insert( array $values ): int {
		global $wpdb;

		$row = $values + array(
			'status'        => Installer::STATUS_DEFAULT,
			'internal_note' => '',
			'created_at'    => current_time( 'mysql', true ),
		);

		$ok = $wpdb->insert( Installer::table_name(), $row );
		if ( false === $ok ) {
			return 0;
		}
		return (int) $wpdb->insert_id;
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

	const STATUSES = array( Installer::STATUS_CONFIRMED, Installer::STATUS_CANCELLED );

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

		$clause = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$offset = max( 0, ( $page - 1 ) * $per_page );

		$rows  = self::select_with_session( $clause, $args, sprintf( 'ORDER BY e.created_at DESC, e.id DESC LIMIT %d OFFSET %d', $per_page, $offset ) );
		$total = (int) self::scalar( $clause, $args );

		return array( 'rows' => $rows, 'total' => $total );
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

	/** Staff edits: status (ignored when unknown), invoiced flag and internal note. */
	public static function update_admin( int $id, string $status, bool $invoiced, string $note ): bool {
		global $wpdb;
		$row = array(
			'invoiced'      => $invoiced ? 1 : 0,
			'internal_note' => $note,
		);
		if ( in_array( $status, self::STATUSES, true ) ) {
			$row['status'] = $status;
		}
		return false !== $wpdb->update( Installer::table_name(), $row, array( 'id' => $id ) );
	}
}
