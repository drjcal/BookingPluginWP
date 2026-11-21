<?php
/**
 * Scenarios driven through the public submission endpoint only.
 */
class EnrolmentSubmissionTest extends WP_UnitTestCase {

	private int $session_id;

	public function set_up(): void {
		parent::set_up();
		$course           = \GezonderInWerk\Booking\Catalog_Repository::save_course(
			array(
				'title'         => 'Werkplekergonomie',
				'description'   => 'Een praktische nascholing.',
				'accreditation' => 'Geaccrediteerd: 4 punten',
				'price_cents'   => 12500,
			)
		);
		$this->session_id = $this->make_session( $course );
	}

	private function make_session( int $course_id, array $overrides = array() ): int {
		return \GezonderInWerk\Booking\Catalog_Repository::save_session(
			$overrides + array(
				'course_id'    => $course_id,
				'session_date' => '2027-03-15',
				'start_time'   => '09:30',
				'end_time'     => '16:30',
				'location'     => 'Eindhoven',
				'is_open'      => 1,
			)
		);
	}

	private function valid(): array {
		return array(
			'session_id'        => $this->session_id,
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
			$this->assertSame( (string) $value, $rows[0][ $field ], $field );
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
			'spaced but short' => array( '1234 5678 90' ),
		);
	}

	public function test_big_number_with_spaces_is_normalised_and_stored(): void {
		$params               = $this->valid();
		$params['big_number'] = ' 1234 567 8901 ';

		$this->assertSame( 201, $this->submit( $params )->get_status() );
		$this->assertSame( '12345678901', $this->stored()[0]['big_number'] );
	}

	public function test_rejected_submission_returns_normalised_big_number(): void {
		$params               = $this->valid();
		$params['big_number'] = '1234 5678 90';

		$data = $this->submit( $params )->get_data();

		$this->assertArrayHasKey( 'big_number', $data['errors'] );
		$this->assertSame( '1234567890', $data['values']['big_number'] );
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

		do_action( 'activate_' . plugin_basename( GZW_BOOKING_FILE ) );

		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
	}

	public function test_shortcode_renders_labelled_dutch_form_fields(): void {
		$html = do_shortcode( '[gezonder_inschrijfformulier]' );

		$this->assertStringContainsString( 'for="gzw-first_name"', $html );
		$this->assertStringContainsString( 'Voornaam', $html );
		$this->assertStringContainsString( 'BIG-registratienummer', $html );
		$this->assertStringContainsString( 'name="invoice_btw"', $html );
	}

	public function test_submission_stores_session_and_price_snapshot(): void {
		$this->assertSame( 201, $this->submit( $this->valid() )->get_status() );
		$row = $this->stored()[0];
		$this->assertSame( (string) $this->session_id, $row['session_id'] );
		$this->assertSame( '12500', $row['price_cents'] );
	}

	public function test_later_price_change_does_not_alter_existing_enrolment(): void {
		$this->submit( $this->valid() );
		$session = \GezonderInWerk\Booking\Catalog_Repository::get_session( $this->session_id );
		\GezonderInWerk\Booking\Catalog_Repository::save_course(
			array(
				'title'         => 'Werkplekergonomie',
				'description'   => '',
				'accreditation' => '',
				'price_cents'   => 99900,
			),
			(int) $session['course_id']
		);

		$this->assertSame( '12500', $this->stored()[0]['price_cents'] );
	}

	public function test_missing_or_unknown_session_is_rejected(): void {
		foreach ( array( '', '999999', 'abc' ) as $bad ) {
			$params               = $this->valid();
			$params['session_id'] = $bad;
			$response             = $this->submit( $params );
			$this->assertSame( 422, $response->get_status(), "session_id '$bad'" );
			$this->assertArrayHasKey( 'session_id', $response->get_data()['errors'] );
		}
		$this->assertCount( 0, $this->stored() );
	}

