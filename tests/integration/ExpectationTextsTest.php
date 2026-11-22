<?php
use GezonderInWerk\Booking\Catalog_Repository;
use GezonderInWerk\Booking\Enrolment_Repository;
use GezonderInWerk\Booking\Expectation_Texts;
use GezonderInWerk\Booking\Form_Shortcode;

/**
 * When the information arrives: the wording and send dates on the form, the endpoint response and
 * the captured e-mails, plus the working-day rule.
 */
class ExpectationTextsTest extends WP_UnitTestCase {

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

	private function session( string $title, string $date, string $format = 'webinar', ?int $capacity = null ): int {
		$course = Catalog_Repository::save_course( array( 'title' => $title, 'price_cents' => 9900 ) );
		return Catalog_Repository::save_session(
			array(
				'course_id'    => $course,
				'session_date' => $date,
				'start_time'   => '19:00',
				'end_time'     => '20:30',
				'location'     => 'Online',
				'is_open'      => 1,
				'capacity'     => $capacity,
				'format'       => $format,
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

	private function mail_to( string $to ): array {
		return array_values( array_filter( $this->mails, static fn( $m ) => $to === $m['to'] ) );
	}

	/* Working days */

	public function test_webinar_link_goes_out_the_working_day_before(): void {
		// Wednesday 2027-03-17 -> Tuesday.
		$this->assertSame( '2027-03-16', Expectation_Texts::send_date( array( 'session_date' => '2027-03-17', 'format' => 'webinar' ) ) );
	}

	public function test_a_weekend_before_the_session_is_skipped(): void {
		// Monday 2027-03-15 -> Friday 2027-03-12.
		$this->assertSame( '2027-03-12', Expectation_Texts::send_date( array( 'session_date' => '2027-03-15', 'format' => 'webinar' ) ) );
	}

	public function test_holidays_before_the_session_are_skipped(): void {
		// Tuesday 2027-03-30: Easter Monday (29th), the weekend and Good Friday (26th) are skipped.
		$this->assertSame( '2027-03-25', Expectation_Texts::send_date( array( 'session_date' => '2027-03-30', 'format' => 'webinar' ) ) );
		// Thursday 2026-05-14 (Ascension) is skipped for the Friday session after it: 2026-05-15 -> 13th.
		$this->assertSame( '2026-05-13', Expectation_Texts::send_date( array( 'session_date' => '2026-05-15', 'format' => 'webinar' ) ) );
	}

	public function test_dutch_holidays_of_a_year(): void {
		$holidays = Expectation_Texts::holidays( 2027 );
		foreach ( array( '2027-01-01', '2027-03-26', '2027-03-28', '2027-03-29', '2027-04-27', '2027-05-05', '2027-05-06', '2027-05-16', '2027-05-17', '2027-12-25', '2027-12-26' ) as $day ) {
			$this->assertArrayHasKey( $day, $holidays, $day );
		}
		// Koningsdag moves to the 26th when the 27th is a Sunday (2025-04-27).
		$this->assertArrayHasKey( '2025-04-26', Expectation_Texts::holidays( 2025 ) );
		$this->assertArrayNotHasKey( '2025-04-27', Expectation_Texts::holidays( 2025 ) );
	}

	public function test_live_training_information_goes_out_one_week_before(): void {
		$this->assertSame( '2027-03-08', Expectation_Texts::send_date( array( 'session_date' => '2027-03-15', 'format' => 'live' ) ) );
	}

	/* Wording */

	public function test_texts_show_the_rule_and_the_calculated_date(): void {
		$webinar = Expectation_Texts::for_session( array( 'session_date' => '2027-03-15', 'format' => 'webinar' ), '2027-01-01' );
		$this->assertSame( 'De link naar het webinar ontvang je uiterlijk vrijdag 12 maart (1 werkdag voor het webinar).', $webinar );

		$live = Expectation_Texts::for_session( array( 'session_date' => '2027-03-15', 'format' => 'live' ), '2027-01-01' );
		$this->assertStringContainsString( 'inclusief werkmaterialen', $live );
		$this->assertStringContainsString( 'uiterlijk maandag 8 maart (1 week voor de training)', $live );
	}

	public function test_late_enrolment_gets_the_as_soon_as_possible_variant(): void {
		$webinar = Expectation_Texts::for_session( array( 'session_date' => '2027-03-15', 'format' => 'webinar' ), '2027-03-14' );
		$this->assertStringContainsString( 'zo snel mogelijk', $webinar );
		$this->assertStringNotContainsString( 'uiterlijk', $webinar );

		$live = Expectation_Texts::for_session( array( 'session_date' => '2027-03-15', 'format' => 'live' ), '2027-03-09' );
		$this->assertStringContainsString( 'zo snel mogelijk', $live );
		// On the send date itself it is still on schedule.
		$this->assertStringContainsString( 'uiterlijk', Expectation_Texts::for_session( array( 'session_date' => '2027-03-15', 'format' => 'live' ), '2027-03-08' ) );
	}

	public function test_sessions_default_to_webinar_and_the_format_can_be_corrected(): void {
		$id = $this->session( 'Standaard', '2027-03-15' );
		$this->assertSame( 'webinar', Catalog_Repository::get_session( $id )['format'] );

		// A session saved without a format (an existing row) reads as webinar too.
		$course = Catalog_Repository::save_course( array( 'title' => 'Oud' ) );
		$old    = Catalog_Repository::save_session( array( 'course_id' => $course, 'session_date' => '2027-03-15', 'start_time' => '10:00', 'end_time' => '11:00', 'location' => 'X', 'is_open' => 1 ) );
		$this->assertSame( 'webinar', Catalog_Repository::get_session( $old )['format'] );

		Catalog_Repository::save_session( array( 'course_id' => $course, 'session_date' => '2027-03-15', 'start_time' => '10:00', 'end_time' => '11:00', 'location' => 'X', 'is_open' => 1, 'format' => 'live' ), $old );
		$this->assertSame( 'live', Catalog_Repository::get_session( $old )['format'] );
	}

	/* Form */

	public function test_form_shows_the_matching_text_per_session_and_per_webinar_of_a_cycle(): void {
		$web  = $this->session( 'Webinar Een', '2027-03-15' );
		$live = $this->session( 'Training Twee', '2027-04-15', 'live' );
		$cyc  = Catalog_Repository::save_cycle( array( 'title' => 'Cyclus', 'price_cents' => 19900, 'session_ids' => array( $web, $live ) ) );

		$html = Form_Shortcode::render();

		$this->assertMatchesRegularExpression( '/data-gzw-expectation="' . $web . '".*?vrijdag 12 maart/s', $html );
		$this->assertMatchesRegularExpression( '/data-gzw-expectation="' . $live . '".*?werkmaterialen.*?donderdag 8 april/s', $html );
		$this->assertMatchesRegularExpression( '/data-gzw-expectation="cycle-' . $cyc . '".*?Webinar Een.*?vrijdag 12 maart.*?Training Twee.*?donderdag 8 april/s', $html );
		$this->assertStringNotContainsString( 'betaling', strtolower( $html ) );
	}

	/* Endpoint and e-mail */

	public function test_confirmed_enrolment_gets_the_text_in_the_response_and_the_email(): void {
		$id       = $this->session( 'Webinar', '2027-03-15' );
		$response = Gzw_Submission::post( $this->valid( (string) $id ) );

		$this->assertSame( 201, $response->get_status() );
		$expectations = $response->get_data()['expectations'];
		$this->assertCount( 1, $expectations );
		$this->assertStringContainsString( 'uiterlijk vrijdag 12 maart', $expectations[0] );
		$this->assertStringContainsString( $expectations[0], $this->mail_to( 'renee@example.nl' )[0]['message'] );
	}

	public function test_live_training_email_names_materials_and_date(): void {
		$id = $this->session( 'Training', '2027-03-15', 'live' );
		Gzw_Submission::post( $this->valid( (string) $id ) );

		$body = $this->mail_to( 'renee@example.nl' )[0]['message'];
		$this->assertStringContainsString( 'werkmaterialen', $body );
		$this->assertStringContainsString( 'uiterlijk maandag 8 maart', $body );
	}

	public function test_late_enrolment_through_the_endpoint_gets_the_variant(): void {
		// A live training three days ahead: its send date (a week before) has already passed.
		$id       = $this->session( 'Training', gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +3 days' ) ), 'live' );
		$response = Gzw_Submission::post( $this->valid( (string) $id ) );

		$this->assertStringContainsString( 'zo snel mogelijk', $response->get_data()['expectations'][0] );
		$this->assertStringContainsString( 'zo snel mogelijk', $this->mail_to( 'renee@example.nl' )[0]['message'] );
	}

	public function test_cycle_email_lists_every_webinar_with_its_own_date(): void {
		$ids = array(
			$this->session( 'Webinar Een', '2027-03-15' ),
			$this->session( 'Webinar Twee', '2027-04-15' ),
			$this->session( 'Webinar Drie', '2027-05-15' ),
		);
		$cycle = Catalog_Repository::save_cycle( array( 'title' => 'Cyclus', 'price_cents' => 24900, 'session_ids' => $ids ) );

		$response = Gzw_Submission::post( $this->valid( 'cycle-' . $cycle ) );

		$this->assertCount( 3, $response->get_data()['expectations'] );
		$body = $this->mail_to( 'renee@example.nl' )[0]['message'];
		$this->assertStringContainsString( 'Webinar Een (15-03-2027): De link naar het webinar ontvang je uiterlijk vrijdag 12 maart', $body );
		$this->assertStringContainsString( 'uiterlijk woensdag 14 april', $body );
		$this->assertStringContainsString( 'uiterlijk vrijdag 14 mei', $body );
	}

	public function test_waitlisted_enrolment_gets_no_promise_until_it_is_promoted(): void {
		$id = $this->session( 'Vol', '2027-03-15', 'webinar', 1 );
		$first = Gzw_Submission::post( $this->valid( (string) $id, 'eerste@example.nl' ) );
		$this->mails = array();

		$response = Gzw_Submission::post( $this->valid( (string) $id ) );

		$this->assertSame( 'waitlisted', $response->get_data()['status'] );
		$this->assertSame( array(), $response->get_data()['expectations'] );
		$this->assertStringNotContainsString( 'uiterlijk', $this->mail_to( 'renee@example.nl' )[0]['message'] );

		// A seat frees up and staff promote: now the text is sent.
		Enrolment_Repository::update_admin( (int) $first->get_data()['id'], 'cancelled', false, '' );
		$waitlisted = (int) $response->get_data()['id'];
		$this->mails = array();
		$this->assertSame( 'ok', Enrolment_Repository::promote( $waitlisted ) );

		$mails = $this->mail_to( 'renee@example.nl' );
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'bevestigd', $mails[0]['subject'] );
		$this->assertStringContainsString( 'uiterlijk vrijdag 12 maart', $mails[0]['message'] );
	}

	public function test_no_payment_condition_is_mentioned(): void {
		$id = $this->session( 'Webinar', '2027-03-15' );
		$response = Gzw_Submission::post( $this->valid( (string) $id ) );
		$all      = strtolower( implode( ' ', $response->get_data()['expectations'] ) . $this->mail_to( 'renee@example.nl' )[0]['message'] );
		$this->assertStringNotContainsString( 'betaling', $all );
		$this->assertStringNotContainsString( 'betaald', $all );
	}
}
