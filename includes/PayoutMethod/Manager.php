<?php
/**
 * The payout method registry.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\PayoutMethod;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\PayoutMethod;
use FlyAffiliate\Models\Affiliate;
use FlyAffiliate\Models\Payout;

/**
 * Every way affiliates can be paid, and what follows from it.
 *
 * Manual is built in and always on. Another plugin adds a method through the
 * `flyaffiliate_payout_methods` filter; FlyAffiliate itself never names one.
 * The registry decides which method pays an affiliate (their own choice, the
 * default setting, then Manual), collects what each automatic method reports
 * about its transfers, and from that whether a payment is *locked*: while
 * money is on its way, or was sent by a method that is not there any more,
 * nothing may change the payment by hand (ADR-0016).
 *
 * The list is read fresh on every call: a plugin can switch a method on in
 * the middle of a request, and the instances it returns are its own shared
 * services, so building the list is cheap.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Manager {

	/**
	 * Transfer states in which the money is on its way.
	 *
	 * @var string[]
	 */
	const IN_FLIGHT_STATES = [
		PayoutMethod::STATE_QUEUED,
		PayoutMethod::STATE_PROCESSING,
		PayoutMethod::STATE_PENDING,
	];

	/**
	 * Every transfer state a method may report.
	 *
	 * @var string[]
	 */
	const STATES = [
		PayoutMethod::STATE_QUEUED,
		PayoutMethod::STATE_PROCESSING,
		PayoutMethod::STATE_PENDING,
		PayoutMethod::STATE_PAID,
		PayoutMethod::STATE_FAILED,
	];

	/**
	 * Locked: the method is sending the money.
	 *
	 * @var string
	 */
	const LOCK_IN_FLIGHT = 'in_flight';

	/**
	 * Locked: the method sent the money and it arrived.
	 *
	 * @var string
	 */
	const LOCK_SETTLED = 'settled';

	/**
	 * Locked: the payment's method is not registered (its plugin is off).
	 *
	 * @var string
	 */
	const LOCK_UNAVAILABLE = 'unavailable';

	/**
	 * The longest method id the payments table can store.
	 *
	 * @var int
	 */
	const MAX_ID_LENGTH = 20;

	/**
	 * The built-in Manual method.
	 *
	 * @var Manual|null
	 */
	protected ?Manual $manual = null;

	/**
	 * Every registered method, Manual first.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, PayoutMethod> Method id => method.
	 */
	public function all(): array {
		$manual = $this->get_manual();

		/**
		 * Filters the payout methods.
		 *
		 * Add an instance of `FlyAffiliate\Contracts\PayoutMethod`, keyed by its
		 * id. Manual is always there and cannot be replaced; an entry that is not
		 * a payout method, or whose id is not lowercase `a-z0-9_` of at most 20
		 * characters, is dropped.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param array<string, PayoutMethod> $methods Method id => method.
		 */
		$filtered = (array) apply_filters( 'flyaffiliate_payout_methods', [ $manual->get_id() => $manual ] );
		$methods  = [ $manual->get_id() => $manual ];

		foreach ( $filtered as $method ) {
			if ( ! $method instanceof PayoutMethod ) {
				continue;
			}

			$id = $method->get_id();

			if ( '' === $id || sanitize_key( $id ) !== $id || strlen( $id ) > self::MAX_ID_LENGTH || isset( $methods[ $id ] ) ) {
				continue;
			}

			$methods[ $id ] = $method;
		}

		return $methods;
	}

	/**
	 * One method.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $id Method id.
	 *
	 * @return PayoutMethod|null Null when no method has that id.
	 */
	public function get( string $id ): ?PayoutMethod {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Whether a method is registered.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $id Method id.
	 *
	 * @return bool
	 */
	public function has( string $id ): bool {
		return null !== $this->get( $id );
	}

	/**
	 * The methods affiliates can be paid by right now.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, PayoutMethod>
	 */
	public function enabled(): array {
		return array_filter(
			$this->all(),
			static fn( PayoutMethod $method ) => Payout::METHOD_MANUAL === $method->get_id() || $method->is_enabled()
		);
	}

	/**
	 * Whether a method is registered and switched on.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $id Method id.
	 *
	 * @return bool
	 */
	public function is_enabled( string $id ): bool {
		return isset( $this->enabled()[ $id ] );
	}

	/**
	 * Every method's label.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, string> Method id => translated label.
	 */
	public function get_labels(): array {
		return array_map( static fn( PayoutMethod $method ) => $method->get_label(), $this->all() );
	}

	/**
	 * A method's label, or the id itself when the method is not registered.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $id Method id.
	 *
	 * @return string
	 */
	public function get_label( string $id ): string {
		$method = $this->get( $id );

		return null === $method ? $id : $method->get_label();
	}

	/**
	 * The method an affiliate without a choice of their own is paid by.
	 *
	 * The `method` setting, as long as that method is switched on; Manual otherwise.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return PayoutMethod
	 */
	public function get_default(): PayoutMethod {
		$enabled = $this->enabled();
		$id      = (string) flyaffiliate_get_option( 'method', Payout::METHOD_MANUAL );

		return $enabled[ $id ] ?? $this->get_manual();
	}

	/**
	 * The method that pays an affiliate.
	 *
	 * The override (an admin's "Switch to Manual" on the preview), then the
	 * affiliate's own choice, then the default — each only when switched on.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Affiliate $affiliate The affiliate.
	 * @param string    $override  A method chosen for this payout only.
	 *
	 * @return PayoutMethod
	 */
	public function resolve_for( Affiliate $affiliate, string $override = '' ): PayoutMethod {
		$enabled = $this->enabled();

		foreach ( [ $override, (string) $affiliate->get( 'payout_method', '' ) ] as $id ) {
			if ( '' !== $id && isset( $enabled[ $id ] ) ) {
				return $enabled[ $id ];
			}
		}

		return $this->get_default();
	}

	/**
	 * The latest transfer of each payment, from the methods that sent them.
	 *
	 * One call per method. A method that throws reports nothing rather than
	 * breaking the screen that asked.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Payout[] $payouts Payments, any method.
	 *
	 * @return array<int, array{status: string, message: string, retryable: bool, reference: string}> Payment id => state, with any detail of the method's own.
	 */
	public function get_states( array $payouts ): array {
		$by_method = [];

		foreach ( $payouts as $payout ) {
			if ( $payout instanceof Payout && $payout->exists() ) {
				$by_method[ (string) $payout->get( 'method' ) ][ $payout->get_id() ] = $payout;
			}
		}

		$states = [];

		foreach ( $by_method as $id => $group ) {
			$method = $this->get( (string) $id );

			if ( null === $method || ! $method->is_automatic() ) {
				continue;
			}

			try {
				$reported = (array) $method->get_states( array_values( $group ) );
			} catch ( \Throwable $e ) {
				continue;
			}

			foreach ( $reported as $payout_id => $state ) {
				$state = $this->normalize_state( $state );

				if ( null !== $state && isset( $group[ (int) $payout_id ] ) ) {
					$states[ (int) $payout_id ] = $state;
				}
			}
		}

		return $states;
	}

	/**
	 * The latest transfer of one payment.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Payout $payout The payment.
	 *
	 * @return array{status: string, message: string, retryable: bool, reference: string}|null
	 */
	public function get_state( Payout $payout ): ?array {
		return $this->get_states( [ $payout ] )[ $payout->get_id() ] ?? null;
	}

	/**
	 * Why a payment is locked, or an empty string when it is not.
	 *
	 * Locked while its transfer is queued, processing or pending; once its
	 * method reports it paid; and while its method is not registered (other
	 * than Manual, which always is), since nothing then knows where the money
	 * is. A failed transfer, or none, leaves the payment free.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Payout     $payout The payment.
	 * @param array|null $states Payment id => state, from `get_states()`, when the caller has
	 *                           already loaded them; looked up otherwise.
	 *
	 * @return string One of the `LOCK_*` constants, or ''.
	 */
	public function get_lock( Payout $payout, ?array $states = null ): string {
		$reason = '';
		$method = $this->get( (string) $payout->get( 'method' ) );

		if ( null === $method ) {
			$reason = self::LOCK_UNAVAILABLE;
		} elseif ( $method->is_automatic() ) {
			$state = null === $states ? $this->get_state( $payout ) : ( $states[ $payout->get_id() ] ?? null );

			if ( null !== $state && in_array( $state['status'], self::IN_FLIGHT_STATES, true ) ) {
				$reason = self::LOCK_IN_FLIGHT;
			} elseif ( null !== $state && PayoutMethod::STATE_PAID === $state['status'] ) {
				$reason = self::LOCK_SETTLED;
			}
		}

		/**
		 * Filters whether a payment is locked against changes by hand.
		 *
		 * Return false to release a payment deliberately — say, one sent by a
		 * method whose plugin is gone for good, after checking the money with
		 * the service that sent it.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param bool   $locked Whether the payment is locked.
		 * @param Payout $payout The payment.
		 * @param string $reason `in_flight`, `settled`, `unavailable`, or '' when not locked.
		 */
		$locked = (bool) apply_filters( 'flyaffiliate_payout_locked', '' !== $reason, $payout, $reason );

		if ( ! $locked ) {
			return '';
		}

		return '' === $reason ? self::LOCK_IN_FLIGHT : $reason;
	}

	/**
	 * Whether a payment is locked.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Payout     $payout The payment.
	 * @param array|null $states Payment id => state, when the caller already has them.
	 *
	 * @return bool
	 */
	public function is_locked( Payout $payout, ?array $states = null ): bool {
		return '' !== $this->get_lock( $payout, $states );
	}

	/**
	 * What to tell an admin who tries to change a locked payment.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Payout $payout The payment.
	 * @param string $reason The lock, from `get_lock()`.
	 *
	 * @return string
	 */
	public function get_lock_message( Payout $payout, string $reason ): string {
		$label = $this->get_label( (string) $payout->get( 'method' ) );

		switch ( $reason ) {
			case self::LOCK_SETTLED:
				/* translators: %s: the payout method, e.g. "PayPal". */
				return sprintf( __( 'This payment was sent by %s, so it cannot be changed by hand.', 'flyaffiliate' ), $label );

			case self::LOCK_UNAVAILABLE:
				/* translators: %s: the payout method's id. */
				return sprintf( __( 'This payment was sent by %s, which is not available right now. It cannot change until that method is back.', 'flyaffiliate' ), $label );

			default:
				/* translators: %s: the payout method, e.g. "PayPal". */
				return sprintf( __( '%s is sending this payment. It can change once the transfer settles.', 'flyaffiliate' ), $label );
		}
	}

	/**
	 * Whether an unpaid payment can be sent again.
	 *
	 * Its method must be automatic and registered, nothing may be in flight,
	 * and the last transfer must have failed in a way worth retrying — or never
	 * have been made.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Payout     $payout The payment.
	 * @param array|null $states Payment id => state, when the caller already has them.
	 *
	 * @return bool
	 */
	public function can_retry( Payout $payout, ?array $states = null ): bool {
		if ( $payout->is_paid() ) {
			return false;
		}

		$method = $this->get( (string) $payout->get( 'method' ) );

		if ( null === $method || ! $method->is_automatic() ) {
			return false;
		}

		$state = null === $states ? $this->get_state( $payout ) : ( $states[ $payout->get_id() ] ?? null );

		if ( null === $state ) {
			return true;
		}

		return PayoutMethod::STATE_FAILED === $state['status'] && $state['retryable'];
	}

	/**
	 * The methods as the apps see them.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param bool $enabled_only Only the methods switched on (the affiliate dashboard).
	 *
	 * @return array<int, array{id: string, label: string, automatic: bool, enabled: bool, fields: array}>
	 */
	public function get_script_data( bool $enabled_only = false ): array {
		$enabled = $this->enabled();
		$data    = [];

		foreach ( $enabled_only ? $enabled : $this->all() as $id => $method ) {
			$data[] = [
				'id'        => $id,
				'label'     => $method->get_label(),
				'logo'      => esc_url_raw( $method->get_logo_url() ),
				'automatic' => $method->is_automatic(),
				'enabled'   => isset( $enabled[ $id ] ),
				'fields'    => array_values( $this->normalize_fields( $method->get_affiliate_fields() ) ),
			];
		}

		return $data;
	}

	/**
	 * A method's affiliate fields with every key the apps read.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $fields As returned by `get_affiliate_fields()`.
	 *
	 * @return array
	 */
	public function normalize_fields( array $fields ): array {
		$normalized = [];

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['id'] ) ) {
				continue;
			}

			$normalized[] = [
				'id'          => sanitize_key( (string) $field['id'] ),
				'type'        => in_array( $field['type'] ?? '', [ 'email', 'text', 'select' ], true ) ? $field['type'] : 'text',
				'label'       => (string) ( $field['label'] ?? $field['id'] ),
				'description' => (string) ( $field['description'] ?? '' ),
				'placeholder' => (string) ( $field['placeholder'] ?? '' ),
				'required'    => ! empty( $field['required'] ),
				'options'     => (array) ( $field['options'] ?? [] ),
			];
		}

		return $normalized;
	}

	/**
	 * The built-in Manual method.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return Manual
	 */
	protected function get_manual(): Manual {
		if ( null === $this->manual ) {
			$this->manual = new Manual();
		}

		return $this->manual;
	}

	/**
	 * A state as a method reported it, with every key, or null when it is not a state.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param mixed $state What the method returned for one payment.
	 *
	 * @return array{status: string, message: string, retryable: bool, reference: string, recipient: string}|null
	 */
	protected function normalize_state( $state ): ?array {
		if ( ! is_array( $state ) || ! in_array( $state['status'] ?? '', self::STATES, true ) ) {
			return null;
		}

		// A method's own details pass through as it wrote them.
		return [
			'status'    => (string) $state['status'],
			'message'   => (string) ( $state['message'] ?? '' ),
			'retryable' => ! empty( $state['retryable'] ),
			'reference' => (string) ( $state['reference'] ?? '' ),
			'recipient' => (string) ( $state['recipient'] ?? '' ),
		] + $state;
	}

	/**
	 * Where a payment goes: where its transfer sent the money, or, before
	 * anything was sent, where its payout method pays this affiliate (the
	 * PayPal email for PayPal, the payment email for Manual).
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Payout         $payout    The payment.
	 * @param Affiliate|null $affiliate Its affiliate, when known.
	 * @param array|null     $states    The payments' states, from `get_states()`, when already read.
	 *
	 * @return string An empty string when nobody can be named.
	 */
	public function get_payment_recipient( Payout $payout, ?Affiliate $affiliate, ?array $states = null ): string {
		$state = null === $states ? $this->get_state( $payout ) : ( $states[ $payout->get_id() ] ?? null );

		if ( '' !== ( $state['recipient'] ?? '' ) ) {
			return (string) $state['recipient'];
		}

		if ( null === $affiliate ) {
			return '';
		}

		$method = $this->get( (string) $payout->get( 'method' ) );

		return null === $method ? $affiliate->get_payment_email() : $method->get_recipient( $affiliate );
	}
}
