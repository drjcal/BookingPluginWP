<?php
use GezonderInWerk\Booking\Catalog_Repository;
use GezonderInWerk\Booking\Settings_Page;

/**
 * Confirmation and admin notification e-mails, asserted on captured outgoing mail.
 */
class EmailTest extends WP_UnitTestCase {

	/** @var array<int,array> Captured wp_mail() arguments. */
	private array $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $null, $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);
	}

	private function session( string $title, string $date, ?int $capacity = null, int $price = 9900 ): int {
		$course = Catalog_Repository::save_course( array( 'title' => $title, 'price_cents' => $price ) );
		return Catalog_Repository::save_session(
			array(
				'course_id'    => $course,
				'session_date' => $date,
				'start_time'   => '19:00',
				'end_time'     => '20:30',
				'location'     => 'Online',
				'is_open'      => 1,
				'capacity'     => $capacity,
			)
		);
	}

	private function valid( string $target, string $email = 'renee@example.nl' ): array {
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

	private function submit( array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/gezonder-in-werk/v1/enrolments' );
		$request->set_body_params( $params );
		return rest_do_request( $request );
	}

	private function mails_to( string $to ): array {
		return array_values( array_filter( $this->mails, static fn( $m ) => $to === $m['to'] ) );
	}

	public function test_confirmed_enrolment_sends_summary_to_the_enrolee(): void {
		$id       = $this->session( 'Nascholing Rugpijn', '2027-03-15' );
		$response = $this->submit( $this->valid( (string) $id ) );

		$this->assertSame( 201, $response->get_status() );
		$mails = $this->mails_to( 'renee@example.nl' );
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'bevestigd', $mails[0]['subject'] );
		$this->assertStringContainsString( 'Renée', $mails[0]['message'] );
		$this->assertStringContainsString( 'Nascholing Rugpijn', $mails[0]['message'] );
		$this->assertStringContainsString( '15-03-2027', $mails[0]['message'] );
		$this->assertStringContainsString( '€ 99,00', $mails[0]['message'] );
		$this->assertStringContainsString( $response->get_data()['message'], $mails[0]['message'] );
	}

	public function test_waitlisted_enrolment_states_that_no_seat_is_confirmed(): void {
		$id = $this->session( 'Vol', '2027-03-15', 1 );
		$this->submit( $this->valid( (string) $id, 'eerste@example.nl' ) );
		$this->mails = array();

		$response = $this->submit( $this->valid( (string) $id ) );

		$mails = $this->mails_to( 'renee@example.nl' );
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'wachtlijst', strtolower( $mails[0]['subject'] ) );
		$this->assertStringContainsString( 'nog niet bevestigd', $mails[0]['message'] );
		$this->assertStringContainsString( $response->get_data()['message'], $mails[0]['message'] );
	}

	public function test_admin_notification_goes_to_the_configured_address(): void {
		update_option( Settings_Page::OPTION_NOTIFICATION_EMAIL, 'planning@example.nl' );
		$id = $this->session( 'Nascholing Rugpijn', '2027-03-15' );

		$this->submit( $this->valid( (string) $id ) );

		$mails = $this->mails_to( 'planning@example.nl' );
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'Renée de Vries', $mails[0]['message'] );
		$this->assertStringContainsString( 'Nascholing Rugpijn', $mails[0]['message'] );
	}

	public function test_admin_notification_defaults_to_the_site_admin_address(): void {
		$id = $this->session( 'Nascholing Rugpijn', '2027-03-15' );
		$this->submit( $this->valid( (string) $id ) );
		$this->assertCount( 1, $this->mails_to( get_option( 'admin_email' ) ) );
	}

	public function test_no_mail_for_rejected_submissions(): void {
		$this->submit( array( 'session_id' => '999' ) );
		$this->assertSame( array(), $this->mails );
	}

	public function test_cycle_sends_one_confirmation_with_each_webinar_status_and_the_bundle_total(): void {
		$ids = array(
			$this->session( 'Webinar Een', '2027-03-15' ),
			$this->session( 'Webinar Twee', '2027-04-15', 1 ),
			$this->session( 'Webinar Drie', '2027-05-15' ),
		);
		$cycle = Catalog_Repository::save_cycle( array( 'title' => 'Cyclus X', 'price_cents' => 24900, 'session_ids' => $ids ) );
		$this->submit( $this->valid( (string) $ids[1], 'bezet@example.nl' ) );
		$this->mails = array();

		$response = $this->submit( $this->valid( 'cycle-' . $cycle ) );

		$this->assertSame( 201, $response->get_status() );
		$mails = $this->mails_to( 'renee@example.nl' );
		$this->assertCount( 1, $mails );
		$body = $mails[0]['message'];
		foreach ( array( 'Webinar Een', 'Webinar Twee', 'Webinar Drie' ) as $title ) {
			$this->assertStringContainsString( $title, $body );
		}
		$this->assertStringContainsString( '€ 249,00', $body );
		$this->assertStringNotContainsString( '€ 99,00', $body );
		$this->assertSame( 1, substr_count( $body, 'Wachtlijst' ) );
		$this->assertSame( 2, substr_count( $body, 'Bevestigd' ) );
		$this->assertStringContainsString( $response->get_data()['message'], $body );
		$this->assertCount( 1, $this->mails_to( get_option( 'admin_email' ) ) );
		$this->assertCount( 2, $this->mails );
	}

	public function test_settings_save_stores_a_valid_notification_address_and_rejects_an_invalid_one(): void {
		$this->assertSame( 'a@example.nl', Settings_Page::sanitize_email( ' a@example.nl ' ) );
		$this->assertSame( '', Settings_Page::sanitize_email( 'geen-adres' ) );
		update_option( Settings_Page::OPTION_NOTIFICATION_EMAIL, '' );
		$this->assertSame( get_option( 'admin_email' ), Settings_Page::notification_email() );
	}
}
