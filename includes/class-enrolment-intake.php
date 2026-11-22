<?php
namespace GezonderInWerk\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * The result of an intake: created, rejected (with field errors) or storage failed.
 */
class Intake_Outcome {

	const CREATED        = 'created';
	const REJECTED       = 'rejected';
	const STORAGE_FAILED = 'storage_failed';
	/** The seat lock was not obtained in time: nothing was stored, trying again is safe. */
	const BUSY           = 'busy';

	/** @var string */
	public $kind;
	/** @var array<string,string> Field errors (rejected only). */
	public $errors = array();
	/** @var array<string,string> Submitted values. */
	public $values = array();
	/** @var array[] Created enrolments with session and course (created only). */
	public $enrolments = array();
	/** @var bool Whether the submission was for a whole cycle. */
	public $is_cycle = false;
	/** @var bool Single session: waitlisted. Cycle: at least one webinar is waitlisted. */
	public $waitlisted = false;
	/** @var string */
	public $group_reference = '';
	/** @var string Wording for the enrolee (created only). */
	public $message = '';
	/** @var string[] When the information arrives, for the confirmed enrolments (created only). */
	public $expectations = array();

	public function __construct( string $kind ) {
		$this->kind = $kind;
	}

	public function status(): string {
		return $this->waitlisted ? Installer::STATUS_WAITLISTED : Installer::STATUS_CONFIRMED;
	}

	/** @return int[] */
	public function ids(): array {
		return array_map( static fn( array $row ) => (int) $row['id'], $this->enrolments );
	}
}

/**
 * Enrolment intake: raw submitted data in, one typed outcome out. Decides once whether the
 * submission is for a single session or a whole cycle, owns the closed/duplicate rules and sends
 * the confirmation mails for what was created.
 */
class Enrolment_Intake {

	/** @var ?Mail_Adapter */
	private static $mail = null;

	/** Swap the mail adapter (tests); null restores wp_mail(). */
	public static function use_mail_adapter( ?Mail_Adapter $mail ): void {
		self::$mail = $mail;
	}

	public static function mail(): Mail_Adapter {
		return self::$mail ?? new Wp_Mail_Adapter();
	}

	/** Form value of the select option that stands for a whole cycle: "cycle-<id>". */
	private static function cycle_id_from( string $target ): int {
		return preg_match( '/^cycle-(\d+)$/', $target, $m ) ? (int) $m[1] : 0;
	}

	/** @param array $input Raw request values. */
	public static function submit( array $input ): Intake_Outcome {
		$checked = Enrolment_Validator::validate( $input );
		$values  = $checked['values'];
		$errors  = $checked['errors'];

		$target   = $values['session_id'];
		$cycle_id = self::cycle_id_from( $target );
		$cycle    = $cycle_id ? Catalog_Repository::get_cycle( $cycle_id ) : null;
		$session  = ( ! $cycle_id && ctype_digit( $target ) ) ? Catalog_Repository::get_session( (int) $target ) : null;

		if ( $cycle_id ) {
			$is_cycle = true;
			$sessions = $cycle ? $cycle['sessions'] : array();
			$price    = $cycle ? (int) $cycle['price_cents'] : 0; // Snapshot of the bundle price.
			$bookable = $cycle && Catalog_Repository::is_cycle_bookable( $cycle );
		} else {
			$is_cycle = false;
			$sessions = $session ? array( $session ) : array();
			$price    = $session ? (int) $session['price_cents'] : 0; // Snapshot.
			$bookable = $session && Catalog_Repository::is_bookable( $session );
		}

		if ( ! $sessions ) {
			$cycle                = null;
			$errors['session_id'] = Enrolment_Messages::no_session();
		} elseif ( ! $bookable ) {
			$errors['session_id'] = Enrolment_Messages::closed( $is_cycle );
		} else {
			$values['price_cents'] = (string) $price;
			if ( ! isset( $errors['email'] ) ) {
				foreach ( $sessions as $target_session ) {
					if ( Enrolment_Repository::has_active( (int) $target_session['id'], $values['email'] ) ) {
						$errors['email'] = Enrolment_Messages::duplicate( $is_cycle );
						break;
					}
				}
			}
		}

		if ( $errors ) {
			return self::rejected( $errors, $values );
		}

		// The checks above give early field errors. Seat allocation re-checks closed and duplicate
		// under the session lock, which is the authoritative guard against concurrent submissions.
		$stored = $is_cycle ? Seat_Allocation::enrol_cycle( $values, $cycle ) : Seat_Allocation::enrol( $values );
		if ( 'closed' === $stored['error'] ) {
			return self::rejected( array( 'session_id' => Enrolment_Messages::closed( $is_cycle ) ), $values );
		}
		if ( 'duplicate' === $stored['error'] ) {
			return self::rejected( array( 'email' => Enrolment_Messages::duplicate( $is_cycle ) ), $values );
		}
		if ( 'busy' === $stored['error'] ) {
			return new Intake_Outcome( Intake_Outcome::BUSY );
		}
		if ( $stored['error'] ) {
			return new Intake_Outcome( Intake_Outcome::STORAGE_FAILED );
		}

		$outcome            = new Intake_Outcome( Intake_Outcome::CREATED );
		$outcome->values    = $values;
		$outcome->is_cycle  = $is_cycle;
		$outcome->enrolments = array_values( array_filter( array_map( array( Enrolment_Repository::class, 'get' ), $is_cycle ? $stored['ids'] : array( $stored['id'] ) ) ) );
		$outcome->waitlisted = Installer::STATUS_WAITLISTED === $stored['status'];
		$outcome->group_reference = $is_cycle ? $stored['group_reference'] : '';
		$outcome->message   = Enrolment_Messages::outcome( $is_cycle, $outcome->waitlisted );
		$outcome->expectations = Expectation_Texts::for_enrolments( $outcome->enrolments, $is_cycle );

		Enrolment_Mailer::send_for_submission( self::mail(), $values, $outcome->enrolments, $is_cycle ? $cycle : null );
		return $outcome;
	}

	private static function rejected( array $errors, array $values ): Intake_Outcome {
		$outcome         = new Intake_Outcome( Intake_Outcome::REJECTED );
		$outcome->errors = $errors;
		$outcome->values = $values;
		return $outcome;
	}
}
