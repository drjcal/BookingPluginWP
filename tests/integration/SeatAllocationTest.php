<?php
use GezonderInWerk\Booking\Admin_Page;
use GezonderInWerk\Booking\Catalog_Repository;
use GezonderInWerk\Booking\Enrolment_Repository;
use GezonderInWerk\Booking\Enrolments_Admin;
use GezonderInWerk\Booking\Installer;
use GezonderInWerk\Booking\Seat_Allocation;
use GezonderInWerk\Booking\Session_Lock;

/**
 * Seat allocation under lock timeouts and partial failure. Driven through the public enrolment
 * endpoint and the admin handlers; the only extra seam is the lock adapter.
 */
class SeatAllocationTest extends WP_UnitTestCase {

	private int $course;
	private Gzw_Memory_Lock $lock;

	public function set_up(): void {
		parent::set_up();
		Installer::grant_capabilities();
		$this->lock = new Gzw_Memory_Lock();
		Session_Lock::use_adapter( $this->lock );
		$this->course = Catalog_Repository::save_course( array( 'title' => 'Webinar', 'price_cents' => 9900 ) );
	}

	public function tear_down(): void {
		Session_Lock::use_adapter( null );
		remove_all_filters( 'query' );
		parent::tear_down();
	}

	private function make_session( array $overrides = array() ): int {
		return Catalog_Repository::save_session(
			$overrides + array(
				'course_id'    => $this->course,
				'session_date' => '2099-03-15',
				'start_time'   => '09:30',
				'end_time'     => '16:30',
				'location'     => 'Online',
				'is_open'      => 1,
			)
		);
	}

	private function params( string $target, string $email ): array {
		return array(
			'session_id'       => $target,
			'first_name'       => 'Renée',
			'last_name'        => 'de Vries',
			'email'            => $email,
			'employer'         => 'Arbodienst Oost',
			'invoice_name'     => 'Arbodienst Oost B.V.',
			'invoice_street'   => 'Kerkstraat 1',
			'invoice_postcode' => '5611 AB',
			'invoice_city'     => 'Eindhoven',
		);
	}

