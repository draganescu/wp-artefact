// The boundaries that must not move.

const { test, expect, request: apiRequest } = require( '@playwright/test' );
const { ability, publish, cleanup, b64, pathOf, LOW_PRIV } = require( './wp' );

const DOC = '<!doctype html><html><body><p>x</p></body></html>';
const PHP = '<?php echo "PWNED"; ?>';

// A context logged in as the author, who has publish_artifacts but no unfiltered_html.
async function asAuthor( baseURL ) {
	const context = await apiRequest.newContext( { baseURL } );

	await context.get( '/wp-login.php' );
	await context.post( '/wp-login.php', {
		form: {
			log: LOW_PRIV.user,
			pwd: LOW_PRIV.password,
			'wp-submit': 'Log In',
			redirect_to: '/wp-admin/',
			testcookie: '1',
		},
		maxRedirects: 5,
	} );

	const nonce = ( await ( await context.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim();

	const call = async ( name, input ) => {
		const response = await context.post( `/wp-json/wp-abilities/v1/abilities/${ name }/run`, {
			headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
			data: { input },
		} );

		return response.json();
	};

	return { context, call };
}

test.afterAll( async ( { request } ) => cleanup( request ) );

test( 'a user without unfiltered_html cannot ship anything executable', async ( { baseURL } ) => {
	const { context, call } = await asAuthor( baseURL );

	const script = await call( 'wp-artifacts/publish', {
		title: 'inline script',
		content: '<!doctype html><html><body><script>alert(1)</script></body></html>',
		status: 'publish',
	} );
	expect( script.code ).toBe( 'artifact_requires_unfiltered_html' );

	const js = await call( 'wp-artifacts/publish', {
		title: 'js asset',
		content: DOC,
		status: 'publish',
		files: [ { path: 'a.js', data_base64: b64( 'x' ) } ],
	} );
	expect( js.code ).toBe( 'artifact_requires_unfiltered_html' );

	await context.dispose();
} );

test( 'server-executable file types are refused outright', async ( { baseURL } ) => {
	const { context, call } = await asAuthor( baseURL );

	// The extension is the thing that decides, so a friendly-looking MIME buys nothing.
	for ( const path of [ 'shell.phtml', 'shell.php', 'shell.phar', 'x.cgi', 'x.pl' ] ) {
		const result = await call( 'wp-artifacts/publish', {
			title: 'probe',
			content: DOC,
			status: 'publish',
			files: [ { path, mime: 'text/plain', data_base64: b64( PHP ) } ],
		} );

		expect( result.code, `${ path } must be refused` ).toBe( 'artifact_invalid_path' );
	}

	await context.dispose();
} );

test( 'a declared MIME type cannot disagree with the extension', async ( { request } ) => {
	const result = await ability( request, 'wp-artifacts/publish', {
		title: 'mismatched type',
		content: DOC,
		status: 'publish',
		files: [ { path: 'a.css', mime: 'text/html', data_base64: b64( 'body{}' ) } ],
	} );

	expect( result.code ).toBe( 'artifact_mime_not_allowed' );
} );

test( 'the assets of a private artifact are not readable from the uploads directory', async ( { request, baseURL } ) => {
	const artifact = await publish( request, {
		title: 'client preview',
		slug: 'client-preview',
		status: 'private',
		content: '<!doctype html><p>confidential</p>',
		files: [ { path: 'secret.css', data_base64: b64( 'body{content:"CONFIDENTIAL"}' ) } ],
	} );

	const visitor = await apiRequest.newContext( { baseURL } );

	// Through the router, where the status is checked.
	expect( ( await visitor.get( pathOf( artifact.url ) ) ).status() ).toBe( 404 );
	expect( ( await visitor.get( `${ pathOf( artifact.url ) }secret.css` ) ).status() ).toBe( 404 );

	// And directly, where the status would not be checked. The old layout put this at
	// a guessable path built from two small integers.
	const guessable = `/wp-content/uploads/artifacts/${ artifact.id }/${ artifact.revision_id }/secret.css`;
	expect( await ( await visitor.get( guessable ) ).text() ).not.toContain( 'CONFIDENTIAL' );

	const listing = await visitor.get( '/wp-content/uploads/artifacts/' );
	expect( await listing.text() ).not.toContain( 'CONFIDENTIAL' );
	expect( await listing.text() ).not.toContain( 'secret.css' );

	await visitor.dispose();
} );

test( 'the manifest, not the filesystem, decides what is served', async ( { request } ) => {
	const artifact = await publish( request, {
		title: 'manifest authority',
		slug: 'manifest-authority',
		status: 'publish',
		content: DOC,
		files: [ { path: 'a.css', data_base64: b64( 'a{}' ) } ],
	} );

	const base = pathOf( artifact.url );

	expect( ( await request.get( `${ base }a.css` ) ).status() ).toBe( 200 );
	expect( ( await request.get( `${ base }b.css` ) ).status() ).toBe( 404 );

	// An encoded separator keeps the traversal inside the artifact route, so the
	// router is what has to refuse it.
	expect( ( await request.get( `${ base }css%2f..%2fa.css` ) ).status() ).toBe( 404 );
} );
