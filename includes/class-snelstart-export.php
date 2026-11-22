<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * SnelStart booking import file for one submission: one sales invoice as three lines
 * (debtors gross, revenue net, VAT) sharing a booking code. Customer code and invoice
 * number stay empty: staff fill them in before importing.
 */
class Snelstart_Export {

	const VAT_PERCENT = 21;

	const HEADER = array( 'fldDagboek', 'fldBoekingcode', 'fldDatum', 'fldGrootboeknummer', 'fldDebet', 'fldCredit', 'fldOmschrijving', 'fldRelatiecode', 'fldFactuurnummer' );

	/**
	 * The CSV, or '' when the ledger numbers are not configured or there is nothing to book.
	 *
	 * @param array<string,string> $values     Validated submission values.
	 * @param array[]              $enrolments Created enrolments, with session and course.
	 */
	public static function build( array $values, array $enrolments, ?array $cycle ): string {
		$ledgers = Settings_Page::snelstart_ledgers();
		$rows    = array_values( $enrolments );
		if ( ! $ledgers || ! $rows ) {
			return '';
		}
		$confirmed = array_filter( $rows, static fn( array $r ) => Installer::STATUS_WAITLISTED !== $r['status'] );
		$net       = array_sum( array_map( static fn( array $r ) => (int) $r['price_cents'], $rows ) );
		if ( ! $confirmed || $net <= 0 ) {
			return ''; // Nothing to invoice yet (waitlisted) or nothing to book (free).
		}
		$vat = (int) round( $net * self::VAT_PERCENT / 100 );

		$dates = array_map( static fn( array $r ) => mysql2date( 'd-m-Y', $r['session_date'] ), $rows );
		$parts = array(
			$cycle ? $cycle['title'] : $rows[0]['course_title'],
			implode( ', ', $dates ),
			(string) ( $values['invoice_name'] ?? '' ),
			trim( $values['first_name'] . ' ' . $values['last_name'] ),
			(string) ( $values['invoice_reference'] ?? '' ),
		);
		$description = implode( ' – ', array_filter( $parts, static fn( string $p ) => '' !== $p ) );
		$code        = (string) $rows[0]['id'];
		$date        = wp_date( 'd-m-Y' );

		$lines = array(
			array( $ledgers['debtors'], $net + $vat, 0 ),
			array( $ledgers['revenue'], 0, $net ),
			array( $ledgers['vat'], 0, $vat ),
		);
		$out = fopen( 'php://temp', 'r+' );
		fputcsv( $out, self::HEADER, ';', '"', '' );
		foreach ( $lines as list( $ledger, $debit, $credit ) ) {
			fputcsv( $out, array( $ledgers['journal'], $code, $date, $ledger, self::amount( $debit ), self::amount( $credit ), $description, '', '' ), ';', '"', '' );
		}
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out );
		return Enrolments_Export::BOM . $csv;
	}

	/** Decimal comma, no thousands separator. */
	private static function amount( int $cents ): string {
		return number_format( $cents / 100, 2, ',', '' );
	}
}
