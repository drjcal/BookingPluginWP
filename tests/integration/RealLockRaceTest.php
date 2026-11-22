<?php
/**
 * The no-overbooking and unique-e-mail rules against the real MySQL named lock: genuinely
 * parallel PHP processes (one database connection each) post to the public enrolment endpoint at
 * the same moment. Everything else runs on the in-memory lock adapter; this is the one test that
 * proves the production adapter holds up.
 *
 * Test cases run inside a rolled-back transaction that other connections cannot see, so the
 * workers use the plugin's committed tables and empty them again (see tests/support/race-worker.php).
 */
class RealLockRaceTest extends WP_UnitTestCase {

	const WORKERS = 6;

	private function worker( array $args ) {
		$cmd  = array_merge( array( PHP_BINARY, dirname( __DIR__ ) . '/support/race-worker.php' ), array_map( 'strval', $args ) );
		$proc = proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $proc );
		return array( $proc, $pipes );
	}

	/** @return array<mixed> Decoded JSON the worker printed. */
	private function finish( array $worker ): array {
		list( $proc, $pipes ) = $worker;
		$out = stream_get_contents( $pipes[1] );
		$err = stream_get_contents( $pipes[2] );
		proc_close( $proc );
		$decoded = json_decode( $out, true );
		$this->assertIsArray( $decoded, "worker output: {$out} {$err}" );
		return $decoded;
	}

	public function test_parallel_connections_cannot_overbook_a_seat_or_duplicate_an_email(): void {
		$go    = tempnam( sys_get_temp_dir(), 'gzw-go' );
		unlink( $go );
		$setup = $this->finish( $this->worker( array( 'setup' ) ) );

		try {
			$workers = array();
			for ( $i = 0; $i < self::WORKERS; $i++ ) {
				$workers['seat'][]      = $this->worker( array( 'submit', $setup['seat'], "seat{$i}@example.nl", $go ) );
				$workers['duplicate'][] = $this->worker( array( 'submit', $setup['duplicate'], 'same@example.nl', $go ) );
			}
			sleep( 3 ); // Let every worker finish loading WordPress, then fire together.
			touch( $go );

			$seat      = array_map( fn( $w ) => $this->finish( $w ), $workers['seat'] );
			$duplicate = array_map( fn( $w ) => $this->finish( $w ), $workers['duplicate'] );
			$rows      = $this->finish( $this->worker( array( 'rows' ) ) );
		} finally {
			@unlink( $go );
			$this->finish( $this->worker( array( 'teardown' ) ) );
		}

		$by_session = static fn( int $id ) => array_values( array_filter( $rows, static fn( $r ) => (int) $r['session_id'] === $id ) );

		// 1-seat session: everyone is accepted, exactly one holds the seat.
		$this->assertSame( array_fill( 0, self::WORKERS, 201 ), array_column( $seat, 'http' ) );
		$this->assertCount( self::WORKERS, $by_session( $setup['seat'] ) );
		$this->assertCount( 1, array_filter( $by_session( $setup['seat'] ), static fn( $r ) => 'confirmed' === $r['status'] ) );

		// One e-mail address, many simultaneous attempts: one enrolment, the rest rejected as duplicates.
		$https = array_count_values( array_column( $duplicate, 'http' ) );
		$this->assertEquals( array( 201 => 1, 422 => self::WORKERS - 1 ), $https );
		$this->assertCount( 1, $by_session( $setup['duplicate'] ) );
	}
}
