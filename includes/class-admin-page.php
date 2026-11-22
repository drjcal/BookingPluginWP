<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * WP admin: manage courses and their sessions (capability: manage_options).
 */
class Admin_Page {

	const SLUG = 'gzw-courses';
	const CAP  = 'manage_options';

	public static function register_menu(): void {
		add_menu_page(
			__( 'Cursussen', 'gezonder-in-werk-booking' ),
			__( 'Cursussen', 'gezonder-in-werk-booking' ),
			self::CAP,
			self::SLUG,
			array( self::class, 'render' ),
			'dashicons-welcome-learn-more'
		);
	}

	/** Parse a euro amount such as "125,50" to cents; null when invalid. */
	public static function euros_to_cents( string $input ): ?int {
		$input = str_replace( ',', '.', trim( $input ) );
		if ( ! preg_match( '/^\d{1,7}(\.\d{1,2})?$/', $input ) ) {
			return null;
		}
		return (int) round( (float) $input * 100 );
	}

	private static function back( int $course_id, string $notice ): void {
		$args = array( 'page' => self::SLUG, 'gzw_notice' => $notice );
		if ( $course_id ) {
			$args['course'] = $course_id;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Geen toegang.', 'gezonder-in-werk-booking' ), 403 );
		}
		check_admin_referer( $action );
	}

