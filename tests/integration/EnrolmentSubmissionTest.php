<?php
/**
 * Scenarios driven through the public submission endpoint only.
 */
class EnrolmentSubmissionTest extends WP_UnitTestCase {

	private function valid(): array {
		return array(
			'first_name'        => 'Renée',
			'last_name'         => 'de Vries',
			'email'             => 'renee@example.nl',
			'employer'          => 'Arbodienst Oost',
			'big_number'        => '12345678901',
			'invoice_name'      => 'Arbodienst Oost B.V.',
			'invoice_street'    => 'Kerkstraat 1',
			'invoice_postcode'  => '5611 AB',
			'invoice_city'      => 'Eindhoven',
			'invoice_email'     => 'facturen@example.nl',
			'invoice_reference' => 'PO-2027-42',
			'invoice_kvk'       => '12345678',
			'invoice_btw'       => 'NL123456789B01',
		);
	}

	private function submit( array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/gezonder-in-werk/v1/enrolments' );
		$request->set_body_params( $params );
		return rest_do_request( $request );
	}

	private function stored(): array {
		global $wpdb;
		return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}gzw_enrolments", ARRAY_A );
	}

	public function test_valid_submission_stores_one_enrolment_with_all_fields(): void {
		$response = $this->submit( $this->valid() );

		$this->assertSame( 201, $response->get_status() );
		$rows = $this->stored();
		$this->assertCount( 1, $rows );
		$expected = $this->valid();
		foreach ( $expected as $field => $value ) {
			$this->assertSame( $value, $rows[0][ $field ], $field );
		}
		$this->assertSame( 'confirmed', $rows[0]['status'] );
		$this->assertNotEmpty( $rows[0]['created_at'] );
		$this->assertNotEmpty( $response->get_data()['message'] );
	}

	public function test_big_number_is_optional(): void {
		$params               = $this->valid();
		$params['big_number'] = '';

		$this->assertSame( 201, $this->submit( $params )->get_status() );
		$this->assertSame( '', $this->stored()[0]['big_number'] );
	}

	public function test_optional_billing_fields_may_be_omitted(): void {
		$params = array_diff_key(
			$this->valid(),
			array_flip( array( 'invoice_email', 'invoice_reference', 'invoice_kvk', 'invoice_btw' ) )
		);

		$this->assertSame( 201, $this->submit( $params )->get_status() );
	}

	/**
	 * @dataProvider required_fields
	 */
	public function test_missing_required_field_is_rejected_and_nothing_stored( string $field ): void {
		$params           = $this->valid();
		$params[ $field ] = '  ';

		$response = $this->submit( $params );

		$this->assertSame( 422, $response->get_status() );
		$this->assertArrayHasKey( $field, $response->get_data()['errors'] );
		$this->assertCount( 0, $this->stored() );
	}

	public function required_fields(): array {
		$fields = array( 'first_name', 'last_name', 'email', 'employer', 'invoice_name', 'invoice_street', 'invoice_postcode', 'invoice_city' );
		return array_combine( $fields, array_map( fn( $f ) => array( $f ), $fields ) );
	}

	public function test_bad_email_is_rejected(): void {
		$params          = $this->valid();
		$params['email'] = 'geen-email';

		$response = $this->submit( $params );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( array( 'email' ), array_keys( $response->get_data()['errors'] ) );
		$this->assertCount( 0, $this->stored() );
	}

	public function test_bad_invoice_email_is_rejected_when_given(): void {
		$params                  = $this->valid();
		$params['invoice_email'] = 'nope';

		$response = $this->submit( $params );

		$this->assertSame( 422, $response->get_status() );
		$this->assertArrayHasKey( 'invoice_email', $response->get_data()['errors'] );
	}

	/**
	 * @dataProvider bad_big_numbers
	 */
	public function test_bad_big_number_is_rejected( string $big ): void {
		$params               = $this->valid();
		$params['big_number'] = $big;

		$response = $this->submit( $params );

		$this->assertSame( 422, $response->get_status() );
		$this->assertArrayHasKey( 'big_number', $response->get_data()['errors'] );
		$this->assertCount( 0, $this->stored() );
	}

	public function bad_big_numbers(): array {
		return array(
			'too short' => array( '1234567890' ),
			'too long'  => array( '123456789012' ),
			'letters'   => array( '1234567890A' ),
		);
	}

	public function test_rejected_submission_returns_input_so_it_can_be_preserved(): void {
		$params          = $this->valid();
		$params['email'] = 'geen-email';

		$data = $this->submit( $params )->get_data();

		$this->assertSame( 'Renée', $data['values']['first_name'] );
		$this->assertSame( 'geen-email', $data['values']['email'] );
	}

	public function test_over_long_value_gets_a_field_error_not_a_server_error(): void {
		$params             = $this->valid();
		$params['employer'] = str_repeat( 'a', 101 );

		$response = $this->submit( $params );

		$this->assertSame( 422, $response->get_status() );
		$this->assertArrayHasKey( 'employer', $response->get_data()['errors'] );
		$this->assertCount( 0, $this->stored() );
	}

	public function test_plugin_activation_creates_enrolment_storage(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'gzw_enrolments';
		// Temporary tables cannot be dropped by DROP TABLE inside the test transaction, so use a clean slate.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		do_action( 'activate_gezonder-in-werk-booking/gezonder-in-werk-booking.php' );

		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
	}

	public function test_shortcode_renders_labelled_dutch_form_fields(): void {
		$html = do_shortcode( '[gezonder_inschrijfformulier]' );

		$this->assertStringContainsString( 'for="gzw-first_name"', $html );
		$this->assertStringContainsString( 'Voornaam', $html );
		$this->assertStringContainsString( 'BIG-registratienummer', $html );
		$this->assertStringContainsString( 'name="invoice_btw"', $html );
	}
}
