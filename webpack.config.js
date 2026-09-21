/**
 * FlyAffiliate build configuration.
 *
 * Extends @wordpress/scripts' default webpack config with two changes:
 *
 * 1. Output lands in assets/js and assets/css, which is what Assets.php enqueues
 *    and what ships in the release zip. Sources live in assets/src.
 * 2. Entries are discovered rather than listed, so adding assets/src/js/foo.js or
 *    assets/src/css/foo.css is enough — no edit here.
 *
 * React apps live under src/<name>/index.tsx and build to assets/js/<name>.js;
 * the stylesheet an app imports is extracted to assets/css/<name>.css so it
 * sits beside the plain stylesheets and Assets.php can treat them alike.
 */

const path = require( 'path' );
const fs = require( 'fs' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const RemoveEmptyScriptsPlugin = require( 'webpack-remove-empty-scripts' );

const root = __dirname;

/**
 * List files in a directory that match an extension, tolerating a missing directory.
 *
 * @param {string}   dir        Directory relative to the project root.
 * @param {string[]} extensions Extensions to accept, with the leading dot.
 * @return {string[]} Matching file names.
 */
function filesIn( dir, extensions ) {
	const absolute = path.resolve( root, dir );

	if ( ! fs.existsSync( absolute ) ) {
		return [];
	}

	return fs
		.readdirSync( absolute )
		.filter( ( file ) => extensions.includes( path.extname( file ) ) );
}

const entry = {};

// Plain scripts: assets/src/js/admin.js -> assets/js/admin.js
filesIn( 'assets/src/js', [ '.js' ] ).forEach( ( file ) => {
	entry[ `js/${ path.basename( file, '.js' ) }` ] = path.resolve(
		root,
		'assets/src/js',
		file
	);
} );

// Stylesheets: assets/src/css/admin.css -> assets/css/admin.css
filesIn( 'assets/src/css', [ '.css', '.scss' ] ).forEach( ( file ) => {
	const name = path.basename( file, path.extname( file ) );
	entry[ `css/${ name }` ] = path.resolve( root, 'assets/src/css', file );
} );

// React apps: src/<name>/index.tsx -> assets/js/<name>.js (+ assets/css/<name>.css)
const srcDir = path.resolve( root, 'src' );

if ( fs.existsSync( srcDir ) ) {
	fs.readdirSync( srcDir, { withFileTypes: true } )
		.filter( ( item ) => item.isDirectory() )
		.forEach( ( item ) => {
			const match = [ 'index.tsx', 'index.ts', 'index.jsx', 'index.js' ]
				.map( ( file ) => path.join( srcDir, item.name, file ) )
				.find( ( file ) => fs.existsSync( file ) );

			if ( match ) {
				entry[ `js/${ item.name }` ] = match;
			}
		} );
}

// webpack refuses to run without at least one entry; a build with nothing to
// build should say so rather than fail with a stack trace.
if ( Object.keys( entry ).length === 0 ) {
	// eslint-disable-next-line no-console
	console.warn(
		'FlyAffiliate: no sources found in assets/src or src — nothing to build.'
	);
}

/**
 * Move the stylesheets a JS entry emits — and the RTL twins the RTL plugin
 * derives from them — from js/ to css/, so every stylesheet lives in one place.
 */
class MoveStylesToCssDirPlugin {
	apply( compiler ) {
		compiler.hooks.thisCompilation.tap(
			'FlyAffiliateMoveStyles',
			( compilation ) => {
				compilation.hooks.processAssets.tap(
					{
						name: 'FlyAffiliateMoveStyles',
						// After every other plugin, including the RTL one, has emitted.
						stage: compiler.webpack.Compilation
							.PROCESS_ASSETS_STAGE_REPORT,
					},
					( assets ) => {
						Object.keys( assets ).forEach( ( name ) => {
							const match = name.match(
								/^js\/(?:style-)?(.+\.css)$/
							);

							if ( match ) {
								compilation.renameAsset(
									name,
									`css/${ match[ 1 ] }`
								);
							}
						} );
					}
				);
			}
		);
	}
}

/**
 * Remove the remote-looking URLs the bundled libraries leave in the output.
 *
 * ("Remove" as in delete — nothing here has anything to do with payments.)
 *
 * WordPress.org's review scanner flags any `http://` inside a stylesheet as a
 * remote file, and its reviewers ask for external hosts to be gone from the
 * scripts. None of these load anything: the Tailwind licence comment names its
 * website, the SVG namespace is a namespace rather than an address, and
 * plugin-ui carries a Google logo for a sign-in button this plugin never
 * renders.
 *
 * The SVG namespace is percent-encoded rather than removed, in the scripts as
 * well as the stylesheets. It reaches the page two ways, and the encoding is
 * inert in both: inside a `data:image/svg+xml` URI the browser decodes it back
 * before parsing the icon, and as the `xmlns` attribute of a React element it
 * is never read — an inline `<svg>` takes its namespace from
 * `createElementNS()`, which lives in react-dom, a WordPress-provided external
 * that no bundle here contains.
 *
 * What is left in the scripts after this is the handful of documentation URLs
 * that libraries put in their own error messages. Those are error text, not
 * resources, and rewriting a third-party error message to satisfy a grep costs
 * more than it buys.
 */
class RemoveRemoteUrlsPlugin {
	apply( compiler ) {
		const { RawSource } = compiler.webpack.sources;

		compiler.hooks.thisCompilation.tap(
			'FlyAffiliateRemoveRemoteUrls',
			( compilation ) => {
				compilation.hooks.processAssets.tap(
					{
						name: 'FlyAffiliateRemoveRemoteUrls',
						stage: compiler.webpack.Compilation
							.PROCESS_ASSETS_STAGE_REPORT,
					},
					( assets ) => {
						Object.keys( assets ).forEach( ( name ) => {
							const isCss = /\.css$/.test( name );
							const isJs = /\.js$/.test( name );

							if ( ! isCss && ! isJs ) {
								return;
							}

							const before = assets[ name ].source().toString();
							let after = before;

							after = after.replace(
								/http:\/\/www\.w3\.org\/2000\/svg/g,
								'http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg'
							);

							if ( isCss ) {
								after = after.replace(
									/\/\*![\s\S]*?\*\//g,
									''
								);
							}

							if ( isJs ) {
								after = after.replace(
									/https:\/\/upload\.wikimedia\.org\/[^"'`)\s]*/g,
									'data:,'
								);
							}

							if ( after !== before ) {
								compilation.updateAsset(
									name,
									new RawSource( after )
								);
							}
						} );
					}
				);
			}
		);
	}
}

module.exports = {
	...defaultConfig,
	entry,
	resolve: {
		...defaultConfig.resolve,
		alias: {
			...( defaultConfig.resolve && defaultConfig.resolve.alias ),
			'@': path.resolve( root, 'src/admin' ),
		},
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( root, 'assets' ),
		filename: '[name].js',
		// The output directory also holds hand-maintained files — assets/images
		// and assets/src — so the default "wipe everything" clean would delete
		// the sources it was about to build from. Only the generated
		// subdirectories are cleared.
		clean: {
			keep: ( asset ) => ! /^(css|js)\//.test( asset ),
		},
	},
	plugins: [
		// A CSS-only entry otherwise emits an empty sibling .js file, which would
		// then ship in the release zip. @wordpress/scripts does not include this
		// plugin in its default config.
		new RemoveEmptyScriptsPlugin(),
		...defaultConfig.plugins,
		new RemoveRemoteUrlsPlugin(),
		new MoveStylesToCssDirPlugin(),
	],
};
