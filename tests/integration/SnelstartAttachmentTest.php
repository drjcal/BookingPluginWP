<?php
use GezonderInWerk\Booking\Catalog_Repository;
use GezonderInWerk\Booking\Settings_Page;

/**
 * The SnelStart booking CSV attached to the admin notification e-mail.
 */
class SnelstartAttachmentTest extends WP_UnitTestCase {

	/** @var array<int,array> Captured mails; attachment contents are read while the file still exists. */
	private array $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $null, $atts ) {
				$atts['attachment_contents'] = array();
				foreach ( (array) ( $atts['attachments'] ?? array() ) as $path ) {
					$atts['attachment_contents'][ basename( $path ) ] = file_get_contents( $path );
					$atts['attachment_paths'][]                       = $path;
				}
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);
	}

	private function configure(): void {
		update_option( Settings_Page::OPTION_SNELSTART_JOURNAL, '70' );
		update_option( Settings_Page::OPTION_SNELSTART_DEBTORS, '1300' );
		update_option( Settings_Page::OPTION_SNELSTART_REVENUE, '8000' );
		update_option( Settings_Page::OPTION_SNELSTART_VAT, '1520' );
	}

	private function session( string $title, string $date, int $price = 10000 ): int {
		$course = Catalog_Repository::save_course( array( 'title' => $title, 'price_cents' => $price ) );
		return Catalog_Repository::save_session(
			array( 'course_id' => $course, 'session_date' => $date, 'start_time' => '19:00', 'end_time' => '20:30', 'location' => 'Online', 'is_open' => 1 )
		);
	}

	private function valid( string $target ): array {
		return array(
			'session_id'        => $target,
			'first_name'        => 'Renée',
			'last_name'         => 'de Vries',
			'email'             => 'renee@example.nl',
			'employer'          => 'Arbodienst Oost',
			'invoice_name'      => 'Arbodienst Oost B.V.',
			'invoice_street'    => 'Kerkstraat 1',
			'invoice_postcode'  => '5611 AB',
			'invoice_city'      => 'Eindhoven',
			'invoice_reference' => 'PO-77',
		);
	}

	private function admin_mail(): array {
		$found = array_values( array_filter( $this->mails, static fn( $m ) => get_option( 'admin_email' ) === $m['to'] ) );
		$this->assertCount( 1, $found );
		return $found[0];
	}

	/** @return string[][] Parsed CSV rows without the header. */
	private function rows( string $csv ): array {
		$csv   = preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
		$lines = array_map( static fn( $l ) => str_getcsv( $l, ';', '"', '' ), array_filter( explode( "\n", trim( $csv ) ) ) );
		return array_slice( $lines, 1 );
	}

	public function test_admin_notification_carries_a_three_line_booking_and_the_file_is_removed_afterwards(): void {
		$this->configure();
		$id = $this->session( 'Nascholing Rugpijn', '2027-03-15' );

		$response = Gzw_Submission::post( $this->valid( (string) $id ) );

		$this->assertSame( 201, $response->get_status() );
		$mail = $this->admin_mail();
		$this->assertCount( 1, $mail['attachment_contents'] );
		$name = array_key_first( $mail['attachment_contents'] );
		$this->assertStringEndsWith( '.csv', $name );
		$csv = $mail['attachment_contents'][ $name ];
		$this->assertStringContainsString( 'fldDagboek;fldBoekingcode;fldDatum;fldGrootboeknummer;fldDebet;fldCredit;fldOmschrijving;fldRelatiecode;fldFactuurnummer', $csv );

		$rows = $this->rows( $csv );
		$this->assertCount( 3, $rows );
		$this->assertSame( array( '1300', '8000', '1520' ), array_column( $rows, 3 ) );
		$this->assertSame( array( '121,00', '0,00', '0,00' ), array_column( $rows, 4 ) );
		$this->assertSame( array( '0,00', '100,00', '21,00' ), array_column( $rows, 5 ) );
		$this->assertCount( 1, array_unique( array_column( $rows, 1 ) ), 'One booking code for all lines.' );
		$this->assertSame( array( '70', '70', '70' ), array_column( $rows, 0 ) );
		$this->assertSame( array( '', '', '' ), array_column( $rows, 7 ), 'Customer code is left to staff.' );
		$this->assertSame( array( '', '', '' ), array_column( $rows, 8 ), 'Invoice number is left to staff.' );
		$this->assertStringContainsString( 'Nascholing Rugpijn', $rows[0][6] );
		$this->assertStringContainsString( '15-03-2027', $rows[0][6] );
		$this->assertStringContainsString( 'Renée de Vries', $rows[0][6] );
		$this->assertStringContainsString( 'PO-77', $rows[0][6] );
		$this->assertFileDoesNotExist( $mail['attachment_paths'][0] );
	}

	public function test_confirmation_to_the_enrolee_has_no_attachment(): void {
		$this->configure();
		$id = $this->session( 'Nascholing Rugpijn', '2027-03-15' );
		Gzw_Submission::post( $this->valid( (string) $id ) );

		$to_enrolee = array_values( array_filter( $this->mails, static fn( $m ) => 'renee@example.nl' === $m['to'] ) );
		$this->assertCount( 1, $to_enrolee );
		$this->assertEmpty( $to_enrolee[0]['attachments'] );
	}

	public function test_no_attachment_until_all_ledger_numbers_are_set(): void {
		$this->configure();
		update_option( Settings_Page::OPTION_SNELSTART_VAT, '' );
		$id = $this->session( 'Nascholing Rugpijn', '2027-03-15' );

		Gzw_Submission::post( $this->valid( (string) $id ) );

		$this->assertEmpty( $this->admin_mail()['attachments'] );
	}

	public function test_cycle_books_the_bundle_price_once(): void {
		$this->configure();
		$ids   = array( $this->session( 'Webinar Een', '2027-03-15' ), $this->session( 'Webinar Twee', '2027-04-15' ) );
		$cycle = Catalog_Repository::save_cycle( array( 'title' => 'Cyclus X', 'price_cents' => 24900, 'session_ids' => $ids ) );

		Gzw_Submission::post( $this->valid( 'cycle-' . $cycle ) );

		$rows = $this->rows( reset( $this->admin_mail()['attachment_contents'] ) );
		$this->assertCount( 3, $rows );
		$this->assertSame( array( '301,29', '0,00', '0,00' ), array_column( $rows, 4 ) );
		$this->assertSame( array( '0,00', '249,00', '52,29' ), array_column( $rows, 5 ) );
		$this->assertStringContainsString( 'Cyclus X', $rows[0][6] );
	}

	public function test_no_attachment_for_a_waitlisted_or_free_enrolment(): void {
		$this->configure();
		$full = Catalog_Repository::save_session(
			array( 'course_id' => Catalog_Repository::save_course( array( 'title' => 'Vol', 'price_cents' => 10000 ) ), 'session_date' => '2027-03-15', 'start_time' => '19:00', 'end_time' => '20:30', 'location' => 'Online', 'is_open' => 1, 'capacity' => 1 )
		);
		Gzw_Submission::post( array_merge( $this->valid( (string) $full ), array( 'email' => 'eerste@example.nl' ) ) );
		$this->mails = array();
		Gzw_Submission::post( $this->valid( (string) $full ) );
		$this->assertEmpty( $this->admin_mail()['attachments'], 'waitlisted' );

		$this->mails = array();
		Gzw_Submission::post( $this->valid( (string) $this->session( 'Gratis', '2027-05-01', 0 ) ) );
		$this->assertEmpty( $this->admin_mail()['attachments'], 'free' );
	}

	public function test_description_names_the_billing_entity(): void {
		$this->configure();
		Gzw_Submission::post( $this->valid( (string) $this->session( 'Nascholing Rugpijn', '2027-03-15' ) ) );
		$rows = $this->rows( reset( $this->admin_mail()['attachment_contents'] ) );
		$this->assertStringContainsString( 'Arbodienst Oost B.V.', $rows[0][6] );
	}

	public function test_sanitised_ledger_numbers_are_digits_only(): void {
		$this->assertSame( '1300', Settings_Page::sanitize_ledger_number( ' 1300 ' ) );
		$this->assertSame( '', Settings_Page::sanitize_ledger_number( '13a' ) );
	}
}
