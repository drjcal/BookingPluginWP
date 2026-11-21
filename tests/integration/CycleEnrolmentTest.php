<?php
use GezonderInWerk\Booking\Catalog_Repository;

/**
 * Webinar cycle enrolment, driven through the public submission endpoint only.
 */
class CycleEnrolmentTest extends WP_UnitTestCase {

	/** @var int[] Session ids of the three webinars in the cycle, in date order. */
	private array $session_ids = array();
	private int $cycle_id;

	public function set_up(): void {
		parent::set_up();
		foreach ( array( '2027-03-15', '2027-04-15', '2027-05-15' ) as $i => $date ) {
			$course               = Catalog_Repository::save_course( array( 'title' => 'Webinar ' . ( $i + 1 ), 'price_cents' => 9900 ) );
			$this->session_ids[] = Catalog_Repository::save_session(
				array(
					'course_id'    => $course,
					'session_date' => $date,
					'start_time'   => '19:00',
					'end_time'     => '20:30',
					'location'     => 'Online',
					'is_open'      => 1,
				)
			);
		}
		$this->cycle_id = Catalog_Repository::save_cycle(
			array(
				'title'       => 'Cyclus Duurzame inzetbaarheid',
				'price_cents' => 24900,
				'session_ids' => $this->session_ids,
			)
		);
	}

	private function valid( string $target = '' ): array {
		return array(
			'session_id'       => '' !== $target ? $target : 'cycle-' . $this->cycle_id,
			'first_name'       => 'Renée',
			'last_name'        => 'de Vries',
			'email'            => 'renee@example.nl',
			'employer'         => 'Arbodienst Oost',
			'invoice_name'     => 'Arbodienst Oost B.V.',
			'invoice_street'   => 'Kerkstraat 1',
			'invoice_postcode' => '5611 AB',
			'invoice_city'     => 'Eindhoven',
		);
	}

	private function submit( array $params ): WP_REST_Response {
		return Gzw_Submission::post( $params );
	}

	private function stored(): array {
		global $wpdb;
		return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}gzw_enrolments ORDER BY id", ARRAY_A );
	}

	public function test_cycle_submission_creates_one_linked_enrolment_per_webinar_totalling_the_bundle_price(): void {
		$response = $this->submit( $this->valid() );

		$this->assertSame( 201, $response->get_status() );
		$rows = $this->stored();
		$this->assertCount( 3, $rows );
		$this->assertEqualsCanonicalizing( $this->session_ids, array_map( 'intval', array_column( $rows, 'session_id' ) ) );

		$references = array_unique( array_column( $rows, 'group_reference' ) );
		$this->assertCount( 1, $references );
		$this->assertNotSame( '', $references[0] );

		$this->assertSame( 24900, array_sum( array_map( 'intval', array_column( $rows, 'price_cents' ) ) ) );
		foreach ( $rows as $row ) {
			$this->assertSame( 'confirmed', $row['status'] );
			$this->assertSame( 'renee@example.nl', $row['email'] );
		}
	}

	public function test_single_enrolment_has_no_group_reference_and_the_course_price(): void {
		$this->assertSame( 201, $this->submit( $this->valid( (string) $this->session_ids[0] ) )->get_status() );

		$rows = $this->stored();
		$this->assertCount( 1, $rows );
		$this->assertSame( '', $rows[0]['group_reference'] );
		$this->assertSame( '9900', $rows[0]['price_cents'] );
	}

	public function test_each_cycle_submission_gets_its_own_group_reference(): void {
		$this->submit( $this->valid() );
		$second          = $this->valid();
		$second['email'] = 'jan@example.nl';
		$this->submit( $second );

		$this->assertCount( 2, array_unique( array_column( $this->stored(), 'group_reference' ) ) );
	}

	public function test_client_cannot_set_the_group_reference(): void {
		$params                    = $this->valid( (string) $this->session_ids[0] );
		$params['group_reference'] = 'FORGED';
		$this->submit( $params );

		$this->assertSame( '', $this->stored()[0]['group_reference'] );
	}

	public function test_closed_webinar_in_the_cycle_rejects_the_whole_submission(): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'gzw_sessions', array( 'is_open' => 0 ), array( 'id' => $this->session_ids[1] ) );

		$response = $this->submit( $this->valid() );

		$this->assertSame( 422, $response->get_status() );
		$this->assertArrayHasKey( 'session_id', $response->get_data()['errors'] );
		$this->assertCount( 0, $this->stored() );
	}

	public function test_existing_enrolment_for_one_webinar_rejects_the_whole_cycle_submission(): void {
		$this->assertSame( 201, $this->submit( $this->valid( (string) $this->session_ids[2] ) )->get_status() );

		$response = $this->submit( $this->valid() );

		$this->assertSame( 422, $response->get_status() );
		$this->assertArrayHasKey( 'email', $response->get_data()['errors'] );
		$this->assertCount( 1, $this->stored() );
	}

	public function test_full_webinar_waitlists_only_that_enrolment(): void {
		Catalog_Repository::save_session(
			array(
				'course_id'    => (int) Catalog_Repository::get_session( $this->session_ids[0] )['course_id'],
				'session_date' => '2027-03-15',
				'start_time'   => '19:00',
				'end_time'     => '20:30',
				'location'     => 'Online',
				'is_open'      => 1,
				'capacity'     => 1,
			),
			$this->session_ids[0]
		);
		$other          = $this->valid( (string) $this->session_ids[0] );
		$other['email'] = 'jan@example.nl';
		$this->submit( $other );

		$response = $this->submit( $this->valid() );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'waitlisted', $response->get_data()['status'] );
		$by_session = array_column( $this->stored(), 'status', 'session_id' );
		$this->assertSame( 'waitlisted', $by_session[ $this->session_ids[0] ] );
		$this->assertSame( 'confirmed', $by_session[ $this->session_ids[1] ] );
		$this->assertSame( 'confirmed', $by_session[ $this->session_ids[2] ] );
	}

	public function test_unknown_or_empty_cycle_is_rejected(): void {
		$this->assertSame( 422, $this->submit( $this->valid( 'cycle-999999' ) )->get_status() );

		$empty = Catalog_Repository::save_cycle( array( 'title' => 'Leeg', 'price_cents' => 100, 'session_ids' => array() ) );
		$this->assertSame( 422, $this->submit( $this->valid( 'cycle-' . $empty ) )->get_status() );
		$this->assertCount( 0, $this->stored() );
	}

	public function test_bundle_price_that_does_not_divide_evenly_is_still_recorded_exactly_once(): void {
		Catalog_Repository::save_cycle( array( 'title' => 'Cyclus', 'price_cents' => 24901, 'session_ids' => $this->session_ids ), $this->cycle_id );

		$this->submit( $this->valid() );

		$this->assertSame( 24901, array_sum( array_map( 'intval', array_column( $this->stored(), 'price_cents' ) ) ) );
	}
}
