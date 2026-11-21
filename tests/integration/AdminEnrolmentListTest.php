<?php
use GezonderInWerk\Booking\Catalog_Repository;
use GezonderInWerk\Booking\Enrolment_Repository;
use GezonderInWerk\Booking\Enrolments_Admin;
use GezonderInWerk\Booking\Installer;

/**
 * Staff-facing enrolment list and detail view.
 */
class AdminEnrolmentListTest extends WP_UnitTestCase {

	private int $course_a;
	private int $course_b;
	private int $session_a;
	private int $session_b;

	public function set_up(): void {
		parent::set_up();
		Installer::grant_capabilities();
		$this->course_a  = Catalog_Repository::save_course( array( 'title' => 'Ergonomie', 'price_cents' => 12500 ) );
		$this->course_b  = Catalog_Repository::save_course( array( 'title' => 'Vitaliteit', 'price_cents' => 9000 ) );
		$this->session_a = $this->make_session( $this->course_a );
		$this->session_b = $this->make_session( $this->course_b );
	}

	private function make_session( int $course_id ): int {
		return Catalog_Repository::save_session(
			array(
				'course_id'    => $course_id,
				'session_date' => '2027-03-15',
				'start_time'   => '09:30',
				'end_time'     => '16:30',
				'location'     => 'Eindhoven',
				'is_open'      => 1,
			)
		);
	}

	private function enrol( array $overrides = array() ): int {
		return Enrolment_Repository::insert(
			$overrides + array(
				'session_id'       => $this->session_a,
				'price_cents'      => 12500,
				'first_name'       => 'Renée',
				'last_name'        => 'de Vries',
				'email'            => 'renee@example.nl',
				'employer'         => 'Arbodienst Oost',
				'big_number'       => '12345678901',
				'invoice_name'     => 'Arbodienst Oost B.V.',
				'invoice_street'   => 'Kerkstraat 1',
				'invoice_postcode' => '5611 AB',
				'invoice_city'     => 'Eindhoven',
			)
		);
	}

	private function admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function emails( array $filters = array(), int $page = 1, int $per_page = 20 ): array {
		$result = Enrolment_Repository::query( $filters, $page, $per_page );
		return array_column( $result['rows'], 'email' );
	}

	private function render( array $get = array() ): string {
		$_GET = $get;
		ob_start();
		try {
			Enrolments_Admin::render();
		} finally {
			$out = ob_get_clean();
			$_GET = array();
		}
		return $out;
	}

