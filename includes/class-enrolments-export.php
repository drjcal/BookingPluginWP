<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * CSV export of the admin enrolment list: same filters, one row per enrolment.
 * Semicolon-delimited with a UTF-8 BOM so Excel (Dutch settings) opens it correctly.
 */
class Enrolments_Export {

	const ACTION = 'gzw_export_enrolments';
	const CAP    = Installer::CAP_MANAGE_ENROLMENTS;
	const BOM    = "\xEF\xBB\xBF";

	/** Nonce-protected download URL carrying the active list filters. */
	public static function url( array $filters ): string {
		$args = array_filter( $filters, static fn( $v ) => '' !== $v && 0 !== $v );
		if ( isset( $args['search'] ) ) {
			$args['s'] = $args['search']; // Same query var as the list.
			unset( $args['search'] );
		}
		$args['action']   = self::ACTION;
		$args['_wpnonce'] = wp_create_nonce( self::ACTION );
		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	public static function handle_export(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Geen toegang.', 'gezonder-in-werk-booking' ), 403 );
		}
		check_admin_referer( self::ACTION );

		self::send( self::build( Enrolments_Admin::filters() ), 'inschrijvingen-' . gmdate( 'Y-m-d' ) . '.csv' );
	}

	private static function send( string $csv, string $filename ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput -- CSV download, not HTML.
		exit;
	}

	/** The complete CSV (BOM, header row, one row per matching enrolment). */
	public static function build( array $filters ): string {
		$fields  = Enrolment_Fields::all();
		$headers = array(
			__( 'Ingeschreven op', 'gezonder-in-werk-booking' ),
			__( 'Cursus', 'gezonder-in-werk-booking' ),
			__( 'Sessie', 'gezonder-in-werk-booking' ),
		);
		foreach ( $fields as $field ) {
			$headers[] = $field['label'];
		}
		array_push(
			$headers,
			__( 'Prijs excl. BTW', 'gezonder-in-werk-booking' ),
			__( 'Status', 'gezonder-in-werk-booking' ),
			__( 'Gefactureerd', 'gezonder-in-werk-booking' ),
			__( 'Interne notitie', 'gezonder-in-werk-booking' )
		);

		$out = fopen( 'php://temp', 'r+' );
		self::put( $out, $headers );
		foreach ( Enrolment_Repository::all( $filters ) as $row ) {
			$cells = array(
				mysql2date( 'd-m-Y H:i', get_date_from_gmt( $row['created_at'] ) ),
				(string) $row['course_title'],
				Enrolments_Admin::session_label( $row ),
			);
			foreach ( array_keys( $fields ) as $name ) {
				$cells[] = (string) $row[ $name ];
			}
			array_push(
				$cells,
				Catalog_Repository::format_price( (int) $row['price_cents'] ),
				Enrolments_Admin::status_label( $row['status'] ),
				(int) $row['invoiced'] ? __( 'Ja', 'gezonder-in-werk-booking' ) : __( 'Nee', 'gezonder-in-werk-booking' ),
				(string) $row['internal_note']
			);
			self::put( $out, $cells );
		}
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out );

		return self::BOM . $csv;
	}

	/** @param resource $out */
	private static function put( $out, array $cells ): void {
		fputcsv( $out, array_map( array( self::class, 'neutralise' ), $cells ), ';', '"', '' );
	}

	/** Prefix cells Excel would read as a formula with an apostrophe (CSV injection). */
	public static function neutralise( string $value ): string {
		return '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ? "'" . $value : $value;
	}
}
