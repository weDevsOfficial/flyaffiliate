const path = require( 'path' );
const fs = require( 'fs' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );
const RemoveEmptyScriptsPlugin = require( 'webpack-remove-empty-scripts' );

const root = __dirname;

// The page inside Dokan's vendor dashboard is built on its own, against Dokan's shared components.
const DOKAN_ENTRY = 'dokan-vendor';

// What Dokan shares with a script on its vendor dashboard: the import, its global, its script handle.
const DOKAN_EXTERNALS = {
	'@dokan/components': {
		external: [ 'dokan', 'components' ],
		handle: 'dokan-react-components',
	},
	'@dokan/utilities': {
		external: [ 'dokan', 'utilities' ],
		handle: 'dokan-utilities',
	},
	'@dokan/hooks': {
		external: [ 'dokan', 'reactHooks' ],
		handle: 'dokan-hooks',
	},
	'@wedevs/plugin-ui': {
		external: [ 'dokan', 'pluginUI' ],
		handle: 'dokan-plugin-ui',
	},
};

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
		.filter( ( item ) => item.isDirectory() && item.name !== DOKAN_ENTRY )
		.forEach( ( item ) => {
			const match = [ 'index.tsx', 'index.ts', 'index.jsx', 'index.js' ]
				.map( ( file ) => path.join( srcDir, item.name, file ) )
				.find( ( file ) => fs.existsSync( file ) );

			if ( match ) {
				entry[ `js/${ item.name }` ] = match;
			}
		} );
}

// webpack needs at least one entry; say so instead of failing with a stack trace.
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
 * Strip the remote-looking strings that bundled libraries leave in the built
 * CSS and JS. None of them loads anything, but WordPress.org's review scanner
 * reports each as "Calling files remotely".
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

							// SVG namespace attributes: react-dom sets the namespace itself via createElementNS().
							if ( isJs ) {
								after = after
									.replace(
										/;background-image:url\(\\'data:image\/svg\+xml;charset=utf-8,<svg xmlns="http:\/\/www\.w3\.org\/2000\/svg"[^)]*\)/g,
										''
									)
									.replace(
										/xmlns:"http:\/\/www\.w3\.org\/2000\/svg",/g,
										''
									)
									.replace(
										/,xmlns:"http:\/\/www\.w3\.org\/2000\/svg"/g,
										''
									)
									.replace(
										/ xmlns="http:\/\/www\.w3\.org\/2000\/svg"/g,
										''
									);
							}

							// Whatever is left sits in a data: URI; percent-encoded it is inert and decodes the same.
							after = after.replace(
								/http:\/\/www\.w3\.org\/2000\/svg/g,
								'http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg'
							);

							if ( isCss ) {
								after = after
									// Tailwind's licence banner names its website.
									.replace( /\/\*![\s\S]*?\*\//g, '' )
									// wp-components' colour-picker checkerboard: the one data: URI carrying the namespace.
									.replace(
										/\.components-circular-option-picker__option-wrapper:before\{[^}]*\}/g,
										''
									)
									// Its doubled selector spells "placeholder.com"; wp-components' Placeholder is never rendered here.
									.replace(
										/\.components-placeholder/g,
										'.section-content'
									);
							}

							// plugin-ui's Google logo (never rendered) and library doc URLs in error text.
							if ( isJs ) {
								after = after
									.replace(
										/https:\/\/upload\.wikimedia\.org\/[^"'`)\s]*/g,
										'data:,'
									)
									.replace(
										/https?:\/\/(redux\.js\.org|radix-ui\.com|base-ui\.com|github\.com|fb\.me)\//g,
										'$1/'
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

const resolve = {
	...defaultConfig.resolve,
	alias: {
		...( defaultConfig.resolve && defaultConfig.resolve.alias ),
		'@': path.resolve( root, 'src/admin' ),
	},
};

const dokanOutput = new RegExp( `^js/${ DOKAN_ENTRY }\\.` );

const apps = {
	...defaultConfig,
	entry,
	resolve,
	output: {
		...defaultConfig.output,
		path: path.resolve( root, 'assets' ),
		filename: '[name].js',
		// assets/ also holds the sources (assets/src); only the built css/ and js/ are cleared, and the Dokan build's files are its own.
		clean: {
			keep: ( asset ) =>
				! /^(css|js)\//.test( asset ) || dokanOutput.test( asset ),
		},
	},
	plugins: [
		// A CSS-only entry would otherwise emit an empty .js file that ships in the zip.
		new RemoveEmptyScriptsPlugin(),
		...defaultConfig.plugins,
		new RemoveRemoteUrlsPlugin(),
		new MoveStylesToCssDirPlugin(),
	],
};

const dokanSource = path.resolve( root, 'src', DOKAN_ENTRY, 'index.tsx' );

// The vendor dashboard page bundles no component library of its own: Dokan's is already on the page.
const dokan = {
	...defaultConfig,
	name: DOKAN_ENTRY,
	entry: { [ `js/${ DOKAN_ENTRY }` ]: dokanSource },
	resolve,
	output: {
		...defaultConfig.output,
		path: path.resolve( root, 'assets' ),
		filename: '[name].js',
		clean: false,
	},
	plugins: [
		...defaultConfig.plugins.filter(
			( plugin ) =>
				plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
		new DependencyExtractionWebpackPlugin( {
			requestToExternal: ( request ) =>
				DOKAN_EXTERNALS[ request ]?.external,
			requestToHandle: ( request ) => DOKAN_EXTERNALS[ request ]?.handle,
		} ),
		new RemoveRemoteUrlsPlugin(),
		new MoveStylesToCssDirPlugin(),
	],
};

module.exports = fs.existsSync( dokanSource ) ? [ apps, dokan ] : apps;
