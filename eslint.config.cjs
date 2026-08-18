const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaultConfig,
	{
		// REST payload shapes deliberately mirror the PHP controllers'
		// snake_case keys (src/types/ is the single source of truth), and
		// TypeScript signatures already document every parameter.
		files: [ '**/*.ts', '**/*.tsx' ],
		rules: {
			camelcase: 'off',
			'jsdoc/require-param': 'off',
		},
	},
];
