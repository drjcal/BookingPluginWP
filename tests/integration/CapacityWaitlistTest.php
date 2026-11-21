<?php
use GezonderInWerk\Booking\Admin_Page;
use GezonderInWerk\Booking\Catalog_Repository;
use GezonderInWerk\Booking\Enrolment_Repository;
use GezonderInWerk\Booking\Enrolments_Admin;
use GezonderInWerk\Booking\Installer;

/**
 * Capacity, waitlist, promotion and the atomic rules around them.
 */
class CapacityWaitlistTest extends WP_UnitTestCase {

	private int $course;

	public function set_up(): void {
		parent::set_up();
		Installer::grant_capabilities();
		$this->course = Catalog_Repository::save_course( array( 'title' => 'Live training', 'price_cents' => 12500 ) );
	}

	private function make_session( array $overrides = array() ): int {
		return Catalog_Repository::save_session(
			$overrides + array(
				'course_id'    => $this->course,
				'session_date' => '2099-03-15',
				'start_time'   => '09:30',
				'end_time'     => '16:30',
				'location'     => 'Eindhoven',
				'is_open'      => 1,
			)
		);
	}

	private function params( int $session, string $email ): array {
		return array(
			'session_id'       => $session,
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

	private function submit( int $session, string $email ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/gezonder-in-werk/v1/enrolments' );
		$request->set_body_params( $this->params( $session, $email ) );
		return rest_do_request( $request );
	}

	private function status_of( WP_REST_Response $response ): string {
		$row = Enrolment_Repository::get( (int) $response->get_data()['id'] );
		return $row['status'];
	}

	private function admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function redirect_of( callable $call ): string {
		$location = '';
		$catch    = static function ( $url ) {
			throw new RuntimeException( $url );
		};
		add_filter( 'wp_redirect', $catch );
		try {
			$call();
		} catch ( RuntimeException $e ) {
			$location = $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $catch );
			$_POST = array();
			unset( $_REQUEST['_wpnonce'] );
		}
		return $location;
	}

	private function admin_post( string $action, string $handler, array $post ): string {
		$_POST                = $post;
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );
		return $this->redirect_of( static fn() => call_user_func( array( Enrolments_Admin::class, $handler ) ) );
	}

	public function test_enrolment_exceeding_capacity_is_waitlisted_and_earlier_ones_confirmed(): void {
		$session = $this->make_session( array( 'capacity' => 2 ) );

		$first  = $this->submit( $session, 'a@example.nl' );
		$second = $this->submit( $session, 'b@example.nl' );
		$third  = $this->submit( $session, 'c@example.nl' );

		$this->assertSame( 'confirmed', $first->get_data()['status'] );
		$this->assertSame( 'confirmed', $second->get_data()['status'] );
		$this->assertSame( 201, $third->get_status() );
		$this->assertSame( 'waitlisted', $third->get_data()['status'] );
		$this->assertSame( 'waitlisted', $this->status_of( $third ) );
		$this->assertSame( 'confirmed', $this->status_of( $first ) );
	}

	public function test_confirmation_message_differs_for_confirmed_and_waitlisted(): void {
		$session = $this->make_session( array( 'capacity' => 1 ) );

		$confirmed  = $this->submit( $session, 'a@example.nl' )->get_data();
		$waitlisted = $this->submit( $session, 'b@example.nl' )->get_data();

		$this->assertNotSame( $confirmed['message'], $waitlisted['message'] );
		$this->assertStringContainsString( 'wachtlijst', $waitlisted['message'] );
		$this->assertStringNotContainsString( 'wachtlijst', $confirmed['message'] );
	}

	public function test_empty_capacity_means_unlimited(): void {
		$session = $this->make_session( array( 'capacity' => '' ) );

		foreach ( array( 'a', 'b', 'c', 'd' ) as $n ) {
			$this->assertSame( 'confirmed', $this->submit( $session, "$n@example.nl" )->get_data()['status'] );
		}
		$this->assertNull( Catalog_Repository::get_session( $session )['capacity'] );
	}

	public function test_waitlisted_enrolments_do_not_use_up_seats_and_cancelling_frees_one(): void {
		$session = $this->make_session( array( 'capacity' => 1 ) );
		$a       = $this->submit( $session, 'a@example.nl' );
		$b       = $this->submit( $session, 'b@example.nl' );
		$this->admin();

		$full = Enrolment_Repository::promote( (int) $b->get_data()['id'] );
		$this->assertSame( 'full', $full );
		$this->assertSame( 'waitlisted', $this->status_of( $b ) );

		Enrolment_Repository::update_admin( (int) $a->get_data()['id'], 'cancelled', false, '' );
		$this->assertSame( 'ok', Enrolment_Repository::promote( (int) $b->get_data()['id'] ) );
		$this->assertSame( 'confirmed', $this->status_of( $b ) );
	}

