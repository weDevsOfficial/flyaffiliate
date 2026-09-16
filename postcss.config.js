/**
 * PostCSS: Tailwind 4 for the admin app, autoprefixer for everything.
 *
 * Tailwind only touches files that import it (each app's tailwind.css). The
 * SCSS stylesheets reach PostCSS already compiled by Sass and contain no
 * Tailwind directives, so they pass through untouched.
 */
module.exports = {
	plugins: {
		'@tailwindcss/postcss': {},
		autoprefixer: {},
	},
};
