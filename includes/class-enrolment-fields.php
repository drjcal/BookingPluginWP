<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * The single definition of the enrolment fields. Validation, the form, and the
 * storage schema all read from here, so a new field is one entry in all().
 *
 * Keys per field:
 *  - label        Display label (without the "optional" suffix).
 *  - type         HTML input type; 'email' also validates as an e-mail address.
 *  - autocomplete HTML autocomplete token.
 *  - required     Whether an empty value is rejected.
 *  - max          Maximum length in characters.
 *  - group        GROUP_PERSON or GROUP_BILLING (form fieldset).
 *  - storage      SQL column type; optional fields get NOT NULL DEFAULT ''.
 *  - normalise    (optional) callable applied to the value before validation.
 *  - pattern      (optional) regex the normalised value must match when given.
 *  - pattern_message (optional) error shown when the pattern fails.
 *  - inputmode / describedby (optional) form attributes.
 */
class Enrolment_Fields {

	const GROUP_PERSON  = 'person';
	const GROUP_BILLING = 'billing';

	const MAX_LENGTH = 100;

	/** @return array<string,array<string,mixed>> */
	public static function all(): array {
		$d = 'gezonder-in-werk-booking';

		return array(
			'first_name'        => array(
				'label'        => __( 'Voornaam', $d ),
				'type'         => 'text',
				'autocomplete' => 'given-name',
				'required'     => true,
				'group'        => self::GROUP_PERSON,
				'storage'      => 'varchar(100)',
			),
			'last_name'         => array(
				'label'        => __( 'Achternaam', $d ),
				'type'         => 'text',
				'autocomplete' => 'family-name',
				'required'     => true,
				'group'        => self::GROUP_PERSON,
				'storage'      => 'varchar(100)',
			),
			'email'             => array(
				'label'        => __( 'E-mailadres', $d ),
				'type'         => 'email',
				'autocomplete' => 'email',
				'required'     => true,
				'group'        => self::GROUP_PERSON,
				'storage'      => 'varchar(190)',
			),
			'employer'          => array(
				'label'        => __( 'Werkzaam bij', $d ),
				'type'         => 'text',
				'autocomplete' => 'organization',
				'required'     => true,
				'group'        => self::GROUP_PERSON,
				'storage'      => 'varchar(190)',
			),
			'big_number'        => array(
				'label'           => __( 'BIG-registratienummer', $d ),
				'type'            => 'text',
				'autocomplete'    => 'off',
				'required'        => false,
				'group'           => self::GROUP_PERSON,
				'storage'         => 'varchar(11)',
				'normalise'       => array( self::class, 'strip_whitespace' ),
				'pattern'         => '/^\d{11}$/',
				'pattern_message' => __( 'Een BIG-registratienummer bestaat uit 11 cijfers.', $d ),
				'inputmode'       => 'numeric',
				'describedby'     => 'gzw-big-hint',
			),
			'invoice_name'      => array(
				'label'        => __( 'Factuurnaam', $d ),
				'type'         => 'text',
				'autocomplete' => 'organization',
				'required'     => true,
				'group'        => self::GROUP_BILLING,
				'storage'      => 'varchar(190)',
			),
			'invoice_street'    => array(
				'label'        => __( 'Straat en huisnummer', $d ),
				'type'         => 'text',
				'autocomplete' => 'street-address',
				'required'     => true,
				'group'        => self::GROUP_BILLING,
				'storage'      => 'varchar(190)',
			),
			'invoice_postcode'  => array(
				'label'        => __( 'Postcode', $d ),
				'type'         => 'text',
				'autocomplete' => 'postal-code',
				'required'     => true,
				'group'        => self::GROUP_BILLING,
				'storage'      => 'varchar(20)',
			),
			'invoice_city'      => array(
				'label'        => __( 'Plaats', $d ),
				'type'         => 'text',
				'autocomplete' => 'address-level2',
				'required'     => true,
				'group'        => self::GROUP_BILLING,
				'storage'      => 'varchar(100)',
			),
			'invoice_email'     => array(
				'label'        => __( 'Factuur e-mailadres', $d ),
				'type'         => 'email',
				'autocomplete' => 'off',
				'required'     => false,
				'group'        => self::GROUP_BILLING,
				'storage'      => 'varchar(190)',
			),
			'invoice_reference' => array(
				'label'        => __( 'PO- of referentienummer', $d ),
				'type'         => 'text',
				'autocomplete' => 'off',
				'required'     => false,
				'group'        => self::GROUP_BILLING,
				'storage'      => 'varchar(190)',
			),
			'invoice_kvk'       => array(
				'label'        => __( 'KvK-nummer', $d ),
				'type'         => 'text',
				'autocomplete' => 'off',
				'required'     => false,
				'group'        => self::GROUP_BILLING,
				'storage'      => 'varchar(50)',
			),
			'invoice_btw'       => array(
				'label'        => __( 'BTW-nummer', $d ),
				'type'         => 'text',
				'autocomplete' => 'off',
				'required'     => false,
				'group'        => self::GROUP_BILLING,
				'storage'      => 'varchar(50)',
			),
		);
	}

	/** @return array<string,array<string,mixed>> Fields of one group, keyed by name. */
	public static function in_group( string $group ): array {
		return array_filter( self::all(), static fn( array $f ) => $group === $f['group'] );
	}

	public static function max_length( array $field ): int {
		return (int) ( $field['max'] ?? self::MAX_LENGTH );
	}

	public static function strip_whitespace( string $value ): string {
		return (string) preg_replace( '/\s+/', '', $value );
	}

	/** SQL column definitions, one per field, for the enrolment table. */
	public static function column_sql(): array {
		$columns = array();
		foreach ( self::all() as $name => $field ) {
			$columns[] = $name . ' ' . $field['storage'] . ( $field['required'] ? ' NOT NULL' : " NOT NULL DEFAULT ''" );
		}
		return $columns;
	}
}