	public function test_staff_can_promote_a_waitlisted_enrolment_from_the_admin(): void {
		$session = $this->make_session( array( 'capacity' => 1 ) );
		$a       = $this->submit( $session, 'a@example.nl' );
		$b       = $this->submit( $session, 'b@example.nl' );
		$this->admin();
		Enrolment_Repository::update_admin( (int) $a->get_data()['id'], 'cancelled', false, '' );

		$id       = (int) $b->get_data()['id'];
		$location = $this->admin_post( 'gzw_promote_enrolment', 'handle_promote', array( 'id' => (string) $id ) );

		$this->assertStringContainsString( 'gzw_notice=saved', $location );
		$this->assertSame( 'confirmed', Enrolment_Repository::get( $id )['status'] );
	}

	public function test_promotion_is_refused_while_the_session_is_full(): void {
		$session = $this->make_session( array( 'capacity' => 1 ) );
		$this->submit( $session, 'a@example.nl' );
		$b = $this->submit( $session, 'b@example.nl' );
		$this->admin();

		$id       = (int) $b->get_data()['id'];
		$location = $this->admin_post( 'gzw_promote_enrolment', 'handle_promote', array( 'id' => (string) $id ) );

		$this->assertStringContainsString( 'gzw_notice=full', $location );
		$this->assertSame( 'waitlisted', Enrolment_Repository::get( $id )['status'] );
	}

	public function test_status_dropdown_cannot_confirm_into_a_full_session(): void {
		$session = $this->make_session( array( 'capacity' => 1 ) );
		$this->submit( $session, 'a@example.nl' );
		$b = $this->submit( $session, 'b@example.nl' );
		$this->admin();

		$id = (int) $b->get_data()['id'];
		$_POST                = array( 'id' => (string) $id, 'status' => 'confirmed' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'gzw_update_enrolment' );
		$location             = $this->redirect_of( static fn() => Enrolments_Admin::handle_update() );

		$this->assertStringContainsString( 'gzw_notice=full', $location );
		$this->assertSame( 'waitlisted', Enrolment_Repository::get( $id )['status'] );
	}

	public function test_admin_accepts_waitlisted_status_and_filters_and_shows_it(): void {
		$session = $this->make_session( array( 'capacity' => 1 ) );
		$this->submit( $session, 'a@example.nl' );
		$b = $this->submit( $session, 'b@example.nl' );
		$this->admin();
		$id = (int) $b->get_data()['id'];

		$result = Enrolment_Repository::query( array( 'status' => 'waitlisted' ), 1, 20 );
		$this->assertSame( array( 'b@example.nl' ), array_column( $result['rows'], 'email' ) );

		$_GET = array( 'status' => 'waitlisted' );
		ob_start();
		Enrolments_Admin::render();
		$list = ob_get_clean();
		$_GET = array( 'enrolment' => (string) $id );
		ob_start();
		Enrolments_Admin::render();
		$detail = ob_get_clean();
		$_GET = array();
		$this->assertStringContainsString( 'value="waitlisted"', $list );
		$this->assertStringContainsString( 'Wachtlijst', $list );
		$this->assertStringContainsString( 'Wachtlijst', $detail );
		$this->assertStringContainsString( 'gzw_promote_enrolment', $detail );

		$_POST                = array( 'id' => (string) $id, 'status' => 'waitlisted', 'internal_note' => 'x' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'gzw_update_enrolment' );
		$location             = $this->redirect_of( static fn() => Enrolments_Admin::handle_update() );
		$this->assertStringContainsString( 'gzw_notice=saved', $location );
	}

	public function test_waitlisted_enrolment_still_blocks_a_duplicate_email(): void {
		$session = $this->make_session( array( 'capacity' => 1 ) );
		$this->submit( $session, 'a@example.nl' );
		$this->submit( $session, 'b@example.nl' );

		$this->assertSame( 422, $this->submit( $session, 'b@example.nl' )->get_status() );
	}

	public function test_duplicate_rule_is_enforced_by_the_database_not_just_the_validator(): void {
		$session = $this->make_session();
		$values  = $this->params( $session, 'a@example.nl' ) + array( 'price_cents' => '12500' );

		$first  = Enrolment_Repository::enrol( $values );
		$second = Enrolment_Repository::enrol( $values );

		$this->assertGreaterThan( 0, $first['id'] );
		$this->assertSame( 0, $second['id'] );
		$this->assertSame( 'duplicate', $second['error'] );

		// Cancelled enrolments release the e-mail address again.
		Enrolment_Repository::update_admin( $first['id'], 'cancelled', false, '' );
		$this->assertGreaterThan( 0, Enrolment_Repository::enrol( $values )['id'] );
	}

