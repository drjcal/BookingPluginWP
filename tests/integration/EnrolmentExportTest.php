<?php
use GezonderInWerk\Booking\Catalog_Repository;
use GezonderInWerk\Booking\Enrolment_Repository;
use GezonderInWerk\Booking\Enrolments_Admin;
use GezonderInWerk\Booking\Enrolments_Export;
use GezonderInWerk\Booking\Installer;

/**
 * CSV export of the admin enrolment list.
 */
class EnrolmentExportTest extends WP_UnitTestCase {

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

	/** Parse the CSV (after the BOM) into a header row plus rows keyed by header. */
	private function parse( string $csv ): array {
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv );
		$stream = fopen( 'php://temp', 'r+' );
		fwrite( $stream, substr( $csv, 3 ) );
		rewind( $stream );
		$header = fgetcsv( $stream, 0, ';', '"', '' );
		$rows   = array();
		while ( false !== ( $cells = fgetcsv( $stream, 0, ';', '"', '' ) ) ) {
			$rows[] = array_combine( $header, $cells );
		}
		fclose( $stream );
		return array( $header, $rows );
	}

	private function request( array $get, ?string $nonce_action = 'gzw_export_enrolments' ): void {
		$_GET = $get;
		if ( $nonce_action ) {
			$_REQUEST['_wpnonce'] = wp_create_nonce( $nonce_action );
		}
		add_filter( 'wp_die_handler', static fn() => static function ( $message ) {
			throw new RuntimeException( is_string( $message ) ? $message : 'die' );
		} );
		try {
			Enrolments_Export::handle_export();
		} finally {
			$_GET = array();
			unset( $_REQUEST['_wpnonce'] );
		}
	}

	public function test_export_has_bom_semicolon_dutch_headers_and_round_trips_accents(): void {
		$this->enrol();

		$csv = Enrolments_Export::build( array() );
		list( $header, $rows ) = $this->parse( $csv );

		$this->assertStringContainsString( 'Voornaam;Achternaam;', $csv );
		foreach ( array( 'Voornaam', 'E-mailadres', 'BIG-registratienummer', 'Factuurnaam', 'Postcode', 'KvK-nummer', 'BTW-nummer', 'Cursus', 'Status', 'Gefactureerd', 'Interne notitie' ) as $expected ) {
			$this->assertContains( $expected, $header );
		}
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Renée', $rows[0]['Voornaam'] );
		$this->assertSame( 'Ergonomie', $rows[0]['Cursus'] );
		$this->assertSame( 'Ergonomie – 15-03-2027, Eindhoven', $rows[0]['Sessie'] );
		$this->assertSame( '€ 125,00', $rows[0]['Prijs excl. BTW'] );
		$this->assertSame( '5611 AB', $rows[0]['Postcode'] );
		$this->assertSame( 'Bevestigd', $rows[0]['Status'] );
		$this->assertSame( 'Nee', $rows[0]['Gefactureerd'] );
	}

	public function test_session_cell_shows_a_placeholder_when_the_session_is_gone(): void {
		$this->enrol( array( 'session_id' => 999999 ) );

		$row = $this->parse( Enrolments_Export::build( array() ) )[1][0];

		$this->assertSame( 'Onbekende sessie', $row['Sessie'] );
		$this->assertSame( '', $row['Cursus'] );
	}

	public function test_export_respects_course_session_status_and_search_filters(): void {
		$this->enrol( array( 'email' => 'a@example.nl', 'first_name' => 'Anna' ) );
		$this->enrol( array( 'email' => 'b@example.nl', 'session_id' => $this->session_b ) );
		$this->enrol( array( 'email' => 'c@example.nl', 'status' => 'cancelled' ) );

		$emails = fn( array $filters ) => array_column( $this->parse( Enrolments_Export::build( $filters ) )[1], 'E-mailadres' );

		$this->assertCount( 3, $emails( array() ) );
		$this->assertEqualsCanonicalizing( array( 'a@example.nl', 'c@example.nl' ), $emails( array( 'course' => $this->course_a ) ) );
		$this->assertSame( array( 'b@example.nl' ), $emails( array( 'session' => $this->session_b ) ) );
		$this->assertSame( array( 'c@example.nl' ), $emails( array( 'status' => 'cancelled' ) ) );
		$this->assertSame( array( 'a@example.nl' ), $emails( array( 'search' => 'Anna' ) ) );
	}

	public function test_export_is_not_paginated(): void {
		for ( $i = 1; $i <= Enrolments_Admin::PER_PAGE + 5; $i++ ) {
			$this->enrol( array( 'email' => "p{$i}@example.nl" ) );
		}

		$this->assertCount( Enrolments_Admin::PER_PAGE + 5, $this->parse( Enrolments_Export::build( array() ) )[1] );
	}

	public function test_formula_injection_is_neutralised(): void {
		foreach ( array( '=1+1', '+31612345678', '-2+3', '@SUM(A1)', "\tcmd", "\rcmd" ) as $i => $payload ) {
			$this->enrol( array( 'email' => "x{$i}@example.nl", 'employer' => $payload ) );
		}

		$employers = array_column( $this->parse( Enrolments_Export::build( array() ) )[1], 'Werkzaam bij' );

		$this->assertCount( 6, $employers );
		foreach ( $employers as $cell ) {
			$this->assertStringStartsWith( "'", $cell );
		}
		$this->assertContains( "'=1+1", $employers );
	}

	public function test_harmless_values_are_left_alone_and_multiline_notes_survive(): void {
		$id = $this->enrol( array( 'employer' => 'Zorg; en meer "Noord"' ) );
		Enrolment_Repository::update_admin( $id, 'confirmed', true, "regel 1\nregel 2" );

		$row = $this->parse( Enrolments_Export::build( array() ) )[1][0];

		$this->assertSame( 'Zorg; en meer "Noord"', $row['Werkzaam bij'] );
		$this->assertSame( "regel 1\nregel 2", $row['Interne notitie'] );
		$this->assertSame( 'Ja', $row['Gefactureerd'] );
	}

	public function test_export_url_carries_filters_and_a_valid_nonce(): void {
		$url = Enrolments_Export::url( array( 'course' => $this->course_a, 'session' => 0, 'status' => 'cancelled', 'search' => 'Jansen' ) );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );

		$this->assertSame( 'gzw_export_enrolments', $q['action'] );
		$this->assertSame( (string) $this->course_a, $q['course'] );
		$this->assertSame( 'cancelled', $q['status'] );
		$this->assertSame( 'Jansen', $q['s'] );
		$this->assertArrayNotHasKey( 'session', $q );
		$this->assertNotFalse( wp_verify_nonce( $q['_wpnonce'], 'gzw_export_enrolments' ) );
	}

	public function test_list_page_offers_the_export_button(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET = array( 'status' => 'cancelled' );
		ob_start();
		Enrolments_Admin::render();
		$out  = ob_get_clean();
		$_GET = array();

		$this->assertStringContainsString( 'action=gzw_export_enrolments', $out );
		$this->assertStringContainsString( 'status=cancelled', $out );
	}

	public function test_users_without_the_capability_are_denied(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->expectException( RuntimeException::class );
		$this->request( array() );
	}

	public function test_requests_without_a_valid_nonce_are_denied(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->expectException( RuntimeException::class );
		$this->request( array(), null );
	}

	public function test_a_nonce_for_another_action_is_denied(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->expectException( RuntimeException::class );
		$this->request( array(), 'gzw_update_enrolment' );
	}
}
