<?php
/**
 * Plugin Name: FlyAffiliate e2e — PayPal sandbox stand-in
 * Description: Written by tests/pw/specs/pro-paypal.spec.ts and removed after it. Answers FlyAffiliate Pro's requests to PayPal's sandbox API so payouts run end to end without the network. Never install it on a real site.
 *
 * What it answers is driven by one option, `pw_paypal_mock`:
 *   token        ok | invalid     the OAuth token request
 *   send         ok | reject | down   POST /v1/payments/payouts
 *   item_status  SUCCESS | FAILED | PENDING | UNCLAIMED | …   what a payout batch / item reports
 *   verify       SUCCESS | FAILURE   webhook signature verification
 * Every request is appended to the option `pw_paypal_log` (method, path, body).
 *
 * @package FlyAffiliate\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		if ( 0 !== strpos( (string) $url, 'https://api-m.sandbox.paypal.com' ) ) {
			return $preempt;
		}

		$mode   = wp_parse_args(
			(array) get_option( 'pw_paypal_mock', [] ), [
				'token' => 'ok',
				'send' => 'ok',
				'item_status' => 'SUCCESS',
				'verify' => 'FAILURE',
			]
		);
		$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
		$body   = $args['body'] ?? '';
		$json   = is_string( $body ) ? json_decode( $body, true ) : null;

		$log   = (array) get_option( 'pw_paypal_log', [] );
		$log[] = [
			'method' => $method,
			'path' => $path,
			'body' => is_array( $json ) ? $json : $body,
			'headers' => array_diff_key( (array) ( $args['headers'] ?? [] ), [ 'Authorization' => 1 ] ),
		];
		update_option( 'pw_paypal_log', $log, false );

		$reply = static function ( int $code, $data ) {
			return [
				'headers'  => [ 'paypal-debug-id' => 'pw-debug' ],
				'body'     => null === $data ? '' : wp_json_encode( $data ),
				'response' => [
					'code' => $code,
					'message' => '',
				],
				'cookies'  => [],
				'filename' => null,
			];
		};

		// The items of a batch Pro sent, as PayPal would report them back.
		$items_of = static function ( string $batch_id ) use ( $mode ): array {
			$batches = (array) get_option( 'pw_paypal_batches', [] );
			$items   = [];
			foreach ( (array) ( $batches[ $batch_id ]['items'] ?? [] ) as $item ) {
				$failed  = in_array( $mode['item_status'], [ 'FAILED', 'RETURNED', 'DENIED', 'BLOCKED' ], true );
				$items[] = [
					'payout_item_id'     => 'PWITEM-' . $item['sender_item_id'],
					'payout_batch_id'    => $batch_id,
					'transaction_status' => $mode['item_status'],
					'transaction_id'     => 'SUCCESS' === $mode['item_status'] ? 'PWTXN-' . $item['sender_item_id'] : '',
					'payout_item'        => $item,
					'errors'             => $failed ? [
						'name' => 'RECEIVER_UNREGISTERED',
						'message' => 'Receiver is unregistered.',
					] : [],
				];
			}
			return $items;
		};

		if ( '/v1/oauth2/token' === $path ) {
			return 'ok' === $mode['token']
				? $reply(
					200, [
						'access_token' => 'pw-token',
						'scope' => 'https://uri.paypal.com/services/payments/payouts',
						'app_id' => 'APP-PW',
						'expires_in' => 32400,
					]
				)
				: $reply(
					401, [
						'error' => 'invalid_client',
						'error_description' => 'Client Authentication failed',
					]
				);
		}

		if ( 0 === strpos( $path, '/v1/identity/oauth2/userinfo' ) ) {
			return $reply(
				200, [
					'payer_id' => 'PWPAYER1',
					'name' => 'PW Sandbox Business',
					'emails' => [
						[
							'value' => 'pw-business@example.test',
							'primary' => true,
						],
					],
				]
			);
		}

		if ( 0 === strpos( $path, '/v1/notifications/webhooks' ) ) {
			return 'GET' === $method ? $reply( 200, [ 'webhooks' => [] ] ) : ( 'POST' === $method ? $reply( 201, [ 'id' => 'WH-PW' ] ) : $reply( 204, null ) );
		}

		if ( '/v1/notifications/verify-webhook-signature' === $path ) {
			return $reply( 200, [ 'verification_status' => $mode['verify'] ] );
		}

		if ( '/v1/payments/payouts' === $path && 'POST' === $method ) {
			if ( 'down' === $mode['send'] ) {
				return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
			}
			if ( 'reject' === $mode['send'] ) {
				return $reply(
					422, [
						'name' => 'INSUFFICIENT_FUNDS',
						'message' => 'Sender does not have sufficient funds.',
						'debug_id' => 'pw-debug',
					]
				);
			}
			$batches = (array) get_option( 'pw_paypal_batches', [] );
			$id      = 'PWBATCH-' . substr( md5( (string) ( $json['sender_batch_header']['sender_batch_id'] ?? '' ) ), 0, 10 );
			$batches[ $id ] = [ 'items' => (array) ( $json['items'] ?? [] ) ];
			update_option( 'pw_paypal_batches', $batches, false );
			return $reply(
				201, [
					'batch_header' => [
						'payout_batch_id' => $id,
						'batch_status' => 'PENDING',
					],
				]
			);
		}

		if ( preg_match( '#^/v1/payments/payouts/([^/?]+)#', $path, $m ) ) {
			if ( 'FLYAFFILIATEPROBE' === $m[1] ) {
				return $reply(
					404, [
						'name' => 'RESOURCE_NOT_FOUND',
						'message' => 'Not found.',
					]
				);
			}
			$items = $items_of( $m[1] );
			return $reply(
				200, [
					'batch_header' => [
						'payout_batch_id' => $m[1],
						'batch_status' => 'SUCCESS' === $mode['item_status'] ? 'SUCCESS' : 'PROCESSING',
					],
					'items' => $items,
					'total_pages' => 1,
				]
			);
		}

		if ( preg_match( '#^/v1/payments/payouts-item/PWITEM-(.+)$#', $path, $m ) ) {
			foreach ( (array) get_option( 'pw_paypal_batches', [] ) as $batch_id => $batch ) {
				foreach ( $items_of( $batch_id ) as $item ) {
					if ( 'PWITEM-' . $m[1] === $item['payout_item_id'] ) {
						return $reply( 200, $item );
					}
				}
			}
			return $reply(
				404, [
					'name' => 'RESOURCE_NOT_FOUND',
					'message' => 'Not found.',
				]
			);
		}

		return $reply(
			404, [
				'name' => 'RESOURCE_NOT_FOUND',
				'message' => 'The PW stand-in does not answer ' . $method . ' ' . $path,
			]
		);
	},
	10,
	3
);