	public static function post( string $key ): string {
		return isset( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked in guard().
	}

	public static function handle_save_course(): void {
		self::guard( 'gzw_save_course' );
		$id    = absint( self::post( 'id' ) );
		$cents = self::euros_to_cents( self::post( 'price' ) );
		$title = self::post( 'title' );
		if ( '' === $title || null === $cents ) {
			self::back( $id, 'invalid_course' );
		}
		$saved = Catalog_Repository::save_course(
			array(
				'title'         => $title,
				'description'   => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['description'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
				'accreditation' => isset( $_POST['accreditation'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['accreditation'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
				'price_cents'   => $cents,
			),
			$id
		);
		self::back( $saved, $saved ? 'saved' : 'error' );
	}

	public static function handle_delete_course(): void {
		self::guard( 'gzw_delete_course' );
		$ok = Catalog_Repository::delete_course( absint( self::post( 'id' ) ) );
		self::back( 0, $ok ? 'deleted' : 'in_use' );
	}

	public static function handle_save_session(): void {
		self::guard( 'gzw_save_session' );
		$id        = absint( self::post( 'id' ) );
		$course_id = absint( self::post( 'course_id' ) );
		$date      = self::post( 'session_date' );
		$start     = self::post( 'start_time' );
		$end       = self::post( 'end_time' );
		$location  = self::post( 'location' );
		$capacity  = self::post( 'capacity' ); // Empty = unlimited.

		$valid = $course_id && Catalog_Repository::get_course( $course_id )
			&& preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && checkdate( (int) substr( $date, 5, 2 ), (int) substr( $date, 8, 2 ), (int) substr( $date, 0, 4 ) )
			&& preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $start ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $end )
			&& $start < $end && '' !== $location
			&& ( '' === $capacity || ( ctype_digit( $capacity ) && (int) $capacity > 0 && strlen( $capacity ) < 8 ) );
		if ( ! $valid ) {
			self::back( $course_id, 'invalid_session' );
		}
		$saved = Catalog_Repository::save_session_checked(
			array(
				'course_id'    => $course_id,
				'session_date' => $date,
				'start_time'   => $start,
				'end_time'     => $end,
				'location'     => $location,
				'is_open'      => '1' === self::post( 'is_open' ),
				'capacity'     => '' === $capacity ? null : (int) $capacity,
			),
			$id
		);
		self::back( $course_id, $saved['id'] ? 'saved' : ( $saved['error'] ?? 'error' ) );
	}

	public static function handle_delete_session(): void {
		self::guard( 'gzw_delete_session' );
		$ok = Catalog_Repository::delete_session( absint( self::post( 'id' ) ) );
		self::back( absint( self::post( 'course_id' ) ), $ok ? 'deleted' : 'in_use' );
	}

	private static function notice(): void {
		$messages = array(
			'saved'           => array( 'success', __( 'Opgeslagen.', 'gezonder-in-werk-booking' ) ),
			'deleted'         => array( 'success', __( 'Verwijderd.', 'gezonder-in-werk-booking' ) ),
			'in_use'          => array( 'error', __( 'Kan niet worden verwijderd: er zijn nog actieve (niet-geannuleerde) inschrijvingen of een datum hoort bij een cyclus. Sluit de datum in plaats daarvan.', 'gezonder-in-werk-booking' ) ),
			'invalid_course'  => array( 'error', __( 'Vul een titel en een geldige prijs (bijv. 125,00) in.', 'gezonder-in-werk-booking' ) ),
			'invalid_session' => array( 'error', __( 'Vul een geldige datum, begin- en eindtijd (begin vóór eind), locatie en capaciteit (leeg of een positief getal) in.', 'gezonder-in-werk-booking' ) ),
			'capacity_below_confirmed' => array( 'error', __( 'De capaciteit kan niet lager zijn dan het aantal bevestigde inschrijvingen. Annuleer of verplaats eerst inschrijvingen.', 'gezonder-in-werk-booking' ) ),
			'busy'            => array( 'error', __( 'Deze datum wordt op dit moment bijgewerkt door een inschrijving. Er is niets opgeslagen; probeer het over een paar seconden opnieuw.', 'gezonder-in-werk-booking' ) ),
			'error'           => array( 'error', __( 'Opslaan mislukt.', 'gezonder-in-werk-booking' ) ),
		);
		$key = isset( $_GET['gzw_notice'] ) ? sanitize_key( wp_unslash( $_GET['gzw_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $messages[ $key ] ) ) {
			printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $messages[ $key ][0] ), esc_html( $messages[ $key ][1] ) );
		}
	}

	public static function action_form( string $action, array $hidden, string $label, string $class = 'button' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		foreach ( $hidden as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}
		wp_nonce_field( $action );
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Geen toegang.', 'gezonder-in-werk-booking' ), 403 );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Cursussen', 'gezonder-in-werk-booking' ) . '</h1>';
		self::notice();

		$course_id = isset( $_GET['course'] ) ? absint( $_GET['course'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['course'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$course = $course_id ? Catalog_Repository::get_course( $course_id ) : array();
			if ( null === $course ) {
				echo '<p>' . esc_html__( 'Cursus niet gevonden.', 'gezonder-in-werk-booking' ) . '</p>';
			} else {
				self::render_course_form( $course ?: array(), $course_id );
				if ( $course_id ) {
					self::render_sessions( $course_id );
				}
			}
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	private static function render_list(): void {
		$new = add_query_arg( array( 'page' => self::SLUG, 'course' => 0 ), admin_url( 'admin.php' ) );
		echo '<a class="page-title-action" href="' . esc_url( $new ) . '">' . esc_html__( 'Nieuwe cursus', 'gezonder-in-werk-booking' ) . '</a>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Titel', 'gezonder-in-werk-booking' ) . '</th><th>' . esc_html__( 'Prijs excl. BTW', 'gezonder-in-werk-booking' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( Catalog_Repository::courses() as $course ) {
			$edit = add_query_arg( array( 'page' => self::SLUG, 'course' => $course['id'] ), admin_url( 'admin.php' ) );
			echo '<tr><td><a href="' . esc_url( $edit ) . '">' . esc_html( $course['title'] ) . '</a></td><td>' . esc_html( Labels::price( (int) $course['price_cents'] ) ) . '</td><td>';
			self::action_form( 'gzw_delete_course', array( 'id' => $course['id'] ), __( 'Verwijderen', 'gezonder-in-werk-booking' ), 'button-link-delete' );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_course_form( array $course, int $id ): void {
		$course += array( 'title' => '', 'description' => '', 'accreditation' => '', 'price_cents' => 0 );
		$price   = number_format( ( (int) $course['price_cents'] ) / 100, 2, ',', '' );
		?>
		<h2><?php echo $id ? esc_html__( 'Cursus bewerken', 'gezonder-in-werk-booking' ) : esc_html__( 'Nieuwe cursus', 'gezonder-in-werk-booking' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gzw_save_course" />
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<?php wp_nonce_field( 'gzw_save_course' ); ?>
			<table class="form-table" role="presentation">
				<tr><th><label for="gzw-c-title"><?php esc_html_e( 'Titel', 'gezonder-in-werk-booking' ); ?></label></th>
					<td><input id="gzw-c-title" name="title" class="regular-text" required value="<?php echo esc_attr( $course['title'] ); ?>" /></td></tr>
				<tr><th><label for="gzw-c-desc"><?php esc_html_e( 'Omschrijving', 'gezonder-in-werk-booking' ); ?></label></th>
					<td><textarea id="gzw-c-desc" name="description" class="large-text" rows="4"><?php echo esc_textarea( $course['description'] ); ?></textarea></td></tr>
				<tr><th><label for="gzw-c-acc"><?php esc_html_e( 'Accreditatie', 'gezonder-in-werk-booking' ); ?></label></th>
					<td><textarea id="gzw-c-acc" name="accreditation" class="large-text" rows="2"><?php echo esc_textarea( $course['accreditation'] ); ?></textarea></td></tr>
				<tr><th><label for="gzw-c-price"><?php esc_html_e( 'Prijs excl. BTW (€)', 'gezonder-in-werk-booking' ); ?></label></th>
					<td><input id="gzw-c-price" name="price" inputmode="decimal" required value="<?php echo esc_attr( $price ); ?>" /></td></tr>
			</table>
			<?php submit_button( __( 'Opslaan', 'gezonder-in-werk-booking' ) ); ?>
		</form>
		<?php
	}

	private static function session_fields( int $course_id, array $s ): void {
		$s += array( 'id' => 0, 'session_date' => '', 'start_time' => '', 'end_time' => '', 'location' => '', 'is_open' => 1, 'capacity' => null );
		$p  = 'gzw-s' . $s['id'] . '-';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-bottom:8px">
			<input type="hidden" name="action" value="gzw_save_session" />
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $s['id'] ); ?>" />
			<input type="hidden" name="course_id" value="<?php echo esc_attr( (string) $course_id ); ?>" />
			<?php wp_nonce_field( 'gzw_save_session' ); ?>
			<label><?php esc_html_e( 'Datum', 'gezonder-in-werk-booking' ); ?><br /><input type="date" name="session_date" required value="<?php echo esc_attr( $s['session_date'] ); ?>" /></label>
			<label><?php esc_html_e( 'Van', 'gezonder-in-werk-booking' ); ?><br /><input type="time" name="start_time" required value="<?php echo esc_attr( substr( (string) $s['start_time'], 0, 5 ) ); ?>" /></label>
			<label><?php esc_html_e( 'Tot', 'gezonder-in-werk-booking' ); ?><br /><input type="time" name="end_time" required value="<?php echo esc_attr( substr( (string) $s['end_time'], 0, 5 ) ); ?>" /></label>
			<label><?php esc_html_e( 'Locatie', 'gezonder-in-werk-booking' ); ?><br /><input name="location" required value="<?php echo esc_attr( $s['location'] ); ?>" /></label>
			<label><?php esc_html_e( 'Capaciteit (leeg = onbeperkt)', 'gezonder-in-werk-booking' ); ?><br /><input type="number" name="capacity" min="1" step="1" value="<?php echo esc_attr( (string) $s['capacity'] ); ?>" /></label>
			<label><input type="checkbox" name="is_open" value="1" <?php checked( (int) $s['is_open'], 1 ); ?> /> <?php esc_html_e( 'Open voor inschrijving', 'gezonder-in-werk-booking' ); ?></label>
			<button type="submit" class="button button-primary"><?php echo $s['id'] ? esc_html__( 'Opslaan', 'gezonder-in-werk-booking' ) : esc_html__( 'Datum toevoegen', 'gezonder-in-werk-booking' ); ?></button>
		</form>
		<?php
	}

	private static function render_sessions( int $course_id ): void {
		echo '<h2>' . esc_html__( 'Data', 'gezonder-in-werk-booking' ) . '</h2>';
		foreach ( Catalog_Repository::sessions_for_course( $course_id ) as $session ) {
			self::session_fields( $course_id, $session );
			self::action_form( 'gzw_delete_session', array( 'id' => $session['id'], 'course_id' => $course_id ), __( 'Verwijderen', 'gezonder-in-werk-booking' ), 'button-link-delete' );
			echo '<hr />';
		}
		echo '<h3>' . esc_html__( 'Nieuwe datum', 'gezonder-in-werk-booking' ) . '</h3>';
		self::session_fields( $course_id, array() );
	}
}
