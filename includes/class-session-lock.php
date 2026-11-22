<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * Where a named lock actually lives. The MySQL adapter serves production; tests swap in an
 * in-memory adapter so the no-overbooking rule can be exercised without racing connections.
 * Locks are re-entrant on one connection: acquiring a name you already hold succeeds.
 */
interface Lock_Adapter {

	/** @return bool Whether the lock was obtained within $timeout seconds. */
	public function acquire( string $name, int $timeout ): bool;

	public function release( string $name ): void;
}

/** MySQL named locks: work under autocommit and are released when the connection closes. */
class Mysql_Lock_Adapter implements Lock_Adapter {

	public function acquire( string $name, int $timeout ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout ) );
	}

	public function release( string $name ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}
}

/**
 * Per-session locks. Seat_Allocation is the only production caller that decides what to do
 * under them; the catalog uses them to keep session deletes and saves out of an enrolment in flight.
 */
class Session_Lock {

	const TIMEOUT = 10;

	/** @var ?Lock_Adapter */
	private static $adapter = null;

	/** Swap the lock adapter (tests); null restores MySQL named locks. */
	public static function use_adapter( ?Lock_Adapter $adapter ): void {
		self::$adapter = $adapter;
	}

	private static function adapter(): Lock_Adapter {
		return self::$adapter ?? new Mysql_Lock_Adapter();
	}

	/** Lock name for a session (lock names max 64 chars). */
	public static function name( int $session_id ): string {
		global $wpdb;
		return 'gzw_session_' . md5( $wpdb->prefix . $session_id );
	}

	/**
	 * Run $callback while holding the session's lock.
	 *
	 * @return mixed The callback's result, or null without running it when the lock is not obtained.
	 */
	public static function run( int $session_id, callable $callback ) {
		$adapter = self::adapter();
		$name    = self::name( $session_id );
		if ( ! $adapter->acquire( $name, self::TIMEOUT ) ) {
			return null;
		}
		try {
			return $callback();
		} finally {
			$adapter->release( $name );
		}
	}

	/**
	 * Run $callback while holding the locks of several sessions, taken in id order so two
	 * submissions over overlapping sessions cannot deadlock. Null when a lock is not obtained.
	 *
	 * @param int[] $session_ids
	 * @return mixed
	 */
	public static function run_all( array $session_ids, callable $callback ) {
		$session_ids = array_values( array_unique( array_map( 'intval', $session_ids ) ) );
		sort( $session_ids );
		$run = $callback;
		foreach ( array_reverse( $session_ids ) as $session_id ) {
			$inner = $run;
			$run   = static fn() => self::run( $session_id, $inner );
		}
		return $run();
	}
}
