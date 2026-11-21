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
 *  - group        A key of groups() (form fieldset / admin detail heading).
 *  - storage      SQL column type; optional fields get NOT NULL DEFAULT ''.
 *  - normalise    (optional) callable applied to the value before validation.
 *  - pattern      (optional) regex the normalised value must match when given.
 *  - pattern_message (optional) error shown when the pattern fails.
 *  - inputmode / describedby (optional) form attributes.
 *  - input        (optional) false for fields the system sets itself: never read from the
 *                 request, never validated, never shown on the form.
 *  - list         (optional) true to show the field as a column in the admin list.
 *  - search       (optional) true to include the field in the admin list search.
 *  - default      (optional) SQL default for the column; makes the column NOT NULL DEFAULT <default>.
 *  - retain       (optional) true to keep the value when an enrolment is anonymised (no personal data).
 *
 * Type 'checkbox' fields are stored as '1' (ticked) or '0'.
 */
class Enrolment_Fields {

	const GROUP_PERSON  = 'person';
	const GROUP_BILLING = 'billing';
	const GROUP_CYCLE   = 'cycle';
	const GROUP_CONSENT = 'consent';

	const MAX_LENGTH = 100;

	/**
	 * Field groups in display order: key => heading. The admin detail view renders every group
	 * listed here, so a new group is one entry.
	 *
	 * @return array<string,string>
	 */
	public static function groups(): array {
		return array(
			self::GROUP_PERSON  => __( 'Deelnemer', 'gezonder-in-werk-booking' ),
			self::GROUP_BILLING => __( 'Factuurgegevens', 'gezonder-in-werk-booking' ),
			self::GROUP_CYCLE   => __( 'Cyclus', 'gezonder-in-werk-booking' ),
			self::GROUP_CONSENT => __( 'Toestemming', 'gezonder-in-werk-booking' ),
		);
	}

	/** Whether the value comes from the public request (false: set by the system). */
	public static function is_input( array $field ): bool {
		return false !== ( $field['input'] ?? true );
	}

