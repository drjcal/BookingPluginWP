<?php
use GezonderInWerk\Booking\Catalog_Repository;
use GezonderInWerk\Booking\Enrolment_Fields;
use GezonderInWerk\Booking\Enrolment_Repository;
use GezonderInWerk\Booking\Enrolments_Admin;
use GezonderInWerk\Booking\Installer;
use GezonderInWerk\Booking\Retention;
use GezonderInWerk\Booking\Settings_Page;
use GezonderInWerk\Booking\Spam_Guard;

/**
 * Consent, spam protection, staff deletion, retention cleanup, uninstall and the delete rules.
 */
class PrivacyTest extends WP_UnitTestCase {

	private int $course;
	private int $session;

	public function set_up(): void {
		parent::set_up();
		Installer::grant_capabilities();
		$this->course  = Catalog_Repository::save_course( array( 'title' => 'Ergonomie', 'price_cents' => 12500 ) );
		$this->session = $this->make_session( $this->course, '2027-03-15' );
	}

	private function make_session( int $course, string $date ): int {
		return Catalog_Repository::save_session(
			array(
				'course_id'    => $course,
				'session_date' => $date,
				'start_time'   => '09:30',
				'end_time'     => '16:30',
				'location'     => 'Eindhoven',
				'is_open'      => 1,
			)
		);
	}

	private function params( array $overrides = array() ): array {
		return $overrides + array(
			'session_id'       => $this->session,
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

	private function row( int $session, string $email, array $overrides = array() ): int {
		return Enrolment_Repository::insert(
			$overrides + array(
				'session_id'           => $session,
				'price_cents'          => 12500,
				'first_name'           => 'Renée',
				'last_name'            => 'de Vries',
				'email'                => $email,
				'employer'             => 'Arbodienst Oost',
				'big_number'           => '12345678901',
				'invoice_name'         => 'Arbodienst Oost B.V.',
				'invoice_street'       => 'Kerkstraat 1',
				'invoice_postcode'     => '5611 AB',
				'invoice_city'         => 'Eindhoven',
				'consent_cancellation' => '1',
				'consent_privacy'      => '1',
			)
		);
	}

	private function count_rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::table_name() );
	}