	public function test_closed_session_cannot_be_enrolled_in(): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'gzw_sessions', array( 'is_open' => 0 ), array( 'id' => $this->session_id ) );

		$response = $this->submit( $this->valid() );

		$this->assertSame( 422, $response->get_status() );
		$this->assertArrayHasKey( 'session_id', $response->get_data()['errors'] );
		$this->assertCount( 0, $this->stored() );
	}

	public function test_duplicate_email_for_same_session_is_rejected_with_clear_message(): void {
		$this->submit( $this->valid() );
		$params          = $this->valid();
		$params['email'] = 'RENEE@example.nl';

		$response = $this->submit( $params );

		$this->assertSame( 422, $response->get_status() );
		$this->assertStringContainsString( 'al ingeschreven', $response->get_data()['errors']['email'] );
		$this->assertCount( 1, $this->stored() );
	}

	public function test_same_email_may_enrol_in_another_session(): void {
		$this->submit( $this->valid() );
		$course = \GezonderInWerk\Booking\Catalog_Repository::get_session( $this->session_id )['course_id'];
		$other  = $this->make_session( (int) $course, array( 'session_date' => '2027-04-01' ) );
		$params = array( 'session_id' => $other ) + $this->valid();

		$this->assertSame( 201, $this->submit( $params )->get_status() );
	}

	public function test_cancelled_enrolment_does_not_block_a_new_one(): void {
		$this->submit( $this->valid() );
		$id = (int) $this->stored()[0]['id'];
		\GezonderInWerk\Booking\Enrolment_Repository::update_admin( $id, 'cancelled', false, '' );

		$this->assertSame( 201, $this->submit( $this->valid() )->get_status() );
	}

	public function test_form_lists_only_open_sessions_with_date_time_location_and_price(): void {
		$course = (int) \GezonderInWerk\Booking\Catalog_Repository::get_session( $this->session_id )['course_id'];
		$this->make_session( $course, array( 'location' => 'Gesloten locatie', 'is_open' => 0 ) );

		$html = do_shortcode( '[gezonder_inschrijfformulier]' );

		$this->assertStringContainsString( 'name="session_id"', $html );
		$this->assertStringContainsString( 'value="' . $this->session_id . '"', $html );
		$this->assertStringContainsString( 'Werkplekergonomie', $html );
		$this->assertStringContainsString( '15-03-2027', $html );
		$this->assertStringContainsString( '09:30', $html );
		$this->assertStringContainsString( '16:30', $html );
		$this->assertStringContainsString( 'Eindhoven', $html );
		$this->assertStringContainsString( '125,00', $html );
		$this->assertStringContainsString( 'excl. BTW', $html );
		$this->assertStringNotContainsString( 'Gesloten locatie', $html );
	}

	public function test_deleting_a_session_with_enrolments_is_refused(): void {
		$this->submit( $this->valid() );

		$this->assertFalse( \GezonderInWerk\Booking\Catalog_Repository::delete_session( $this->session_id ) );
		$this->assertNotNull( \GezonderInWerk\Booking\Catalog_Repository::get_session( $this->session_id ) );
	}

	public function test_deleting_a_course_removes_its_sessions_when_unused(): void {
		$course = (int) \GezonderInWerk\Booking\Catalog_Repository::get_session( $this->session_id )['course_id'];

		$this->assertTrue( \GezonderInWerk\Booking\Catalog_Repository::delete_course( $course ) );
		$this->assertNull( \GezonderInWerk\Booking\Catalog_Repository::get_session( $this->session_id ) );
	}

	public function test_admin_pages_render_for_administrators(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$course = (int) \GezonderInWerk\Booking\Catalog_Repository::get_session( $this->session_id )['course_id'];

		ob_start();
		\GezonderInWerk\Booking\Admin_Page::render();
		$list = ob_get_clean();
		$_GET['course'] = (string) $course;
		ob_start();
		\GezonderInWerk\Booking\Admin_Page::render();
		$edit = ob_get_clean();
		unset( $_GET['course'] );

		$this->assertStringContainsString( 'Werkplekergonomie', $list );
		$this->assertStringContainsString( 'Eindhoven', $edit );
		$this->assertStringContainsString( 'gzw_save_session', $edit );
	}

	public function test_euro_amounts_are_parsed_to_cents(): void {
		$this->assertSame( 12550, \GezonderInWerk\Booking\Admin_Page::euros_to_cents( '125,50' ) );
		$this->assertSame( 12500, \GezonderInWerk\Booking\Admin_Page::euros_to_cents( '125' ) );
		$this->assertNull( \GezonderInWerk\Booking\Admin_Page::euros_to_cents( 'abc' ) );
		$this->assertNull( \GezonderInWerk\Booking\Admin_Page::euros_to_cents( '-5' ) );
	}

	private function admin_post( string $action, array $post ): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST                = $post;
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );
		$location             = '';
		$catch                = static function ( $url ) {
			throw new RuntimeException( $url );
		};
		add_filter( 'wp_redirect', $catch );
		try {
			call_user_func( array( \GezonderInWerk\Booking\Admin_Page::class, 'handle_' . substr( $action, 4 ) ) );
		} catch ( RuntimeException $e ) {
			$location = $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $catch );
			$_POST = array();
			unset( $_REQUEST['_wpnonce'] );
		}
		return $location;
	}

	private function courses_count(): int {
		return count( \GezonderInWerk\Booking\Catalog_Repository::courses() );
	}

	public function test_admin_can_create_a_course(): void {
		$before   = $this->courses_count();
		$location = $this->admin_post(
			'gzw_save_course',
			array( 'title' => 'Nieuwe cursus', 'description' => 'Omschrijving', 'accreditation' => '', 'price' => '125,50' )
		);

		$this->assertSame( $before + 1, $this->courses_count() );
		$this->assertStringContainsString( 'gzw_notice=saved', $location );
		$new = current( wp_list_filter( \GezonderInWerk\Booking\Catalog_Repository::courses(), array( 'title' => 'Nieuwe cursus' ) ) );
		$this->assertSame( 'Nieuwe cursus', $new['title'] );
		$this->assertSame( '12550', $new['price_cents'] );
	}

	public function test_admin_can_edit_a_course(): void {
		$course = (int) \GezonderInWerk\Booking\Catalog_Repository::get_session( $this->session_id )['course_id'];

		$this->admin_post( 'gzw_save_course', array( 'id' => (string) $course, 'title' => 'Hernoemd', 'price' => '99' ) );

		$updated = \GezonderInWerk\Booking\Catalog_Repository::get_course( $course );
		$this->assertSame( 'Hernoemd', $updated['title'] );
		$this->assertSame( '9900', $updated['price_cents'] );
	}

	public function test_invalid_course_is_not_saved(): void {
		$before   = $this->courses_count();
		$location = $this->admin_post( 'gzw_save_course', array( 'title' => '', 'price' => 'abc' ) );

		$this->assertSame( $before, $this->courses_count() );
		$this->assertStringContainsString( 'gzw_notice=invalid_course', $location );
	}

	public function test_admin_can_create_and_edit_a_session(): void {
		$course = (int) \GezonderInWerk\Booking\Catalog_Repository::get_session( $this->session_id )['course_id'];
		$post   = array(
			'course_id'    => (string) $course,
			'session_date' => '2027-06-01',
			'start_time'   => '10:00',
			'end_time'     => '12:00',
			'location'     => 'Utrecht',
			'is_open'      => '1',
		);

		$this->admin_post( 'gzw_save_session', $post );
		$sessions = \GezonderInWerk\Booking\Catalog_Repository::sessions_for_course( $course );
		$this->assertCount( 2, $sessions );
		$created = end( $sessions );
		$this->assertSame( 'Utrecht', $created['location'] );

		unset( $post['is_open'] );
		$this->admin_post( 'gzw_save_session', array( 'id' => $created['id'], 'location' => 'Zwolle' ) + $post );
		$edited = \GezonderInWerk\Booking\Catalog_Repository::get_session( (int) $created['id'] );
		$this->assertSame( 'Zwolle', $edited['location'] );
		$this->assertSame( '0', (string) $edited['is_open'] );
	}

	public function test_invalid_session_is_not_saved(): void {
		$course   = (int) \GezonderInWerk\Booking\Catalog_Repository::get_session( $this->session_id )['course_id'];
		$location = $this->admin_post(
			'gzw_save_session',
			array( 'course_id' => (string) $course, 'session_date' => '2027-02-30', 'start_time' => '12:00', 'end_time' => '10:00', 'location' => 'X' )
		);

		$this->assertCount( 1, \GezonderInWerk\Booking\Catalog_Repository::sessions_for_course( $course ) );
		$this->assertStringContainsString( 'gzw_notice=invalid_session', $location );
	}

	public function test_admin_can_delete_unused_session_and_course(): void {
		$course = (int) \GezonderInWerk\Booking\Catalog_Repository::get_session( $this->session_id )['course_id'];

		$this->admin_post( 'gzw_delete_session', array( 'id' => (string) $this->session_id, 'course_id' => (string) $course ) );
		$this->assertNull( \GezonderInWerk\Booking\Catalog_Repository::get_session( $this->session_id ) );

		$location = $this->admin_post( 'gzw_delete_course', array( 'id' => (string) $course ) );
		$this->assertNull( \GezonderInWerk\Booking\Catalog_Repository::get_course( $course ) );
		$this->assertStringContainsString( 'gzw_notice=deleted', $location );
	}

	public function test_admin_cannot_delete_session_with_enrolments(): void {
		$this->submit( $this->valid() );
		$course   = (int) \GezonderInWerk\Booking\Catalog_Repository::get_session( $this->session_id )['course_id'];
		$location = $this->admin_post( 'gzw_delete_session', array( 'id' => (string) $this->session_id, 'course_id' => (string) $course ) );

		$this->assertNotNull( \GezonderInWerk\Booking\Catalog_Repository::get_session( $this->session_id ) );
		$this->assertStringContainsString( 'gzw_notice=in_use', $location );
	}

	public function test_admin_handlers_refuse_users_without_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'gzw_save_course' );
		$_POST                = array( 'title' => 'Hack', 'price' => '1' );
		$before               = $this->courses_count();

		$this->expectException( WPDieException::class );
		try {
			\GezonderInWerk\Booking\Admin_Page::handle_save_course();
		} finally {
			$_POST = array();
			unset( $_REQUEST['_wpnonce'] );
			$this->assertSame( $before, $this->courses_count() );
		}
	}

	public function test_field_definition_drives_form_validation_and_storage(): void {
		global $wpdb;
		$html    = do_shortcode( '[gezonder_inschrijfformulier]' );
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}gzw_enrolments", 0 );
		foreach ( array_keys( \GezonderInWerk\Booking\Enrolment_Fields::all() ) as $name ) {
			$this->assertStringContainsString( 'name="' . $name . '"', $html, "form: $name" );
			$this->assertContains( $name, $columns, "column: $name" );
		}
	}
}
