/**
 * SVG files imported from the admin bundle: @wordpress/scripts inlines them
 * through url-loader, so the default export is a data URL for an `<img>`.
 */
declare module '*.svg' {
	const url: string;
	export default url;
}
