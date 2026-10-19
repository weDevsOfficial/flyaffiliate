/**
 * Run a callback on an interval while a condition holds and the tab is visible.
 *
 * For screens that follow money on its way: a payout whose transfers have not
 * settled asks again every few seconds, and stops asking once they have, or
 * while nobody is looking.
 */
import { useEffect, useRef } from '@wordpress/element';

/**
 * @param {Function} callback Called on every tick, with the tick number (1, 2, …).
 * @param {number}   interval Milliseconds between ticks.
 * @param {boolean}  active   Whether to poll at all.
 */
export function usePolling(
	callback: ( tick: number ) => void,
	interval: number,
	active: boolean
): void {
	const saved = useRef( callback );

	saved.current = callback;

	useEffect( () => {
		if ( ! active ) {
			return;
		}

		let tick = 0;
		const timer = window.setInterval( () => {
			if ( document.hidden ) {
				return;
			}

			tick += 1;
			saved.current( tick );
		}, interval );

		return () => window.clearInterval( timer );
	}, [ interval, active ] );
}
