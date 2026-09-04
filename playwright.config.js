// Playwright drives a WordPress Playground instance.
// Start one first: `npm run test:e2e:server`.

const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests/e2e',
	timeout: 60000,
	expect: { timeout: 10000 },
	fullyParallel: false,
	workers: 1,
	reporter: [ [ 'list' ] ],
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://127.0.0.1:9411',
		ignoreHTTPSErrors: true,
		trace: 'retain-on-failure',
	},
} );
