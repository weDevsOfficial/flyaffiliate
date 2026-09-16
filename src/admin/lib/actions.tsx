/**
 * List actions labelled the Dokan way: the icon sits beside the text in the
 * bulk toolbar and the row menu alike. DataViews only draws `icon` on narrow
 * screens, so the label itself carries it.
 */
import type { DataViewAction } from '@wedevs/plugin-ui';

export function withIconLabels< Item >(
	actions: DataViewAction< Item >[]
): DataViewAction< Item >[] {
	return actions.map( ( action ) => {
		if ( ! action.icon || typeof action.label !== 'string' ) {
			return action;
		}

		const text = action.label;
		const label = () => (
			<span className="flyaffiliate-action-label">
				{ action.icon }
				{ text }
			</span>
		);

		return {
			...action,
			// The confirm dialog falls back to the label; keep it plain text.
			confirmTitle: action.confirmTitle ?? text,
			confirmButtonLabel: action.confirmButtonLabel ?? text,
			// Typed as a string, but DataViews renders whatever it returns.
			label: label as unknown as ( items: Item[] ) => string,
		};
	} );
}