	/** @return array<string,array<string,mixed>> */
	public static function all(): array {
		return array(
			'first_name'        => array(
				'label'        => __( 'Voornaam', 'gezonder-in-werk-booking' ),
				'type'         => 'text',
				'autocomplete' => 'given-name',
				'required'     => true,
				'group'        => self::GROUP_PERSON,
				'storage'      => 'varchar(100)',
				'list'         => true,
				'search'       => true,
			),
			'last_name'         => array(
				'label'        => __( 'Achternaam', 'gezonder-in-werk-booking' ),
				'type'         => 'text',
				'autocomplete' => 'family-name',
				'required'     => true,
				'group'        => self::GROUP_PERSON,
				'storage'      => 'varchar(100)',
				'list'         => true,
				'search'       => true,
			),
			'email'             => array(
				'label'        => __( 'E-mailadres', 'gezonder-in-werk-booking' ),
				'type'         => 'email',
				'autocomplete' => 'email',
				'required'     => true,
				'group'        => self::GROUP_PERSON,
				'storage'      => 'varchar(190)',
				'list'         => true,
				'search'       => true,
			),
			'employer'          => array(
				'label'        => __( 'Werkzaam bij', 'gezonder-in-werk-booking' ),
				'type'         => 'text',
				'autocomplete' => 'organization',
				'required'     => true,
				'group'        => self::GROUP_PERSON,
				'storage'      => 'varchar(190)',
				'list'         => true,
				'search'       => true,
			),
			'big_number'        => array(
				'label'           => __( 'BIG-registratienummer', 'gezonder-in-werk-booking' ),
				'type'            => 'text',
				'autocomplete'    => 'off',
				'required'        => false,
				'group'           => self::GROUP_PERSON,
				'storage'         => 'varchar(11)',
				'normalise'       => array( self::class, 'strip_whitespace' ),
				'pattern'         => '/^\d{11}$/',
				'pattern_message' => __( 'Een BIG-registratienummer bestaat uit 11 cijfers.', 'gezonder-in-werk-booking' ),
				'inputmode'       => 'numeric',
				'describedby'     => 'gzw-big-hint',
				'list'            => true,
			),
			'invoice_name'      => array(
				'label'        => __( 'Factuurnaam', 'gezonder-in-werk-booking' ),
				'type'         => 'text',
				'autocomplete' => 'organization',
				'required'     => true,
				'group'        => self::GROUP_BILLING,
				'storage'      => 'varchar(190)',
			),
			'invoice_street'    => array(
				'label'        => __( 'Straat en huisnummer', 'gezonder-in-werk-booking' ),
				'type'         => 'text',
				'autocomplete' => 'street-address',
				'required'     => true,
				'group'        => self::GROUP_BILLING,
				'storage'      => 'varchar(190)',
			),
			'invoice_postcode'  => array(
				'label'        => __( 'Postcode', 'gezonder-in-werk-booking' ),
				'type'         => 'text',
				'autocomplete' => 'postal-code',
				'required'     => true,
				'group'        => self::GROUP_BILLING,
				'storage'      => 'varchar(20)',
			),
			'invoice_city'      => array(
				'label'        => __( 'Plaats', 'gezonder-in-werk-booking' ),
				'type'         => 'text',
				'autocomplete' => 'address-level2',
				'required'     => true,
				'group'        => self::GROUP_BILLING,
				'storage'      => 'varchar(100)',
			),
			'invoice_email'     => array(
				'label'        => __( 'Factuur e-mailadres', 'gezonder-in-werk-booking' ),
				'type'         => 'email',
				'autocomplete' => 'off',
				'required'     => false,
				'group'        => self::GROUP_BILLING,
				'storage'      => 'varchar(190)',
			),
			'invoice_reference' => array(
				'label'        => __( 'PO- of referentienummer', 'gezonder-in-werk-booking' ),
				'type'         => 'text',
				'autocomplete' => 'off',
				'required'     => false,
				'group'        => self::GROUP_BILLING,
				'storage'      => 'varchar(190)',
			),
			'invoice_kvk'       => array(
				'label'        => __( 'KvK-nummer', 'gezonder-in-werk-booking' ),
				'type'         => 'text',
				'autocomplete' => 'off',
				'required'     => false,
				'group'        => self::GROUP_BILLING,
				'storage'      => 'varchar(50)',
			),
			'invoice_btw'       => array(
				'label'        => __( 'BTW-nummer', 'gezonder-in-werk-booking' ),
				'type'         => 'text',
				'autocomplete' => 'off',
				'required'     => false,
				'group'        => self::GROUP_BILLING,
				'storage'      => 'varchar(50)',
			),
			'group_reference'   => array(
				'label'    => __( 'Groepsreferentie (cyclus)', 'gezonder-in-werk-booking' ),
				'type'     => 'text',
				'required' => false,
				'group'    => self::GROUP_CYCLE,
				'storage'  => 'varchar(40)',
				'input'    => false,
				'list'     => true,
				'search'   => true,
				'retain'   => true,
			),
			'consent_cancellation' => array(
				'label'    => __( 'Akkoord met de annuleringsvoorwaarden', 'gezonder-in-werk-booking' ),
				'type'     => 'checkbox',
				'required' => true,
				'group'    => self::GROUP_CONSENT,
				'storage'  => 'tinyint(1)',
				'default'  => '0',
				'retain'   => true,
			),
			'consent_privacy'      => array(
				'label'    => __( 'Akkoord met de privacyverklaring', 'gezonder-in-werk-booking' ),
				'type'     => 'checkbox',
				'required' => true,
				'group'    => self::GROUP_CONSENT,
				'storage'  => 'tinyint(1)',
				'default'  => '0',
				'retain'   => true,
			),
		);
	}

	/** A value as shown to staff: checkboxes read Ja/Nee, everything else as stored. */
	public static function display_value( array $field, string $value ): string {
		if ( 'checkbox' !== $field['type'] ) {
			return $value;
		}
		return '1' === $value ? __( 'Ja', 'gezonder-in-werk-booking' ) : __( 'Nee', 'gezonder-in-werk-booking' );
	}

	/** @return array<string,array<string,mixed>> Fields of one group, keyed by name. */
	public static function in_group( string $group ): array {
		return array_filter( self::all(), static fn( array $f ) => $group === $f['group'] );
	}

	/** @return array<string,array<string,mixed>> Fields shown as admin list columns. */
	public static function list_fields(): array {
		return array_filter( self::all(), static fn( array $f ) => ! empty( $f['list'] ) );
	}

	/** @return string[] Names of the fields the admin list search looks in. */
	public static function search_fields(): array {
		return array_keys( array_filter( self::all(), static fn( array $f ) => ! empty( $f['search'] ) ) );
	}

	/** Maximum length of a field value in characters. */
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
			$default   = $field['default'] ?? ( $field['required'] ? null : '' );
			$columns[] = $name . ' ' . $field['storage'] . ' NOT NULL' . ( null === $default ? '' : " DEFAULT '" . $default . "'" );
		}
		return $columns;
	}
}
