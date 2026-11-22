<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * Shared HTML email template and plain-text alternatives for all outgoing emails.
 * Table-based email-safe markup, inline styles, system font stack, responsive and dark mode aware.
 * Colour palette is centralised here so it can be swapped in one place.
 * All wording is read from Enrolment_Messages and Labels.
 */
class Email_Template {

	/* Brand palette (matches gezonderinwerk.nl) */
	const COLOR_BG                   = '#f2f5f7';
	const COLOR_CONTAINER            = '#ffffff';
	const COLOR_TEXT                 = '#192a3d';
	const COLOR_MUTED                = '#526171';
	const COLOR_ACCENT               = '#1559ed';
	const COLOR_BORDER               = '#e1e8ed';
	const COLOR_CARD_BG              = '#fafbfc';
	const COLOR_BADGE_CONFIRMED_BG   = '#eaf5ea';
	const COLOR_BADGE_CONFIRMED_TEXT = '#1e6627';
	const COLOR_BADGE_WAITLIST_BG    = '#fff4e5';
	const COLOR_BADGE_WAITLIST_TEXT  = '#8a5300';

	const FONT_FAMILY = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";

	/**
	 * Wraps body content in the shared 600px email shell with header and footer.
	 *
	 * @param string $title        Email document title.
	 * @param string $content_html Inner HTML content.
	 */
	public static function shell( string $title, string $content_html ): string {
		$org_name    = Settings_Page::org_name();
		$logo_url    = Settings_Page::logo_url();
		$website_url = Settings_Page::website_url();
		$contact_email = Settings_Page::contact_email();
		$site_display  = preg_replace( '#^https?://#', '', rtrim( $website_url, '/' ) );

		$header_content = '' !== $logo_url
			? sprintf(
				'<a href="%1$s" target="_blank" style="text-decoration:none;display:inline-block;"><img src="%2$s" alt="%3$s" width="180" style="display:block;width:180px;max-width:100%%;height:auto;border:0;outline:none;text-decoration:none;" /></a>',
				esc_url( $website_url ),
				esc_url( $logo_url ),
				esc_attr( $org_name )
			)
			: sprintf(
				'<a href="%1$s" target="_blank" style="text-decoration:none;color:%2$s;font-family:%3$s;font-size:20px;font-weight:700;letter-spacing:-0.01em;display:inline-block;">%4$s</a>',
				esc_url( $website_url ),
				self::COLOR_TEXT,
				self::FONT_FAMILY,
				esc_html( $org_name )
			);

		ob_start();
		?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="nl">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<meta name="color-scheme" content="light dark" />
	<meta name="supported-color-schemes" content="light dark" />
	<title><?php echo esc_html( $title ); ?></title>
	<style type="text/css">
		body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
		table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
		img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
		body { height: 100% !important; margin: 0 !important; padding: 0 !important; width: 100% !important; background-color: <?php echo self::COLOR_BG; ?>; }
		@media only screen and (max-width: 600px) {
			.gzw-container { width: 100% !important; }
			.gzw-content-cell { padding: 24px 20px !important; }
			.gzw-header-cell { padding: 24px 20px 20px 20px !important; }
			.gzw-footer-cell { padding: 20px 20px 28px 20px !important; }
			.gzw-field-label { width: 40% !important; }
		}
		@media (prefers-color-scheme: dark) {
			body, .gzw-wrapper-table { background-color: #121519 !important; }
			.gzw-container-table { background-color: #1a1e24 !important; border-color: #2e353e !important; }
			.gzw-card { background-color: #22272e !important; border-color: #2e353e !important; }
			.gzw-text-main { color: #f2f5f7 !important; }
			.gzw-text-muted { color: #a0aec0 !important; }
			.gzw-divider { border-color: #2e353e !important; }
			.gzw-table-row td, .gzw-table-row th { border-color: #2e353e !important; }
			.gzw-badge-ok { background-color: #1c3320 !important; color: #8fd69a !important; }
			.gzw-badge-wait { background-color: #3a2a12 !important; color: #f0b05a !important; }
			.gzw-table-heading { background-color: #22272e !important; color: #f2f5f7 !important; border-color: #2e353e !important; }
		}
	</style>
</head>
<body style="margin:0;padding:0;background-color:<?php echo self::COLOR_BG; ?>;font-family:<?php echo self::FONT_FAMILY; ?>;-webkit-font-smoothing:antialiased;">
	<table class="gzw-wrapper-table" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:<?php echo self::COLOR_BG; ?>;width:100%;margin:0;padding:32px 12px;">
		<tr>
			<td align="center" style="padding:0;">
				<!--[if (gte mso 9)|(IE)]>
				<table width="600" align="center" cellpadding="0" cellspacing="0" border="0"><tr><td>
				<![endif]-->
				<table class="gzw-container-table gzw-container" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;margin:0 auto;background-color:<?php echo self::COLOR_CONTAINER; ?>;border-radius:8px;border:1px solid <?php echo self::COLOR_BORDER; ?>;overflow:hidden;">
					<!-- Header -->
					<tr>
						<td class="gzw-header-cell gzw-divider" style="padding:32px 32px 24px 32px;border-bottom:1px solid <?php echo self::COLOR_BORDER; ?>;">
							<?php echo $header_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?>
						</td>
					</tr>
					<!-- Content -->
					<tr>
						<td class="gzw-content-cell" style="padding:32px 32px 28px 32px;">
							<?php echo $content_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by builders ?>
						</td>
					</tr>
					<!-- Footer -->
					<tr>
						<td class="gzw-footer-cell gzw-divider" style="padding:24px 32px 32px 32px;border-top:1px solid <?php echo self::COLOR_BORDER; ?>;font-size:13px;line-height:1.6;color:<?php echo self::COLOR_MUTED; ?>;">
							<div class="gzw-text-main" style="font-weight:700;color:<?php echo self::COLOR_TEXT; ?>;margin-bottom:4px;"><?php echo esc_html( $org_name ); ?></div>
							<div>
								<?php esc_html_e( 'Contact:', 'gezonder-in-werk-booking' ); ?>
								<a href="mailto:<?php echo esc_attr( $contact_email ); ?>" style="color:<?php echo self::COLOR_ACCENT; ?>;text-decoration:underline;"><?php echo esc_html( $contact_email ); ?></a>
								&bull;
								<a href="<?php echo esc_url( $website_url ); ?>" target="_blank" style="color:<?php echo self::COLOR_MUTED; ?>;text-decoration:underline;"><?php echo esc_html( $site_display ); ?></a>
							</div>
						</td>
					</tr>
				</table>
				<!--[if (gte mso 9)|(IE)]>
				</td></tr></table>
				<![endif]-->
			</td>
		</tr>
	</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Renders HTML for enrolee confirmation (single enrolment or cycle), or promotion confirmation.
	 *
	 * @param array<string,string> $values       Validated submission values (or enrolment row).
	 * @param array[]              $enrolments   Enrolment rows with session and course data.
	 * @param ?array               $cycle        Cycle row when this is a cycle enrolment.
	 * @param bool                 $is_promotion Whether this is a promotion email for a previously waitlisted seat.
	 */
	public static function render_confirmation_html( array $values, array $enrolments, ?array $cycle, bool $is_promotion = false ): string {
		$rows       = array_values( $enrolments );
		$waitlisted = (bool) array_filter( $rows, static fn( array $r ) => Installer::STATUS_WAITLISTED === $r['status'] );
		$all_waiting = self::all_waitlisted( $rows );
		$first_name = $values['first_name'] ?? '';
		$org_name   = Settings_Page::org_name();

		$intro = $is_promotion
			? Enrolment_Messages::promotion_intro()
			: Enrolment_Messages::outcome( null !== $cycle, $waitlisted );

		$bundle_total = 0;
		foreach ( $rows as $row ) {
			$bundle_total += (int) $row['price_cents'];
		}

		ob_start();
		?>
		<p class="gzw-text-main" style="margin:0 0 16px 0;font-size:22px;font-weight:700;line-height:1.3;color:<?php echo self::COLOR_TEXT; ?>;">
			<?php echo esc_html( Enrolment_Messages::greeting( $first_name ) ); ?>
		</p>

		<p class="gzw-text-main" style="margin:0 0 24px 0;font-size:16px;line-height:1.6;color:<?php echo self::COLOR_TEXT; ?>;">
			<?php echo esc_html( $intro ); ?>
		</p>

		<?php if ( $cycle ) : ?>
			<div class="gzw-text-main" style="margin:0 0 16px 0;font-size:17px;font-weight:700;color:<?php echo self::COLOR_TEXT; ?>;">
				<?php echo esc_html( $cycle['title'] ); ?>
			</div>
		<?php endif; ?>

		<!-- Summary blocks -->
		<?php foreach ( $rows as $row ) :
			$row_waitlisted = Installer::STATUS_WAITLISTED === $row['status'];
			$session_date   = mysql2date( 'd-m-Y', $row['session_date'] );
			$start_time     = substr( $row['start_time'], 0, 5 );
			$end_time       = substr( $row['end_time'], 0, 5 );
			$time_loc       = sprintf( '%s, %s–%s, %s', $session_date, $start_time, $end_time, $row['location'] );
			$expectation    = ! $row_waitlisted ? Expectation_Texts::for_session( $row ) : '';
			if ( '' !== $expectation && null !== $cycle ) {
				$expectation = sprintf( '%s (%s): %s', $row['course_title'], $session_date, $expectation );
			}
			?>
			<table class="gzw-card" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:<?php echo self::COLOR_CARD_BG; ?>;border:1px solid <?php echo self::COLOR_BORDER; ?>;border-radius:6px;margin:0 0 16px 0;">
				<tr>
					<td style="padding:18px 20px;">
						<div class="gzw-text-main" style="font-size:16px;font-weight:700;color:<?php echo self::COLOR_TEXT; ?>;margin-bottom:6px;">
							<?php echo esc_html( $row['course_title'] ); ?>
						</div>
						<div class="gzw-text-muted" style="font-size:14px;line-height:1.5;color:<?php echo self::COLOR_MUTED; ?>;margin-bottom:10px;">
							<?php echo esc_html( $time_loc ); ?>
						</div>
						<div style="margin-bottom:<?php echo ( null === $cycle || '' !== $expectation ) ? '10px' : '0'; ?>;">
							<span class="<?php echo $row_waitlisted ? 'gzw-badge-wait' : 'gzw-badge-ok'; ?>" style="display:inline-block;padding:3px 9px;font-size:12px;font-weight:600;border-radius:4px;background-color:<?php echo $row_waitlisted ? self::COLOR_BADGE_WAITLIST_BG : self::COLOR_BADGE_CONFIRMED_BG; ?>;color:<?php echo $row_waitlisted ? self::COLOR_BADGE_WAITLIST_TEXT : self::COLOR_BADGE_CONFIRMED_TEXT; ?>;">
								<?php echo esc_html( Labels::status( $row['status'] ) ); ?>
							</span>
						</div>
						<?php if ( null === $cycle ) : ?>
							<div class="gzw-text-main" style="font-size:14px;font-weight:600;color:<?php echo self::COLOR_TEXT; ?>;">
								<?php printf( esc_html__( 'Prijs: %s excl. BTW', 'gezonder-in-werk-booking' ), esc_html( Labels::price( (int) $row['price_cents'] ) ) ); ?>
							</div>
						<?php endif; ?>
						<?php if ( '' !== $expectation ) : ?>
							<div class="gzw-email-expectation gzw-text-main" style="margin-top:12px;padding-top:12px;border-top:1px dashed <?php echo self::COLOR_BORDER; ?>;font-size:13px;line-height:1.5;color:<?php echo self::COLOR_TEXT; ?>;">
								<?php echo esc_html( $expectation ); ?>
							</div>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		<?php endforeach; ?>

		<?php if ( $cycle ) : ?>
			<table class="gzw-card" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:<?php echo self::COLOR_CARD_BG; ?>;border:1px solid <?php echo self::COLOR_BORDER; ?>;border-radius:6px;margin:0 0 24px 0;">
				<tr>
					<td style="padding:14px 20px;">
						<div class="gzw-text-main" style="font-size:15px;font-weight:700;color:<?php echo self::COLOR_TEXT; ?>;">
							<?php printf( esc_html__( 'Prijs hele cyclus: %s excl. BTW', 'gezonder-in-werk-booking' ), esc_html( Labels::price( $bundle_total ) ) ); ?>
						</div>
					</td>
				</tr>
			</table>
		<?php endif; ?>

		<!-- What next / cancellation terms -->
		<div style="margin:24px 0;padding-top:8px;">
			<p class="gzw-text-muted" style="margin:0 0 8px 0;font-size:14px;line-height:1.6;color:<?php echo self::COLOR_MUTED; ?>;">
				<?php echo esc_html( Enrolment_Messages::what_next( $all_waiting ) ); ?>
			</p>
			<?php if ( ! $all_waiting ) : ?>
				<p style="margin:0;font-size:14px;line-height:1.6;">
					<a href="<?php echo esc_url( Enrolment_Messages::cancellation_terms_url() ); ?>" target="_blank" style="color:<?php echo self::COLOR_ACCENT; ?>;text-decoration:underline;">
						<?php echo esc_html( Enrolment_Messages::cancellation_terms_label() ); ?> &rarr;
					</a>
				</p>
			<?php endif; ?>
		</div>

		<!-- Sign-off -->
		<div style="margin-top:28px;">
			<p class="gzw-text-main" style="margin:0;font-size:14px;line-height:1.6;color:<?php echo self::COLOR_TEXT; ?>;">
				<?php echo esc_html( Enrolment_Messages::signoff() ); ?><br />
				<strong><?php echo esc_html( $org_name ); ?></strong>
			</p>
		</div>
		<?php
		$content = (string) ob_get_clean();
		$title   = $waitlisted
			? __( 'Je staat op de wachtlijst', 'gezonder-in-werk-booking' )
			: __( 'Je inschrijving is bevestigd', 'gezonder-in-werk-booking' );

		return self::shell( $title, $content );
	}

	/**
	 * Renders HTML for admin notification email with compact 2-column submitted fields table.
	 *
	 * @param array<string,string> $values       Validated submission values.
	 * @param array[]              $rows         Enrolment rows.
	 * @param ?array               $cycle        Cycle row when this is a cycle enrolment.
	 * @param string               $admin_url    URL to enrolment detail in WordPress admin.
	 */
	public static function render_admin_notification_html( array $values, array $enrolments, ?array $cycle, string $admin_url ): string {
		$rows       = array_values( $enrolments );
		$name       = trim( ( $values['first_name'] ?? '' ) . ' ' . ( $values['last_name'] ?? '' ) );
		$email      = $values['email'] ?? '';
		$employer   = $values['employer'] ?? '';
		$title      = sprintf( __( 'Nieuwe inschrijving: %s', 'gezonder-in-werk-booking' ), $name );

		$bundle_total = 0;
		foreach ( $rows as $row ) {
			$bundle_total += (int) $row['price_cents'];
		}

		// Collect input fields in display order
		$fields_data = array();
		foreach ( Enrolment_Fields::all() as $field_key => $field_def ) {
			if ( ! Enrolment_Fields::is_input( $field_def ) ) {
				continue;
			}
			$raw_val = (string) ( $values[ $field_key ] ?? '' );
			if ( '' === $raw_val ) {
				continue;
			}
			$fields_data[ $field_def['label'] ] = Enrolment_Fields::display_value( $field_def, $raw_val );
		}

		ob_start();
		?>
		<h1 class="gzw-text-main" style="margin:0 0 12px 0;font-size:20px;font-weight:700;line-height:1.3;color:<?php echo self::COLOR_TEXT; ?>;">
			<?php esc_html_e( 'Nieuwe inschrijving', 'gezonder-in-werk-booking' ); ?>
		</h1>

		<p class="gzw-text-main" style="margin:0 0 20px 0;font-size:15px;line-height:1.6;color:<?php echo self::COLOR_TEXT; ?>;">
			<?php echo esc_html( Enrolment_Messages::admin_intro( $name, $email, $employer ) ); ?>
		</p>

		<!-- Admin action button -->
		<table cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;">
			<tr>
				<td align="center" style="border-radius:4px;background-color:<?php echo self::COLOR_ACCENT; ?>;">
					<a href="<?php echo esc_url( $admin_url ); ?>" target="_blank" style="display:inline-block;padding:10px 18px;font-family:<?php echo self::FONT_FAMILY; ?>;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:4px;">
						<?php echo esc_html( Enrolment_Messages::admin_view_button_label() ); ?> &rarr;
					</a>
				</td>
			</tr>
		</table>

		<!-- Session summary card -->
		<div class="gzw-text-main" style="margin:0 0 10px 0;font-size:15px;font-weight:700;color:<?php echo self::COLOR_TEXT; ?>;">
			<?php echo esc_html( Enrolment_Messages::summary_heading() ); ?>
		</div>
		<?php if ( $cycle ) : ?>
			<div class="gzw-text-main" style="margin:0 0 10px 0;font-size:14px;font-weight:600;color:<?php echo self::COLOR_TEXT; ?>;">
				<?php echo esc_html( $cycle['title'] ); ?>
			</div>
		<?php endif; ?>
		<?php foreach ( $rows as $row ) :
			$session_date = mysql2date( 'd-m-Y', $row['session_date'] );
			$start_time   = substr( $row['start_time'], 0, 5 );
			$end_time     = substr( $row['end_time'], 0, 5 );
			$time_loc     = sprintf( '%s, %s–%s, %s', $session_date, $start_time, $end_time, $row['location'] );
			?>
			<table class="gzw-card" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:<?php echo self::COLOR_CARD_BG; ?>;border:1px solid <?php echo self::COLOR_BORDER; ?>;border-radius:6px;margin:0 0 12px 0;">
				<tr>
					<td style="padding:14px 16px;">
						<div class="gzw-text-main" style="font-size:15px;font-weight:700;color:<?php echo self::COLOR_TEXT; ?>;margin-bottom:4px;">
							<?php echo esc_html( $row['course_title'] ); ?>
						</div>
						<div class="gzw-text-muted" style="font-size:13px;line-height:1.5;color:<?php echo self::COLOR_MUTED; ?>;margin-bottom:6px;">
							<?php echo esc_html( $time_loc ); ?>
						</div>
						<div>
							<span class="<?php echo ( Installer::STATUS_WAITLISTED === $row['status'] ) ? 'gzw-badge-wait' : 'gzw-badge-ok'; ?>" style="display:inline-block;padding:2px 8px;font-size:11px;font-weight:600;border-radius:4px;background-color:<?php echo ( Installer::STATUS_WAITLISTED === $row['status'] ) ? self::COLOR_BADGE_WAITLIST_BG : self::COLOR_BADGE_CONFIRMED_BG; ?>;color:<?php echo ( Installer::STATUS_WAITLISTED === $row['status'] ) ? self::COLOR_BADGE_WAITLIST_TEXT : self::COLOR_BADGE_CONFIRMED_TEXT; ?>;">
								<?php echo esc_html( Labels::status( $row['status'] ) ); ?>
							</span>
							<?php if ( null === $cycle ) : ?>
								<span class="gzw-text-main" style="font-size:13px;font-weight:600;color:<?php echo self::COLOR_TEXT; ?>;margin-left:12px;">
									<?php printf( esc_html__( 'Prijs: %s excl. BTW', 'gezonder-in-werk-booking' ), esc_html( Labels::price( (int) $row['price_cents'] ) ) ); ?>
								</span>
							<?php endif; ?>
						</div>
					</td>
				</tr>
			</table>
		<?php endforeach; ?>

		<?php if ( $cycle ) : ?>
			<table class="gzw-card" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:<?php echo self::COLOR_CARD_BG; ?>;border:1px solid <?php echo self::COLOR_BORDER; ?>;border-radius:6px;margin:0 0 20px 0;">
				<tr>
					<td style="padding:12px 16px;">
						<div class="gzw-text-main" style="font-size:14px;font-weight:700;color:<?php echo self::COLOR_TEXT; ?>;">
							<?php printf( esc_html__( 'Prijs hele cyclus: %s excl. BTW', 'gezonder-in-werk-booking' ), esc_html( Labels::price( $bundle_total ) ) ); ?>
						</div>
					</td>
				</tr>
			</table>
		<?php endif; ?>

		<!-- Two-column submitted fields table -->
		<div class="gzw-text-main" style="margin:20px 0 10px 0;font-size:15px;font-weight:700;color:<?php echo self::COLOR_TEXT; ?>;">
			<?php echo esc_html( Enrolment_Messages::admin_fields_heading() ); ?>
		</div>
		<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;border:1px solid <?php echo self::COLOR_BORDER; ?>;border-radius:6px;overflow:hidden;margin:0 0 24px 0;">
			<?php $i = 0; foreach ( $fields_data as $label => $val ) :
				$row_bg = 0 === $i % 2 ? self::COLOR_CONTAINER : self::COLOR_CARD_BG;
				$i++;
				?>
				<tr class="gzw-table-row" style="background-color:<?php echo $row_bg; ?>;">
					<td class="gzw-field-label gzw-text-muted" style="padding:10px 14px;border-bottom:1px solid <?php echo self::COLOR_BORDER; ?>;font-size:13px;font-weight:600;color:<?php echo self::COLOR_MUTED; ?>;width:35%;vertical-align:top;">
						<?php echo esc_html( $label ); ?>
					</td>
					<td class="gzw-text-main" style="padding:10px 14px;border-bottom:1px solid <?php echo self::COLOR_BORDER; ?>;font-size:13px;color:<?php echo self::COLOR_TEXT; ?>;vertical-align:top;">
						<?php echo esc_html( $val ); ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
		$content = (string) ob_get_clean();
		return self::shell( $title, $content );
	}

	/**
	 * Plain-text alternative for confirmation email.
	 */
	public static function render_confirmation_plain( array $values, array $enrolments, ?array $cycle, array $expectations = array(), bool $is_promotion = false ): string {
		$rows       = array_values( $enrolments );
		$waitlisted = (bool) array_filter( $rows, static fn( array $r ) => Installer::STATUS_WAITLISTED === $r['status'] );
		$first_name = $values['first_name'] ?? '';
		$org_name   = Settings_Page::org_name();
		$contact    = Settings_Page::contact_email();

		$intro = $is_promotion
			? Enrolment_Messages::promotion_intro()
			: Enrolment_Messages::outcome( null !== $cycle, $waitlisted );

		$lines = array();
		$lines[] = Enrolment_Messages::greeting( $first_name );
		$lines[] = '';
		$lines[] = $intro;
		$lines[] = '';

		$lines = array_merge( $lines, self::plain_summary( $rows, $cycle ) );
		$lines[] = '';

		if ( ! empty( $expectations ) ) {
			foreach ( $expectations as $exp ) {
				$lines[] = $exp;
			}
			$lines[] = '';
		}

		$all_waiting = self::all_waitlisted( $rows );
		$lines[]     = Enrolment_Messages::what_next( $all_waiting );
		if ( ! $all_waiting ) {
			$lines[] = Enrolment_Messages::cancellation_terms_label() . ': ' . Enrolment_Messages::cancellation_terms_url();
		}
		$lines[] = '';
		$lines[] = Enrolment_Messages::signoff();
		$lines[] = $org_name;
		$lines[] = $contact;

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Plain-text alternative for admin notification email.
	 */
	public static function render_admin_notification_plain( array $values, array $enrolments, ?array $cycle, string $admin_url ): string {
		$rows     = array_values( $enrolments );
		$name     = trim( ( $values['first_name'] ?? '' ) . ' ' . ( $values['last_name'] ?? '' ) );
		$email    = $values['email'] ?? '';
		$employer = $values['employer'] ?? '';
		$org_name = Settings_Page::org_name();
		$contact  = Settings_Page::contact_email();

		$lines   = array();
		$lines[] = Enrolment_Messages::admin_intro( $name, $email, $employer );
		$lines[] = '';
		$lines[] = Enrolment_Messages::summary_heading() . ':';
		$lines   = array_merge( $lines, self::plain_summary( $rows, $cycle ) );
		$lines[] = '';
		$lines[] = sprintf( __( 'Bekijk de inschrijving: %s', 'gezonder-in-werk-booking' ), $admin_url );
		$lines[] = '';
		$lines[] = Enrolment_Messages::admin_fields_heading() . ':';

		foreach ( Enrolment_Fields::all() as $field_key => $field_def ) {
			if ( ! Enrolment_Fields::is_input( $field_def ) ) {
				continue;
			}
			$raw_val = (string) ( $values[ $field_key ] ?? '' );
			if ( '' === $raw_val ) {
				continue;
			}
			$lines[] = sprintf( '- %s: %s', $field_def['label'], Enrolment_Fields::display_value( $field_def, $raw_val ) );
		}

		$lines[] = '';
		$lines[] = $org_name;
		$lines[] = $contact;

		return implode( "\n", $lines ) . "\n";
	}

	/** True when every row is waitlisted: only then is the neutral "no promises" wording right. */
	private static function all_waitlisted( array $rows ): bool {
		return $rows && ! array_filter( $rows, static fn( array $r ) => Installer::STATUS_WAITLISTED !== $r['status'] );
	}

	/**
	 * Plain-text lines: cycle title, one line per session with its status, then the price (bundle total for a cycle).
	 *
	 * @param array[] $rows
	 * @return string[]
	 */
	private static function plain_summary( array $rows, ?array $cycle ): array {
		$lines = array();
		if ( $cycle ) {
			$lines[] = $cycle['title'];
		}
		$total = 0;
		foreach ( $rows as $row ) {
			$total  += (int) $row['price_cents'];
			$lines[] = sprintf(
				'- %1$s – %2$s, %3$s–%4$s, %5$s – %6$s',
				$row['course_title'],
				mysql2date( 'd-m-Y', $row['session_date'] ),
				substr( $row['start_time'], 0, 5 ),
				substr( $row['end_time'], 0, 5 ),
				$row['location'],
				Labels::status( $row['status'] )
			);
		}
		$lines[] = sprintf(
			$cycle ? __( 'Prijs hele cyclus: %s excl. BTW', 'gezonder-in-werk-booking' ) : __( 'Prijs: %s excl. BTW', 'gezonder-in-werk-booking' ),
			Labels::price( $total )
		);
		return $lines;
	}
}