	public function test_reactivating_a_cancelled_enrolment_that_would_duplicate_fails(): void {
		$session = $this->make_session();
		$values  = $this->params( $session, 'a@example.nl' ) + array( 'price_cents' => '12500' );
		$first   = Enrolment_Repository::enrol( $values );
		Enrolment_Repository::update_admin( $first['id'], 'cancelled', false, '' );
		Enrolment_Repository::enrol( $values );

		$this->assertSame( 'error', Enrolment_Repository::update_admin( $first['id'], 'confirmed', false, '' ) );
		$this->assertSame( 'cancelled', Enrolment_Repository::get( $first['id'] )['status'] );
	}

	public function test_sessions_in_the_past_are_not_offered_and_cannot_be_enrolled_in(): void {
		$past = $this->make_session( array( 'session_date' => '2000-01-01' ) );
		$this->make_session( array( 'location' => 'Toekomstlocatie' ) );

		$html = do_shortcode( '[gezonder_inschrijfformulier]' );
		$this->assertStringContainsString( 'Toekomstlocatie', $html );
		$this->assertStringNotContainsString( '01-2000', $html );

		$response = $this->submit( $past, 'a@example.nl' );
		$this->assertSame( 422, $response->get_status() );
		$this->assertArrayHasKey( 'session_id', $response->get_data()['errors'] );
	}

	public function test_session_closed_after_validation_cannot_receive_a_confirmed_enrolment(): void {
		$session = $this->make_session( array( 'capacity' => 5 ) );
		$values  = $this->params( $session, 'a@example.nl' ) + array( 'price_cents' => '12500' );

		// Close between validation and storage: the storage step re-checks under the session lock.
		global $wpdb;
		$wpdb->update( Installer::sessions_table(), array( 'is_open' => 0 ), array( 'id' => $session ) );
		$result = Enrolment_Repository::enrol( $values );

		$this->assertSame( 0, $result['id'] );
		$this->assertSame( 'closed', $result['error'] );
		$this->assertCount( 0, $wpdb->get_results( 'SELECT id FROM ' . Installer::table_name() ) );
	}

	public function test_closing_a_session_waits_for_the_session_lock(): void {
		$session = $this->make_session();
		$held    = Catalog_Repository::with_session_lock(
			$session,
			static function () use ( $session ) {
				// Re-entrant on the same connection; the closing save takes the same lock.
				return Catalog_Repository::save_session( array( 'course_id' => 1, 'is_open' => 0, 'session_date' => '2099-03-15', 'start_time' => '09:30', 'end_time' => '10:30', 'location' => 'x' ), $session );
			}
		);
		$this->assertSame( $session, $held );
	}

	public function test_capacity_is_configurable_in_the_session_admin_and_empty_means_unlimited(): void {
		$this->admin();
		$post = array(
			'course_id'    => (string) $this->course,
			'session_date' => '2099-05-01',
			'start_time'   => '09:00',
			'end_time'     => '12:00',
			'location'     => 'Utrecht',
			'is_open'      => '1',
		);

		foreach ( array( '30' => 30, '' => null ) as $input => $expected ) {
			$_POST                = $post + array( 'id' => '0', 'capacity' => (string) $input );
			$_REQUEST['_wpnonce'] = wp_create_nonce( 'gzw_save_session' );
			$this->redirect_of( static fn() => Admin_Page::handle_save_session() );
			$rows = Catalog_Repository::sessions_for_course( $this->course );
			$last = end( $rows );
			$this->assertEquals( $expected, $last['capacity'] );
		}

		foreach ( array( '0', '-3', 'abc', '1.5' ) as $bad ) {
			$_POST                = $post + array( 'id' => '0', 'capacity' => $bad );
			$_REQUEST['_wpnonce'] = wp_create_nonce( 'gzw_save_session' );
			$location             = $this->redirect_of( static fn() => Admin_Page::handle_save_session() );
			$this->assertStringContainsString( 'invalid_session', $location, $bad );
		}
	}

	public function test_schema_upgrade_runs_on_init_not_before_translations_are_available(): void {
		// The schema is built from translated field labels, so it must not run on plugins_loaded
		// (WordPress 6.7+ reports "textdomain loaded too early").
		$this->assertFalse( has_action( 'plugins_loaded', array( Installer::class, 'maybe_upgrade' ) ) );
		$this->assertNotFalse( has_action( 'init', array( Installer::class, 'maybe_upgrade' ) ) );

		update_option( Installer::OPTION_DB_VERSION, '1' );
		Installer::maybe_upgrade();
		$this->assertSame( Installer::DB_VERSION, get_option( Installer::OPTION_DB_VERSION ) );
	}
}
