<?php
/**
 * Base class for payout methods.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Abstracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\PayoutMethod as PayoutMethodContract;
use FlyAffiliate\Models\Affiliate;
use FlyAffiliate\Models\Payout;
use WP_Error;

/**
 * Safe defaults for a payout method: paid by hand, always on, no details to
 * give, nothing to send and nothing to report.
 *
 * It also keeps an affiliate's details for the method — whatever
 * `get_affiliate_fields()` declares — in one user meta row per method on the
 * affiliate's user, sanitized by the field's type. A method that stores its
 * details elsewhere overrides `get_affiliate_details()` and
 * `save_affiliate_details()`.
 *
 * @since 1.0.0
 */
abstract class PayoutMethod implements PayoutMethodContract {

	/**
	 * The user meta key holding an affiliate's details, followed by the method id.
	 *
	 * @var string
	 */
	const DETAILS_META_PREFIX = '_flyaffiliate_payout_details_';

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_automatic(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_logo_url(): string {
		return '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_affiliate_fields(): array {
		return [];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @param Affiliate $affiliate The affiliate.
	 *
	 * @return array<string, string>
	 */
	public function get_affiliate_details( Affiliate $affiliate ): array {
		$fields = $this->get_affiliate_fields();

		if ( [] === $fields ) {
			return [];
		}

		$stored  = get_user_meta( (int) $affiliate->get( 'user_id' ), $this->get_details_meta_key(), true );
		$stored  = is_array( $stored ) ? $stored : [];
		$details = [];

		foreach ( $fields as $field ) {
			$details[ $field['id'] ] = isset( $stored[ $field['id'] ] ) ? (string) $stored[ $field['id'] ] : '';
		}

		return $details;
	}

	/**
	 * {@inheritDoc}
	 *
	 * A field left out of `$details` keeps its stored value. A required field
	 * that ends up empty refuses the whole save.
	 *
	 * @since 1.0.0
	 *
	 * @param Affiliate            $affiliate The affiliate.
	 * @param array<string, mixed> $details   Field id => value.
	 *
	 * @return true|WP_Error
	 */
	public function save_affiliate_details( Affiliate $affiliate, array $details ) {
		$fields = $this->get_affiliate_fields();

		if ( [] === $fields ) {
			return true;
		}

		$values = $this->get_affiliate_details( $affiliate );

		foreach ( $fields as $field ) {
			$id = $field['id'];

			if ( ! array_key_exists( $id, $details ) ) {
				continue;
			}

			$value = $this->sanitize_detail( $field, $details[ $id ] );

			if ( is_wp_error( $value ) ) {
				return $value;
			}

			$values[ $id ] = $value;
		}

		foreach ( $fields as $field ) {
			if ( ! empty( $field['required'] ) && '' === ( $values[ $field['id'] ] ?? '' ) ) {
				return new WP_Error(
					'flyaffiliate_payout_details_required',
					/* translators: 1: the field, e.g. "PayPal email"; 2: the payout method. */
					sprintf( __( 'Enter your %1$s to be paid by %2$s.', 'flyaffiliate' ), $field['label'], $this->get_label() ),
					[ 'status' => 400 ]
				);
			}
		}

		update_user_meta( (int) $affiliate->get( 'user_id' ), $this->get_details_meta_key(), $values );

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Every required detail must be there.
	 *
	 * @since 1.0.0
	 *
	 * @param Affiliate $affiliate The affiliate.
	 *
	 * @return true|WP_Error
	 */
	public function check_affiliate( Affiliate $affiliate ) {
		$details = $this->get_affiliate_details( $affiliate );

		foreach ( $this->get_affiliate_fields() as $field ) {
			if ( ! empty( $field['required'] ) && '' === ( $details[ $field['id'] ] ?? '' ) ) {
				return new WP_Error(
					'flyaffiliate_payout_details_missing',
					/* translators: %s: the missing detail, e.g. "PayPal email". */
					sprintf( __( '%s missing.', 'flyaffiliate' ), $field['label'] )
				);
			}
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * The first detail the affiliate gave, else their payment email.
	 *
	 * @since 1.0.0
	 *
	 * @param Affiliate $affiliate The affiliate.
	 *
	 * @return string
	 */
	public function get_recipient( Affiliate $affiliate ): string {
		if ( [] === $this->get_affiliate_fields() ) {
			return $affiliate->get_payment_email();
		}

		foreach ( $this->get_affiliate_details( $affiliate ) as $value ) {
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * None: the affiliate gives their own details.
	 *
	 * @since 1.0.0
	 *
	 * @param Affiliate $affiliate The affiliate.
	 *
	 * @return array
	 */
	public function get_connected_account( Affiliate $affiliate ): array {
		return [];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @param string   $batch_key The payout.
	 * @param Payout[] $payouts   The payments.
	 *
	 * @return void
	 */
	public function pay( string $batch_key, array $payouts ): void {}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @param Payout $payout The payment.
	 *
	 * @return true|WP_Error
	 */
	public function retry( Payout $payout ) {
		return new WP_Error(
			'flyaffiliate_payout_not_retryable',
			/* translators: %s: the payout method. */
			sprintf( __( '%s payments are paid by hand, so there is nothing to send again.', 'flyaffiliate' ), $this->get_label() ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @param Payout[] $payouts The payments.
	 *
	 * @return void
	 */
	public function refresh( array $payouts ): void {}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @param Payout[] $payouts The payments.
	 *
	 * @return array
	 */
	public function get_states( array $payouts ): array {
		return [];
	}

	/**
	 * The user meta key this method's details are kept under.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	protected function get_details_meta_key(): string {
		return self::DETAILS_META_PREFIX . $this->get_id();
	}

	/**
	 * Clean one detail by its field type.
	 *
	 * @since 1.0.0
	 *
	 * @param array $field The field definition.
	 * @param mixed $value  The value given.
	 *
	 * @return string|WP_Error
	 */
	protected function sanitize_detail( array $field, $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		switch ( $field['type'] ?? 'text' ) {
			case 'email':
				$email = sanitize_email( $value );

				if ( '' === $email || ! is_email( $email ) ) {
					return new WP_Error(
						'flyaffiliate_payout_details_invalid',
						/* translators: %s: the field, e.g. "PayPal email". */
						sprintf( __( 'Enter a valid %s.', 'flyaffiliate' ), $field['label'] ),
						[ 'status' => 400 ]
					);
				}

				return $email;

			case 'select':
				$options = (array) ( $field['options'] ?? [] );

				if ( ! array_key_exists( $value, $options ) ) {
					return new WP_Error(
						'flyaffiliate_payout_details_invalid',
						/* translators: %s: the field. */
						sprintf( __( 'Choose a %s from the list.', 'flyaffiliate' ), $field['label'] ),
						[ 'status' => 400 ]
					);
				}

				return $value;

			default:
				return sanitize_text_field( $value );
		}
	}
}