	private function update( int $id, array $post ): string {
		$_POST                = $post + array( 'id' => (string) $id );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'gzw_update_enrolment' );
		$location             = '';
		$catch                = static function ( $url ) {
			throw new RuntimeException( $url );
		};
		add_filter( 'wp_redirect', $catch );
		try {
			Enrolments_Admin::handle_update();
		} catch ( RuntimeException $e ) {
			$location = $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $catch );
			$_POST = array();
			unset( $_REQUEST['_wpnonce'] );
		}
		return $location;
	}

	public function test_list_query_returns_session_and_course_and_is_newest_first(): void {
		$first  = $this->enrol( array( 'email' => 'a@example.nl', 'created_at' => '2027-01-01 10:00:00' ) );
		$second = $this->enrol( array( 'email' => 'b@example.nl', 'created_at' => '2027-01-02 10:00:00' ) );

		$result = Enrolment_Repository::query( array(), 1, 20 );

		$this->assertSame( 2, $result['total'] );
		$this->assertSame( array( $second, $first ), array_map( 'intval', array_column( $result['rows'], 'id' ) ) );
		$this->assertSame( 'Ergonomie', $result['rows'][0]['course_title'] );
	}

	public function test_list_paginates(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->enrol( array( 'email' => "p{$i}@example.nl", 'created_at' => "2027-01-0{$i} 10:00:00" ) );
		}

		$page1 = Enrolment_Repository::query( array(), 1, 2 );
		$page3 = Enrolment_Repository::query( array(), 3, 2 );

		$this->assertSame( 5, $page1['total'] );
		$this->assertCount( 2, $page1['rows'] );
		$this->assertCount( 1, $page3['rows'] );
	}

	public function test_filters_by_course_session_and_status(): void {
		$this->enrol( array( 'email' => 'a@example.nl' ) );
		$this->enrol( array( 'email' => 'b@example.nl', 'session_id' => $this->session_b ) );
		$this->enrol( array( 'email' => 'c@example.nl', 'status' => 'cancelled' ) );

		$this->assertEqualsCanonicalizing( array( 'a@example.nl', 'c@example.nl' ), $this->emails( array( 'course' => $this->course_a ) ) );
		$this->assertSame( array( 'b@example.nl' ), $this->emails( array( 'session' => $this->session_b ) ) );
		$this->assertSame( array( 'c@example.nl' ), $this->emails( array( 'status' => 'cancelled' ) ) );
	}

	public function test_search_matches_name_email_and_employer(): void {
		$this->enrol( array( 'email' => 'a@example.nl', 'first_name' => 'Anna', 'last_name' => 'Jansen', 'employer' => 'Zorggroep Noord' ) );
		$this->enrol( array( 'email' => 'b@example.nl', 'first_name' => 'Bram', 'last_name' => 'Peters', 'employer' => 'Fysio Zuid' ) );

		$this->assertSame( array( 'a@example.nl' ), $this->emails( array( 'search' => 'Jansen' ) ) );
		$this->assertSame( array( 'a@example.nl' ), $this->emails( array( 'search' => 'Anna Jansen' ) ) );
		$this->assertSame( array( 'b@example.nl' ), $this->emails( array( 'search' => 'b@example' ) ) );
		$this->assertSame( array( 'b@example.nl' ), $this->emails( array( 'search' => 'fysio' ) ) );
		$this->assertSame( array(), $this->emails( array( 'search' => 'niemand' ) ) );
	}

	public function test_search_treats_like_wildcards_literally(): void {
		$this->enrol( array( 'email' => 'a@example.nl' ) );
		$this->assertSame( array(), $this->emails( array( 'search' => '%' ) ) );
	}

	public function test_enrolment_without_matching_session_shows_as_unknown_and_does_not_break_list(): void {
		$orphan = $this->enrol( array( 'email' => 'old@example.nl', 'session_id' => 0, 'first_name' => 'Oud' ) );
		$this->enrol( array( 'email' => 'new@example.nl' ) );
		$this->admin();

		$this->assertCount( 2, $this->emails() );
		$this->assertSame( array( 'new@example.nl' ), $this->emails( array( 'course' => $this->course_a ) ) );
		$html = $this->render( array( 'page' => 'gzw-enrolments' ) );
		$this->assertStringContainsString( 'Onbekende sessie', $html );
		$detail = $this->render( array( 'page' => 'gzw-enrolments', 'enrolment' => (string) $orphan ) );
		$this->assertStringContainsString( 'Onbekende sessie', $detail );
	}

	public function test_list_renders_columns_from_field_definition(): void {
		$this->enrol();
		$this->admin();

		$html = $this->render();

		foreach ( array( 'Voornaam', 'Achternaam', 'E-mailadres', 'Werkzaam bij', 'BIG-registratienummer', 'Sessie', 'Status', 'Ingeschreven op' ) as $heading ) {
			$this->assertStringContainsString( $heading, $html );
		}
		$this->assertStringContainsString( 'renee@example.nl', $html );
		$this->assertStringContainsString( '12345678901', $html );
		$this->assertStringContainsString( 'Ergonomie', $html );
		$this->assertStringNotContainsString( 'Kerkstraat', $html, 'billing details belong in the detail view' );
	}

	public function test_detail_shows_every_stored_field_including_billing(): void {
		$id = $this->enrol( array( 'invoice_btw' => 'NL123456789B01', 'invoice_kvk' => '12345678' ) );
		$this->admin();

		$html = $this->render( array( 'enrolment' => (string) $id ) );

		foreach ( \GezonderInWerk\Booking\Enrolment_Fields::all() as $field ) {
			$this->assertStringContainsString( $field['label'], $html );
		}
		foreach ( array( 'Kerkstraat 1', '5611 AB', 'NL123456789B01', '12345678', 'Arbodienst Oost B.V.', 'gzw_update_enrolment' ) as $value ) {
			$this->assertStringContainsString( $value, $html );
		}
	}

	public function test_admin_can_change_status_invoiced_and_note_and_it_persists(): void {
		$id = $this->enrol();
		$this->admin();

		$location = $this->update( $id, array( 'status' => 'cancelled', 'invoiced' => '1', 'internal_note' => 'Factuur verstuurd op 1 mei' ) );

		$row = Enrolment_Repository::get( $id );
		$this->assertSame( 'cancelled', $row['status'] );
		$this->assertSame( '1', (string) $row['invoiced'] );
		$this->assertSame( 'Factuur verstuurd op 1 mei', $row['internal_note'] );
		$this->assertStringContainsString( 'enrolment=' . $id, $location );

		$this->update( $id, array( 'status' => 'confirmed', 'internal_note' => '' ) );
		$row = Enrolment_Repository::get( $id );
		$this->assertSame( 'confirmed', $row['status'] );
		$this->assertSame( '0', (string) $row['invoiced'] );
		$this->assertSame( '', $row['internal_note'] );
	}

	public function test_unknown_status_is_rejected_and_nothing_changes(): void {
		$id = $this->enrol();
		$this->admin();

		$location = $this->update( $id, array( 'status' => 'bogus', 'internal_note' => 'x' ) );

		$this->assertStringContainsString( 'gzw_notice=error', $location );
		$row = Enrolment_Repository::get( $id );
		$this->assertSame( 'confirmed', $row['status'] );
		$this->assertSame( '', $row['internal_note'] );
	}

	public function test_users_without_capability_are_denied_list_detail_and_update(): void {
		$id = $this->enrol();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		foreach ( array(
			fn() => $this->render(),
			fn() => $this->render( array( 'enrolment' => (string) $id ) ),
			fn() => $this->update( $id, array( 'status' => 'cancelled' ) ),
		) as $call ) {
			try {
				$call();
				$this->fail( 'Expected access to be denied.' );
			} catch ( WPDieException $e ) {
				$this->assertSame( 403, $e->getCode() );
			}
		}
		$this->assertSame( 'confirmed', Enrolment_Repository::get( $id )['status'] );
	}

	public function test_administrators_get_the_capability_by_default(): void {
		$this->assertTrue( get_role( 'administrator' )->has_cap( Enrolments_Admin::CAP ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Enrolments_Admin::CAP ) );
	}
}
