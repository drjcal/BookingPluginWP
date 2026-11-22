<?php
use GezonderInWerk\Booking\Catalog_Repository;
use GezonderInWerk\Booking\Enrolment_Intake;
use GezonderInWerk\Booking\Enrolment_Repository;
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

	public function tear_down(): void {
		Enrolment_Intake::use_mail_adapter( null );
		delete_option( Settings_Page::OPTION_NOTIFICATION_EMAIL );
		delete_option( Settings_Page::OPTION_LOGO_ID );
		delete_option( Settings_Page::OPTION_LOGO_URL );
		delete_option( Settings_Page::OPTION_ORG_NAME );
		delete_option( Settings_Page::OPTION_CONTACT_EMAIL );
		parent::tear_down();
	}

	private function session( string $title, string $date, ?int $capacity = null, int $price = 9900, string $format = 'webinar' ): int {
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

	private function submit( array $params ): WP_REST_Response {
		return Gzw_Submission::post( $params );
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

	public function test_confirmation_and_admin_notification_sent_as_html_with_plain_text_alternative(): void {
		$recorder = new Gzw_Mail_Recorder();
		Enrolment_Intake::use_mail_adapter( $recorder );

		$id = $this->session( 'Nascholing Neurodiversiteit', '2027-03-15' );
		$this->submit( $this->valid( (string) $id ) );

		$this->assertCount( 2, $recorder->sent );
		$enrolee_mail = $recorder->sent[0];
		$admin_mail   = $recorder->sent[1];

		// Enrolee confirmation: HTML and plain-text parts present
		$this->assertSame( 'renee@example.nl', $enrolee_mail['to'] );
		$this->assertNotEmpty( $enrolee_mail['html'] );
		$this->assertNotEmpty( $enrolee_mail['plain'] );
		$this->assertStringContainsString( '<!DOCTYPE html', $enrolee_mail['html'] );
		$this->assertStringContainsString( '<table', $enrolee_mail['html'] );
		$this->assertStringContainsString( 'max-width:600px', $enrolee_mail['html'] );
		$this->assertStringNotContainsString( '<!DOCTYPE html', $enrolee_mail['plain'] );
		$this->assertSame( strip_tags( $enrolee_mail['plain'] ), $enrolee_mail['plain'] );

		// Admin notification: HTML and plain-text parts present
		$this->assertNotEmpty( $admin_mail['html'] );
		$this->assertNotEmpty( $admin_mail['plain'] );
		$this->assertStringContainsString( '<!DOCTYPE html', $admin_mail['html'] );
		$this->assertStringContainsString( '<table', $admin_mail['html'] );
		$this->assertSame( strip_tags( $admin_mail['plain'] ), $admin_mail['plain'] );

		// Default mail adapter sets Content-Type text/html header
		Enrolment_Intake::use_mail_adapter( null );
		$this->mails = array();
		$this->submit( $this->valid( (string) $id, 'tweede@example.nl' ) );
		$this->assertCount( 2, $this->mails );
		$this->assertStringContainsString( 'text/html', implode( ' ', (array) $this->mails[0]['headers'] ) );
	}

	public function test_header_shows_logo_chosen_in_settings_with_fixed_width_and_alt_text_linking_to_website(): void {
		$recorder = new Gzw_Mail_Recorder();
		Enrolment_Intake::use_mail_adapter( $recorder );

		$logo_url = 'https://gezonderinwerk.nl/wp-content/uploads/2025/09/logo-GezonderinWerk.svg';
		update_option( Settings_Page::OPTION_LOGO_URL, $logo_url );
		update_option( Settings_Page::OPTION_ORG_NAME, 'Gezonder in Werk B.V.' );

		$id = $this->session( 'Cursus Ergonomie', '2027-03-15' );
		$this->submit( $this->valid( (string) $id ) );

		$enrolee_mail = $recorder->sent[0];
		$html         = $enrolee_mail['html'];

		// Absolute URL, not embedded (no cid: or data URI)
		$this->assertStringContainsString( 'src="' . $logo_url . '"', $html );
		$this->assertStringNotContainsString( 'cid:', $html );
		$this->assertStringNotContainsString( 'data:image', $html );

		// Fixed width and alt text
		$this->assertStringContainsString( 'width="180"', $html );
		$this->assertStringContainsString( 'alt="Gezonder in Werk B.V."', $html );

		// Linked to website
		$website = Settings_Page::website_url();
		$this->assertStringContainsString( 'href="' . $website . '"', $html );
	}

	public function test_header_shows_text_fallback_when_no_logo_is_configured(): void {
		$recorder = new Gzw_Mail_Recorder();
		Enrolment_Intake::use_mail_adapter( $recorder );

		delete_option( Settings_Page::OPTION_LOGO_ID );
		delete_option( Settings_Page::OPTION_LOGO_URL );
		update_option( Settings_Page::OPTION_ORG_NAME, 'Gezonder in Werk B.V.' );

		$id = $this->session( 'Cursus Ergonomie', '2027-03-15' );
		$this->submit( $this->valid( (string) $id ) );

		$html = $recorder->sent[0]['html'];
		$this->assertStringNotContainsString( '<img src=', $html );
		$this->assertStringContainsString( 'Gezonder in Werk B.V.', $html );
		$this->assertStringContainsString( 'href="' . Settings_Page::website_url() . '"', $html );
	}

	public function test_footer_shows_configurable_organisation_name_and_contact_email(): void {
		$recorder = new Gzw_Mail_Recorder();
		Enrolment_Intake::use_mail_adapter( $recorder );

		update_option( Settings_Page::OPTION_ORG_NAME, 'Gezonder in Werk Nascholingen B.V.' );
		update_option( Settings_Page::OPTION_CONTACT_EMAIL, 'nascholing@gezonderinwerk.nl' );

		$id = $this->session( 'Nascholing Burnout', '2027-03-15' );
		$this->submit( $this->valid( (string) $id ) );

		foreach ( $recorder->sent as $mail ) {
			$this->assertStringContainsString( 'Gezonder in Werk Nascholingen B.V.', $mail['html'] );
			$this->assertStringContainsString( 'mailto:nascholing@gezonderinwerk.nl', $mail['html'] );
			$this->assertStringContainsString( 'nascholing@gezonderinwerk.nl', $mail['plain'] );
			$this->assertStringContainsString( 'Gezonder in Werk Nascholingen B.V.', $mail['plain'] );
		}
	}

	public function test_user_supplied_values_are_escaped_in_html(): void {
		$recorder = new Gzw_Mail_Recorder();
		Enrolment_Intake::use_mail_adapter( $recorder );

		$id     = $this->session( 'Nascholing Ethiek', '2027-03-15' );
		$params = array(
			'session_id'       => (string) $id,
			'first_name'       => 'Renée & Partners',
			'last_name'        => 'de Boer & Zonen',
			'email'            => 'renee@example.nl',
			'employer'         => 'Bedrijf "Veiligheid" & Welzijn',
			'invoice_name'     => 'Factuur "Speciaal" & Zoon B.V.',
			'invoice_street'   => 'Hoofdstraat 10 & 11',
			'invoice_postcode' => '1000 AA',
			'invoice_city'     => 'Amsterdam',
		);
		$this->submit( $params );

		$this->assertCount( 2, $recorder->sent );
		$enrolee_html = $recorder->sent[0]['html'];
		$admin_html   = $recorder->sent[1]['html'];

		// Ampersands and quotes in user input are escaped in HTML
		$this->assertStringContainsString( 'Renée &amp; Partners', $enrolee_html );
		$this->assertStringContainsString( 'Renée &amp; Partners', $admin_html );
		$this->assertStringContainsString( 'de Boer &amp; Zonen', $admin_html );
		$this->assertStringContainsString( 'Bedrijf &quot;Veiligheid&quot; &amp; Welzijn', $admin_html );
		$this->assertStringContainsString( 'Factuur &quot;Speciaal&quot; &amp; Zoon B.V.', $admin_html );
		$this->assertStringContainsString( 'Hoofdstraat 10 &amp; 11', $admin_html );

		// Direct template rendering with malicious payload escapes HTML tags
		$malicious_values = array(
			'first_name'       => '<script>alert("xss")</script>',
			'last_name'        => '<b onmouseover="alert(1)">Danger</b>',
			'email'            => 'test@example.nl',
			'employer'         => 'Bedrijf <img src="x" onerror="alert(1)">',
			'invoice_name'     => 'Arbo <iframe src="evil.com">',
			'invoice_street'   => 'Straat 1',
			'invoice_postcode' => '1234 AB',
			'invoice_city'     => 'Stad',
		);
		$row = array(
			'id'           => 1,
			'course_title' => 'Cursus <Test>',
			'session_date' => '2027-03-15',
			'start_time'   => '19:00',
			'end_time'     => '20:30',
			'location'     => 'Online',
			'status'       => 'confirmed',
			'price_cents'  => 9900,
		);
		$rendered_html = \GezonderInWerk\Booking\Email_Template::render_confirmation_html( $malicious_values, array( $row ), null );
		$this->assertStringNotContainsString( '<script>', $rendered_html );
		$this->assertStringNotContainsString( '<b onmouseover', $rendered_html );
		$this->assertStringNotContainsString( '<img src="x"', $rendered_html );
		$this->assertStringContainsString( '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $rendered_html );

		$rendered_admin = \GezonderInWerk\Booking\Email_Template::render_admin_notification_html( $malicious_values, array( $row ), null, 'http://example.com' );
		$this->assertStringNotContainsString( '<iframe', $rendered_admin );
		$this->assertStringContainsString( '&lt;iframe src=&quot;evil.com&quot;&gt;', $rendered_admin );
	}

	public function test_cycle_confirmation_renders_each_webinar_with_date_and_expectation_text(): void {
		$recorder = new Gzw_Mail_Recorder();
		Enrolment_Intake::use_mail_adapter( $recorder );

		$ids = array(
			$this->session( 'Webinar A', '2027-03-15' ),
			$this->session( 'Webinar B', '2027-04-15' ),
		);
		$cycle = Catalog_Repository::save_cycle( array( 'title' => 'Cyclus ABC', 'price_cents' => 19900, 'session_ids' => $ids ) );

		$this->submit( $this->valid( 'cycle-' . $cycle ) );

		$mail = $recorder->sent[0];
		$html = $mail['html'];

		$this->assertStringContainsString( 'Cyclus ABC', $html );
		$this->assertStringContainsString( 'Webinar A', $html );
		$this->assertStringContainsString( 'Webinar B', $html );
		$this->assertStringContainsString( '15-03-2027', $html );
		$this->assertStringContainsString( '15-04-2027', $html );
		$this->assertStringContainsString( 'uiterlijk vrijdag 12 maart', $html );
		$this->assertStringContainsString( 'uiterlijk woensdag 14 april', $html );
		$this->assertStringContainsString( 'Prijs hele cyclus: € 199,00 excl. BTW', $html );
	}

	public function test_waitlisted_confirmation_has_no_expectation_block(): void {
		$recorder = new Gzw_Mail_Recorder();
		Enrolment_Intake::use_mail_adapter( $recorder );

		$id = $this->session( 'Volle Nascholing', '2027-03-15', 1 );
		$this->submit( $this->valid( (string) $id, 'persoon1@example.nl' ) );

		$recorder->sent = array();
		$this->submit( $this->valid( (string) $id, 'persoon2@example.nl' ) );

		$mail = $recorder->sent[0];
		$this->assertStringContainsString( 'Wachtlijst', $mail['html'] );
		$this->assertStringContainsString( 'nog niet bevestigd', $mail['html'] );
		$this->assertStringNotContainsString( 'gzw-email-expectation', $mail['html'] );
		$this->assertStringNotContainsString( 'uiterlijk', $mail['html'] );
	}

	public function test_admin_notification_lists_submitted_fields_in_a_two_column_table(): void {
		$recorder = new Gzw_Mail_Recorder();
		Enrolment_Intake::use_mail_adapter( $recorder );

		$id = $this->session( 'Nascholing Arbo', '2027-03-15' );
		$this->submit( $this->valid( (string) $id ) );

		$admin_html = $recorder->sent[1]['html'];

		// Contains table structure and heading
		$this->assertStringContainsString( 'Ingezonden gegevens', $admin_html );
		$this->assertStringContainsString( 'gzw-field-label', $admin_html );

		// Contains participant and billing field labels and values
		$this->assertStringContainsString( 'Voornaam', $admin_html );
		$this->assertStringContainsString( 'Renée', $admin_html );
		$this->assertStringContainsString( 'Achternaam', $admin_html );
		$this->assertStringContainsString( 'de Vries', $admin_html );
		$this->assertStringContainsString( 'Factuurnaam', $admin_html );
		$this->assertStringContainsString( 'Arbodienst Oost B.V.', $admin_html );
		$this->assertStringContainsString( 'Straat en huisnummer', $admin_html );
		$this->assertStringContainsString( 'Kerkstraat 1', $admin_html );
		$this->assertStringContainsString( 'Postcode', $admin_html );
		$this->assertStringContainsString( '5611 AB', $admin_html );
		$this->assertStringContainsString( 'Plaats', $admin_html );
		$this->assertStringContainsString( 'Eindhoven', $admin_html );

		// Admin detail view link
		$this->assertStringContainsString( 'Bekijk de inschrijving in WordPress', $admin_html );
		$this->assertStringContainsString( 'admin.php?page=gzw-enrolments', $admin_html );
	}

	public function test_settings_sanitization_and_fallbacks(): void {
		// Contact email
		$this->assertSame( 'info@gezonderinwerk.nl', Settings_Page::contact_email() );
		update_option( Settings_Page::OPTION_CONTACT_EMAIL, ' custom@example.nl ' );
		$this->assertSame( 'custom@example.nl', Settings_Page::contact_email() );

		// Organisation name
		delete_option( Settings_Page::OPTION_ORG_NAME );
		$this->assertNotEmpty( Settings_Page::org_name() );
		update_option( Settings_Page::OPTION_ORG_NAME, 'Mijn Opleidingen' );
		$this->assertSame( 'Mijn Opleidingen', Settings_Page::org_name() );

		// Logo URL
		$this->assertSame( '', Settings_Page::logo_url() );
		update_option( Settings_Page::OPTION_LOGO_URL, 'https://example.com/logo.png' );
		$this->assertSame( 'https://example.com/logo.png', Settings_Page::logo_url() );
	}

	public function test_promotion_sends_html_and_plain_confirmation_with_expectation_text(): void {
		$recorder = new Gzw_Mail_Recorder();
		Enrolment_Intake::use_mail_adapter( $recorder );

		$id    = $this->session( 'Volle Nascholing', '2027-03-15', 1 );
		$first = $this->submit( $this->valid( (string) $id, 'eerste@example.nl' ) );
		$wait  = $this->submit( $this->valid( (string) $id, 'tweede@example.nl' ) );
		$this->assertSame( 'waitlisted', $wait->get_data()['status'] );

		Enrolment_Repository::update_admin( (int) $first->get_data()['id'], 'cancelled', false, '' );
		$recorder->sent = array();
		$this->assertSame( 'ok', Enrolment_Repository::promote( (int) $wait->get_data()['id'] ) );

		$this->assertCount( 1, $recorder->sent );
		$mail = $recorder->sent[0];
		$this->assertSame( 'tweede@example.nl', $mail['to'] );
		$this->assertStringContainsString( '<!DOCTYPE html', $mail['html'] );
		$this->assertStringContainsString( 'er is een plek vrijgekomen', $mail['html'] );
		$this->assertStringContainsString( 'Bevestigd', $mail['html'] );
		$this->assertStringContainsString( 'gzw-email-expectation', $mail['html'] );
		$this->assertStringContainsString( 'er is een plek vrijgekomen', $mail['plain'] );
		$this->assertSame( strip_tags( $mail['plain'] ), $mail['plain'] );
	}

	public function test_cycle_with_one_full_session_still_links_the_cancellation_terms(): void {
		$recorder = new Gzw_Mail_Recorder();
		Enrolment_Intake::use_mail_adapter( $recorder );

		$ids   = array( $this->session( 'Webinar Een', '2027-03-15' ), $this->session( 'Webinar Twee', '2027-04-15', 1 ) );
		$cycle = Catalog_Repository::save_cycle( array( 'title' => 'Cyclus Y', 'price_cents' => 15000, 'session_ids' => $ids ) );
		$this->submit( $this->valid( (string) $ids[1], 'bezet@example.nl' ) );
		$recorder->sent = array();

		$this->submit( $this->valid( 'cycle-' . $cycle ) );

		$mail = $recorder->sent[0];
		$this->assertStringContainsString( 'Annuleringsvoorwaarden bekijken', $mail['html'] );
		$this->assertStringContainsString( 'Annuleringsvoorwaarden bekijken', $mail['plain'] );
		$this->assertStringNotContainsString( 'Zodra er een plaats beschikbaar komt', $mail['html'] );
	}
}