	private function rows(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . Installer::table_name() . ' ORDER BY id', ARRAY_A );
	}

	private function cycle_of( int $count ): array {
		$ids = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$ids[] = $this->make_session( array( 'session_date' => sprintf( '2099-0%d-15', $i ) ) );
		}
		return array( Catalog_Repository::save_cycle( array( 'title' => 'Cyclus', 'price_cents' => 24900, 'session_ids' => $ids ) ), $ids );
	}

	/** Make INSERTs into the enrolments table for one session fail at the database. */
	private function break_inserts_for( int $session ): void {
		global $wpdb;
		$wpdb->hide_errors(); // The injected failure is expected.
		$table = Installer::table_name();
		add_filter(
			'query',
			static fn( $sql ) => ( 0 === strpos( ltrim( $sql ), "INSERT INTO `{$table}`" ) && str_contains( $sql, "'{$session}'" ) ) ? 'INSERT INTO gzw_no_such_table VALUES (1)' : $sql
		);
	}

	private function redirect_of( callable $fn ): string {
		add_filter( 'wp_redirect', static function ( $location ) {
			throw new RuntimeException( $location );
		} );
		try {
			$fn();
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		} finally {
			remove_all_filters( 'wp_redirect' );
		}
		return '';
	}

	// --- Lock timeout ------------------------------------------------------------------------

	public function test_session_admin_shows_seats_and_waitlist_for_each_date(): void {
		$session = $this->make_session( array( 'capacity' => 1 ) );
		foreach ( array( 'a', 'b' ) as $n ) {
			Seat_Allocation::enrol( $this->params( (string) $session, "{$n}@example.nl" ) );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET = array( 'course' => (string) $this->course );
		ob_start();
		try {
			Admin_Page::render();
		} finally {
			$html = ob_get_clean();
			$_GET = array();
		}

		$this->assertStringContainsString( '1 van 1 bevestigd, 1 op wachtlijst', $html );
	}

	public function test_availability_reports_confirmed_capacity_waitlist_and_free_seats(): void {
		$session = $this->make_session( array( 'capacity' => 2 ) );
		foreach ( array( 'a', 'b', 'c' ) as $n ) {
			Seat_Allocation::enrol( array( 'session_id' => (string) $session, 'email' => "{$n}@example.nl" ) + $this->params( (string) $session, "{$n}@example.nl" ) );
		}

		$this->assertSame(
			array( 'confirmed' => 2, 'capacity' => 2, 'waitlisted' => 1, 'free' => 0 ),
			Seat_Allocation::availability( $session )
		);
	}

	public function test_availability_of_an_unlimited_session_has_no_capacity_and_unlimited_free_seats(): void {
		$session = $this->make_session();

		$this->assertSame( array( 'confirmed' => 0, 'capacity' => null, 'waitlisted' => 0, 'free' => null ), Seat_Allocation::availability( $session ) );
	}

	public function test_a_lock_timeout_gives_the_enrolee_a_retryable_message_and_stores_nothing(): void {
		$session = $this->make_session();
		$this->lock->hold_elsewhere( Session_Lock::name( $session ) );

		$response = Gzw_Submission::post( $this->params( (string) $session, 'a@example.nl' ) );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'busy', $response->get_data()['code'] );
		$this->assertStringContainsString( 'opnieuw', $response->get_data()['message'] );
		$this->assertArrayHasKey( 'Retry-After', $response->get_headers() );
		$this->assertCount( 0, $this->rows() );
	}

	public function test_a_lock_timeout_on_one_webinar_of_a_cycle_stores_nothing(): void {
		list( $cycle, $ids ) = $this->cycle_of( 3 );
		$this->lock->hold_elsewhere( Session_Lock::name( $ids[1] ) );

		$response = Gzw_Submission::post( $this->params( 'cycle-' . $cycle, 'a@example.nl' ) );

		$this->assertSame( 503, $response->get_status() );
		$this->assertCount( 0, $this->rows() );
	}

	public function test_the_session_lock_is_released_after_a_submission(): void {
		$session = $this->make_session();
		Gzw_Submission::post( $this->params( (string) $session, 'a@example.nl' ) );

		$this->assertFalse( $this->lock->is_held( Session_Lock::name( $session ) ) );
	}

	public function test_an_admin_status_edit_during_a_lock_timeout_reports_busy_and_changes_nothing(): void {
		$session = $this->make_session( array( 'capacity' => 1 ) );
		$id      = Gzw_Submission::post( $this->params( (string) $session, 'a@example.nl' ) )->get_data()['id'];
		$this->lock->hold_elsewhere( Session_Lock::name( $session ) );

		$this->assertSame( 'busy', Enrolment_Repository::update_admin( $id, 'cancelled', false, '' ) );
		$this->assertSame( 'confirmed', Enrolment_Repository::get( $id )['status'] );
	}

	// --- Capacity ----------------------------------------------------------------------------

	public function test_capacity_cannot_be_lowered_below_the_confirmed_count_and_the_admin_is_told(): void {
		$session = $this->make_session( array( 'capacity' => 3 ) );
		foreach ( array( 'a', 'b' ) as $who ) {
			Gzw_Submission::post( $this->params( (string) $session, $who . '@example.nl' ) );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post = array(
			'id'           => (string) $session,
			'course_id'    => (string) $this->course,
			'session_date' => '2099-03-15',
			'start_time'   => '09:30',
			'end_time'     => '16:30',
			'location'     => 'Online',
			'is_open'      => '1',
		);

		$_POST                = $post + array( 'capacity' => '1' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'gzw_save_session' );
		$location             = $this->redirect_of( static fn() => Admin_Page::handle_save_session() );
		$this->assertStringContainsString( 'capacity_below_confirmed', $location );
		$this->assertEquals( 3, Catalog_Repository::get_session( $session )['capacity'] );

		// Lowering to exactly the confirmed count is fine, and so is going unlimited.
		foreach ( array( '2' => 2, '' => null ) as $input => $expected ) {
			$_POST                = $post + array( 'capacity' => (string) $input );
			$_REQUEST['_wpnonce'] = wp_create_nonce( 'gzw_save_session' );
			$this->assertStringContainsString( 'saved', $this->redirect_of( static fn() => Admin_Page::handle_save_session() ) );
			$this->assertEquals( $expected, Catalog_Repository::get_session( $session )['capacity'] );
		}
	}

	public function test_cancelled_and_waitlisted_enrolments_do_not_count_against_a_lower_capacity(): void {
		$session = $this->make_session( array( 'capacity' => 1 ) );
		$first   = Gzw_Submission::post( $this->params( (string) $session, 'a@example.nl' ) )->get_data()['id'];
		Gzw_Submission::post( $this->params( (string) $session, 'b@example.nl' ) ); // Waitlisted.
		Enrolment_Repository::update_admin( $first, 'cancelled', false, '' );

		$this->assertGreaterThan( 0, Catalog_Repository::save_session( array( 'course_id' => $this->course, 'session_date' => '2099-03-15', 'start_time' => '09:30', 'end_time' => '16:30', 'location' => 'Online', 'is_open' => 1, 'capacity' => 1 ), $session ) );
	}

	// --- Cycles: all or nothing --------------------------------------------------------------

	public function test_a_cycle_submission_that_fails_halfway_leaves_no_partial_enrolments(): void {
		list( $cycle, $ids ) = $this->cycle_of( 3 );
		$this->break_inserts_for( $ids[2] );

		$response = Gzw_Submission::post( $this->params( 'cycle-' . $cycle, 'a@example.nl' ) );

		$this->assertSame( 500, $response->get_status() ?? 500 );
		$this->assertCount( 0, $this->rows(), 'the webinars placed before the failure are rolled back' );
	}

	public function test_a_rolled_back_cycle_submission_leaves_the_seats_free_for_the_next_person(): void {
		list( $cycle, $ids ) = $this->cycle_of( 2 );
		foreach ( $ids as $id ) {
			Catalog_Repository::save_session( array( 'course_id' => $this->course, 'session_date' => '2099-03-15', 'start_time' => '09:30', 'end_time' => '16:30', 'location' => 'Online', 'is_open' => 1, 'capacity' => 1 ), $id );
		}
		$this->break_inserts_for( $ids[1] );
		Gzw_Submission::post( $this->params( 'cycle-' . $cycle, 'a@example.nl' ) );
		remove_all_filters( 'query' );

		$response = Gzw_Submission::post( $this->params( 'cycle-' . $cycle, 'b@example.nl' ) );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'confirmed', $response->get_data()['status'] );
	}

	public function test_a_cycle_needs_at_least_two_sessions_in_the_repository_itself(): void {
		$one = $this->make_session();

		$this->assertSame( 0, Catalog_Repository::save_cycle( array( 'title' => 'Eén', 'price_cents' => 100, 'session_ids' => array( $one ) ) ) );
		$this->assertSame( 0, Catalog_Repository::save_cycle( array( 'title' => 'Dubbel', 'price_cents' => 100, 'session_ids' => array( $one, $one ) ) ) );
		$this->assertCount( 0, Catalog_Repository::cycles() );

		list( $cycle, $ids ) = $this->cycle_of( 2 );
		$this->assertSame( 0, Catalog_Repository::save_cycle( array( 'title' => 'Krimp', 'price_cents' => 100, 'session_ids' => array( $ids[0] ) ), $cycle ) );
		$this->assertCount( 2, Catalog_Repository::get_cycle( $cycle )['sessions'], 'a refused edit leaves the cycle as it was' );
	}

	public function test_a_cycle_save_that_fails_halfway_restores_the_previous_cycle(): void {
		list( $cycle, $ids ) = $this->cycle_of( 2 );
		$new = $this->make_session( array( 'session_date' => '2099-09-15' ) );
		global $wpdb;
		$wpdb->hide_errors();
		$links = Installer::cycle_sessions_table();
		add_filter(
			'query',
			static fn( $sql ) => ( 0 === strpos( ltrim( $sql ), "INSERT INTO `{$links}`" ) && str_contains( $sql, "'{$new}'" ) ) ? 'INSERT INTO gzw_no_such_table VALUES (1)' : $sql
		);

		$saved = Catalog_Repository::save_cycle( array( 'title' => 'Nieuw', 'price_cents' => 5, 'session_ids' => array( $ids[0], $new ) ), $cycle );

		$this->assertSame( 0, $saved );
		$restored = Catalog_Repository::get_cycle( $cycle );
		$this->assertSame( 'Cyclus', $restored['title'] );
		$this->assertSame( 24900, (int) $restored['price_cents'] );
		$this->assertEqualsCanonicalizing( $ids, array_map( 'intval', array_column( $restored['sessions'], 'id' ) ) );
	}

	public function test_a_new_cycle_whose_save_fails_halfway_is_not_left_behind(): void {
		global $wpdb;
		$wpdb->hide_errors();
		$a   = $this->make_session();
		$b   = $this->make_session( array( 'session_date' => '2099-04-15' ) );
		$links = Installer::cycle_sessions_table();
		add_filter(
			'query',
			static fn( $sql ) => ( 0 === strpos( ltrim( $sql ), "INSERT INTO `{$links}`" ) && str_contains( $sql, "'{$b}'" ) ) ? 'INSERT INTO gzw_no_such_table VALUES (1)' : $sql
		);

		$this->assertSame( 0, Catalog_Repository::save_cycle( array( 'title' => 'Half', 'price_cents' => 5, 'session_ids' => array( $a, $b ) ) ) );
		$this->assertCount( 0, Catalog_Repository::cycles() );
	}

	public function test_saving_and_deleting_a_cycle_wait_for_the_session_locks(): void {
		list( $cycle, $ids ) = $this->cycle_of( 2 );
		$this->lock->hold_elsewhere( Session_Lock::name( $ids[1] ) );

		$this->assertSame( 0, Catalog_Repository::save_cycle( array( 'title' => 'X', 'price_cents' => 1, 'session_ids' => $ids ), $cycle ) );
		$this->assertFalse( Catalog_Repository::delete_cycle( $cycle ) );
		$this->assertNotNull( Catalog_Repository::get_cycle( $cycle ) );
		$this->assertSame( 'Cyclus', Catalog_Repository::get_cycle( $cycle )['title'] );
	}

	public function test_an_admin_edit_judges_the_seat_on_the_status_at_lock_time_not_form_time(): void {
		$session = $this->make_session( array( 'capacity' => 1 ) );
		$a       = Gzw_Submission::post( $this->params( (string) $session, 'a@example.nl' ) )->get_data()['id'];
		$b       = Gzw_Submission::post( $this->params( (string) $session, 'b@example.nl' ) )->get_data()['id']; // Waitlisted.

		// Setting an already-confirmed enrolment to confirmed again must not be refused as "full".
		$this->assertSame( 'ok', Enrolment_Repository::update_admin( $a, 'confirmed', true, 'x' ) );
		// A waitlisted one cannot take the only seat.
		$this->assertSame( 'full', Enrolment_Repository::update_admin( $b, 'confirmed', false, '' ) );
	}
}