	private function admin_post( string $handler, string $action, array $post ): string {
		$_POST                = $post;
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );
		$location             = '';
		$catch                = static function ( $url ) {
			throw new RuntimeException( $url );
		};
		add_filter( 'wp_redirect', $catch );
		try {
			call_user_func( array( Enrolments_Admin::class, $handler ) );
		} catch ( RuntimeException $e ) {
			$location = $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $catch );
			$_POST = array();
			unset( $_REQUEST['_wpnonce'] );
		}
		return $location;
	}

	private function as_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	// --- Consent ---------------------------------------------------------------------------

	public function test_submission_without_each_consent_is_rejected_and_not_stored(): void {
		foreach ( array( 'consent_cancellation', 'consent_privacy' ) as $field ) {
			$response = Gzw_Submission::post( $this->params( array( $field => '' ) ) );
			$this->assertSame( 422, $response->get_status(), $field );
			$this->assertArrayHasKey( $field, $response->get_data()['errors'] );
		}
		$missing = Gzw_Submission::post_raw( $this->params() + array( Spam_Guard::NONCE_FIELD => Spam_Guard::nonce() ) );
		$this->assertSame( 422, $missing->get_status() );
		$this->assertCount( 2, $missing->get_data()['errors'] );
		$this->assertSame( 0, $this->count_rows() );
	}

	public function test_consent_is_stored_with_the_enrolment(): void {
		$response = Gzw_Submission::post( $this->params() );
		$this->assertSame( 201, $response->get_status() );
		$row = Enrolment_Repository::get( (int) $response->get_data()['id'] );
		$this->assertSame( '1', (string) $row['consent_cancellation'] );
		$this->assertSame( '1', (string) $row['consent_privacy'] );
	}

	public function test_consent_fields_come_from_the_field_definition_and_render_in_form_and_admin(): void {
		$form = do_shortcode( '[gezonder_inschrijfformulier]' );
		foreach ( Enrolment_Fields::in_group( Enrolment_Fields::GROUP_CONSENT ) as $name => $field ) {
			$this->assertStringContainsString( 'name="' . $name . '"', $form );
		}
		$this->assertStringContainsString( 'name="' . Spam_Guard::NONCE_FIELD . '"', $form );
		$this->assertStringContainsString( 'name="' . Spam_Guard::HONEYPOT_FIELD . '"', $form );

		$id = $this->row( $this->session, 'a@example.nl' );
		$this->as_admin();
		$_GET = array( 'enrolment' => (string) $id );
		ob_start();
		Enrolments_Admin::render();
		$html = ob_get_clean();
		$_GET = array();
		$this->assertStringContainsString( Enrolment_Fields::groups()[ Enrolment_Fields::GROUP_CONSENT ], $html );
		$this->assertStringContainsString( 'Akkoord met de privacyverklaring', $html );
		$this->assertStringContainsString( 'gzw_delete_enrolment', $html );
	}

	// --- Spam ------------------------------------------------------------------------------

	public function test_missing_or_wrong_nonce_is_rejected_and_not_stored(): void {
		$consent = array( 'consent_cancellation' => '1', 'consent_privacy' => '1' );
		$this->assertSame( 403, Gzw_Submission::post_raw( $this->params() + $consent )->get_status() );
		$this->assertSame( 403, Gzw_Submission::post_raw( $this->params() + $consent + array( Spam_Guard::NONCE_FIELD => 'bogus' ) )->get_status() );
		$this->assertSame( 0, $this->count_rows() );
	}

	public function test_filled_honeypot_is_rejected_and_not_stored(): void {
		$response = Gzw_Submission::post( $this->params( array( Spam_Guard::HONEYPOT_FIELD => 'http://spam.example' ) ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 0, $this->count_rows() );
	}

	public function test_over_the_rate_limit_is_rejected_and_not_stored(): void {
		add_filter( 'gzw_booking_rate_limit', static fn() => array( 2, 600 ) );
		$this->assertSame( 201, Gzw_Submission::post( $this->params( array( 'email' => 'a@example.nl' ) ) )->get_status() );
		$this->assertSame( 201, Gzw_Submission::post( $this->params( array( 'email' => 'b@example.nl' ) ) )->get_status() );
		$third = Gzw_Submission::post( $this->params( array( 'email' => 'c@example.nl' ) ) );
		$this->assertSame( 429, $third->get_status() );
		$this->assertSame( 2, $this->count_rows() );
	}

	// --- Staff deletion --------------------------------------------------------------------

	public function test_staff_can_delete_an_enrolment_and_its_data(): void {
		$id    = $this->row( $this->session, 'a@example.nl' );
		$other = $this->row( $this->session, 'b@example.nl' );
		$this->as_admin();

		$location = $this->admin_post( 'handle_delete', 'gzw_delete_enrolment', array( 'id' => (string) $id ) );

		$this->assertStringContainsString( 'gzw_notice=deleted', $location );
		$this->assertNull( Enrolment_Repository::get( $id ) );
		$this->assertNotNull( Enrolment_Repository::get( $other ) );
	}

	public function test_deleting_a_cycle_enrolment_removes_the_whole_group(): void {
		$s2   = $this->make_session( $this->course, '2027-04-15' );
		$ref  = array( 'group_reference' => 'CYC-TEST1' );
		$a    = $this->row( $this->session, 'a@example.nl', $ref );
		$b    = $this->row( $s2, 'a@example.nl', $ref );
		$keep = $this->row( $s2, 'z@example.nl' );

		$this->assertSame( 2, Enrolment_Repository::delete( $b ) );
		$this->assertNull( Enrolment_Repository::get( $a ) );
		$this->assertNotNull( Enrolment_Repository::get( $keep ) );
	}

	public function test_delete_handler_refuses_users_without_capability(): void {
		$id = $this->row( $this->session, 'a@example.nl' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->expectException( WPDieException::class );
		try {
			$this->admin_post( 'handle_delete', 'gzw_delete_enrolment', array( 'id' => (string) $id ) );
		} finally {
			$this->assertNotNull( Enrolment_Repository::get( $id ) );
		}
	}

	// --- Retention -------------------------------------------------------------------------

	public function test_retention_is_configurable_in_settings(): void {
		$this->assertSame( 24, Settings_Page::retention_months() );
		Settings_Page::register_settings();
		update_option( Settings_Page::OPTION_RETENTION_MONTHS, '6' );
		$this->assertSame( 6, Settings_Page::retention_months() );
		update_option( Settings_Page::OPTION_RETENTION_MONTHS, '-3' );
		$this->assertSame( 0, Settings_Page::retention_months() );

		$this->as_admin();
		ob_start();
		Settings_Page::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( Settings_Page::OPTION_RETENTION_MONTHS, $html );
	}

	public function test_scheduled_cleanup_anonymises_expired_enrolments_and_keeps_recent_ones(): void {
		$old    = $this->make_session( $this->course, gmdate( 'Y-m-d', strtotime( '-30 months' ) ) );
		$recent = $this->make_session( $this->course, gmdate( 'Y-m-d', strtotime( '-3 months' ) ) );
		$expired = $this->row( $old, 'old@example.nl', array( 'internal_note' => 'bel terug' ) );
		$kept    = $this->row( $recent, 'recent@example.nl' );

		$this->assertNotFalse( wp_next_scheduled( Retention::HOOK ) );
		$this->assertSame( 1, Retention::run() );

		$row = Enrolment_Repository::get( $expired );
		foreach ( array( 'first_name', 'last_name', 'employer', 'big_number', 'invoice_name', 'invoice_street', 'internal_note' ) as $field ) {
			$this->assertSame( '', $row[ $field ], $field );
		}
		$this->assertStringStartsWith( 'anonymised-', $row['email'] );
		$this->assertSame( '12500', (string) $row['price_cents'] );
		$this->assertSame( '1', (string) $row['consent_privacy'], 'consent record is kept' );
		$this->assertNotNull( $row['anonymised_at'] );
		$this->assertSame( 'recent@example.nl', Enrolment_Repository::get( $kept )['email'] );
		$this->assertSame( 0, Retention::run(), 'second run finds nothing new' );
	}

	public function test_zero_months_keeps_everything(): void {
		$old = $this->make_session( $this->course, '2015-01-01' );
		$id  = $this->row( $old, 'old@example.nl' );
		update_option( Settings_Page::OPTION_RETENTION_MONTHS, 0 );

		$this->assertSame( 0, Retention::run() );
		$this->assertSame( 'old@example.nl', Enrolment_Repository::get( $id )['email'] );
	}

	public function test_anonymised_rows_never_collide_on_the_unique_session_email_key(): void {
		$old = $this->make_session( $this->course, '2015-01-01' );
		$a   = $this->row( $old, 'a@example.nl' );
		$b   = $this->row( $old, 'b@example.nl' );

		$this->assertSame( 2, Retention::run() );
		$this->assertNotSame( Enrolment_Repository::get( $a )['email'], Enrolment_Repository::get( $b )['email'] );
		// A staff status change re-sets the active-session key on an anonymised row without a collision.
		$this->assertSame( 'ok', Enrolment_Repository::update_admin( $a, Installer::STATUS_CONFIRMED, false, '' ) );
		$this->assertSame( 'ok', Enrolment_Repository::update_admin( $b, Installer::STATUS_CONFIRMED, false, '' ) );
		// The address is free to enrol again.
		$this->assertGreaterThan( 0, $this->row( $old, 'a@example.nl' ) );
	}

	public function test_cleanup_handles_a_cycle_group_together(): void {
		$old    = $this->make_session( $this->course, gmdate( 'Y-m-d', strtotime( '-30 months' ) ) );
		$recent = $this->make_session( $this->course, gmdate( 'Y-m-d', strtotime( '-1 month' ) ) );
		$ref    = array( 'group_reference' => 'CYC-GROUP1' );
		$first  = $this->row( $old, 'a@example.nl', $ref );
		$second = $this->row( $recent, 'a@example.nl', $ref );

		$this->assertSame( 0, Retention::run(), 'group is as old as its latest webinar' );
		$this->assertSame( 'a@example.nl', Enrolment_Repository::get( $first )['email'] );

		global $wpdb;
		$wpdb->update( Installer::sessions_table(), array( 'session_date' => gmdate( 'Y-m-d', strtotime( '-26 months' ) ) ), array( 'id' => $recent ) );
		$this->assertSame( 2, Retention::run() );
		$this->assertStringStartsWith( 'anonymised-', Enrolment_Repository::get( $first )['email'] );
		$this->assertStringStartsWith( 'anonymised-', Enrolment_Repository::get( $second )['email'] );
		$this->assertSame( 'CYC-GROUP1', Enrolment_Repository::get( $second )['group_reference'] );
	}

	public function test_enrolment_of_a_deleted_session_expires_by_its_creation_date(): void {
		$gone = $this->make_session( $this->course, '2027-01-01' );
		$id   = $this->row( $gone, 'a@example.nl', array( 'status' => Installer::STATUS_CANCELLED, 'created_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-40 months' ) ) ) );
		$this->assertTrue( Catalog_Repository::delete_session( $gone ) );

		$this->assertSame( 1, Retention::run() );
		$this->assertStringStartsWith( 'anonymised-', Enrolment_Repository::get( $id )['email'] );
	}

	// --- Delete rules for sessions and courses ---------------------------------------------

	public function test_cancelled_enrolments_do_not_block_deleting_a_session_or_course(): void {
		$this->row( $this->session, 'a@example.nl', array( 'status' => Installer::STATUS_CANCELLED ) );
		$this->assertTrue( Catalog_Repository::delete_course( $this->course ) );
		$this->assertNull( Catalog_Repository::get_course( $this->course ) );
		$this->assertSame( array(), Catalog_Repository::sessions_for_course( $this->course ) );
	}

	public function test_active_enrolments_block_deleting_session_and_leave_the_course_intact(): void {
		$second = $this->make_session( $this->course, '2027-05-01' );
		$this->row( $second, 'a@example.nl' );

		$this->assertFalse( Catalog_Repository::delete_session( $second ) );
		$this->assertFalse( Catalog_Repository::delete_course( $this->course ) );
		$this->assertNotNull( Catalog_Repository::get_course( $this->course ) );
		$this->assertCount( 2, Catalog_Repository::sessions_for_course( $this->course ), 'a refused delete removes no session' );
	}

	public function test_sessions_in_a_cycle_stay_non_deletable_and_the_notice_says_why(): void {
		// A cycle needs at least two sessions.
		$other = $this->make_session( $this->course, '2027-04-15' );
		Catalog_Repository::save_cycle( array( 'title' => 'Cyclus', 'price_cents' => 20000, 'session_ids' => array( $this->session, $other ) ) );

		$this->assertFalse( Catalog_Repository::delete_session( $this->session ) );
		$this->assertFalse( Catalog_Repository::delete_course( $this->course ) );
		$this->assertCount( 2, Catalog_Repository::sessions_for_course( $this->course ) );

		$this->as_admin();
		$_GET = array( 'gzw_notice' => 'in_use' );
		ob_start();
		\GezonderInWerk\Booking\Admin_Page::render();
		$html = ob_get_clean();
		$_GET = array();
		$this->assertStringContainsString( 'cyclus', $html );
		$this->assertStringContainsString( 'inschrijvingen', $html );
	}

	// --- Uninstall -------------------------------------------------------------------------

	public function test_uninstall_option_is_a_setting(): void {
		Settings_Page::register_settings();
		update_option( Installer::OPTION_REMOVE_ON_UNINSTALL, '1' );
		$this->assertSame( 1, get_option( Installer::OPTION_REMOVE_ON_UNINSTALL ) );
		update_option( Installer::OPTION_REMOVE_ON_UNINSTALL, '' );
		$this->assertSame( 0, get_option( Installer::OPTION_REMOVE_ON_UNINSTALL ) );

		$this->as_admin();
		ob_start();
		Settings_Page::render();
		$this->assertStringContainsString( Installer::OPTION_REMOVE_ON_UNINSTALL, ob_get_clean() );
	}

	public function test_uninstall_drops_data_only_when_the_owner_opted_in(): void {
		global $wpdb;
		$this->row( $this->session, 'a@example.nl' );
		$tables = array( Installer::table_name(), Installer::courses_table(), Installer::sessions_table(), Installer::cycles_table(), Installer::cycle_sessions_table() );
		$exists = static fn( string $t ): bool => $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'gezonder-in-werk-booking/gezonder-in-werk-booking.php' );
		}

		// Without the opt-in uninstall.php keeps everything.
		delete_option( Installer::OPTION_REMOVE_ON_UNINSTALL );
		include dirname( __DIR__, 2 ) . '/uninstall.php';
		foreach ( $tables as $table ) {
			$this->assertTrue( $exists( $table ), "kept: $table" );
		}
		$this->assertNotFalse( get_option( Installer::OPTION_DB_VERSION ) );

		// With the opt-in everything goes. DROP TABLE commits, so the test framework's
		// temporary-table rewriting is switched off and the schema is rebuilt afterwards.
		update_option( Installer::OPTION_REMOVE_ON_UNINSTALL, 1 );
		update_option( Settings_Page::OPTION_NOTIFICATION_EMAIL, 'x@example.nl' );
		update_option( Settings_Page::OPTION_RETENTION_MONTHS, 12 );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		try {
			include dirname( __DIR__, 2 ) . '/uninstall.php';
			foreach ( $tables as $table ) {
				$this->assertFalse( $exists( $table ), "dropped: $table" );
			}
			$this->assertFalse( get_option( Installer::OPTION_DB_VERSION ) );
			$this->assertFalse( get_option( Installer::OPTION_REMOVE_ON_UNINSTALL ) );
			$this->assertFalse( get_option( Settings_Page::OPTION_NOTIFICATION_EMAIL ) );
			$this->assertFalse( get_option( Settings_Page::OPTION_RETENTION_MONTHS ) );
			$this->assertFalse( wp_next_scheduled( Retention::HOOK ) );
		} finally {
			Installer::maybe_upgrade();
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}
}
