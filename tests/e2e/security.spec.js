// The boundaries that must not move.

const { test, expect, request: apiRequest } = require( '@playwright/test' );
const { ability, callAbility, publish, rest, cleanup, b64, pathOf, LOW_PRIV } = require( './wp' );

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

	const call = ( name, input ) => callAbility( context, nonce, name, input );

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

test( 'the unfiltered_html gate is not fooled by attribute-separator tricks', async ( { baseURL } ) => {
	const { context, call } = await asAuthor( baseURL );

	// Every one of these executes in a browser. The HTML tokenizer starts an attribute
	// after `/` or a closing quote, and decodes entities before resolving a scheme.
	const payloads = [
		'<svg/onload=alert(1)>',
		'<img src="x"onerror=alert(1)>',
		'<body/onload=alert(1)>',
		'<a href="&#106;avascript:alert(1)">x</a>',
		'<meta http-equiv=refresh content="0;url=javascript:alert(1)">',
		'<iframe src="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=="></iframe>',
		'<object data="evil.swf"></object>',
		'<img src=x onerror=alert(1)>',
		'<div ONCLICK="go()">x</div>',
		'<a href="JaVaScRiPt:alert(1)">x</a>',
		'<img src="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=">',
	];

	for ( const payload of payloads ) {
		const result = await call( 'wp-artifacts/publish', {
			title: 'gate probe',
			content: `<!doctype html><html><body>${ payload }</body></html>`,
			status: 'publish',
		} );

		expect( result.code, `must be refused: ${ payload }` ).toBe( 'artifact_requires_unfiltered_html' );
	}

	// And a genuinely inert document still goes through.
	const clean = await call( 'wp-artifacts/publish', {
		title: 'inert',
		slug: 'inert-doc',
		status: 'publish',
		content: '<!doctype html><html><body><h1>Hi</h1><p><a href="/somewhere/">link</a></p><img src="data:image/png;base64,iVBORw0KGgo="></body></html>',
	} );
	expect( clean.id ).toBeGreaterThan( 0 );

	await context.dispose();
} );

test( 'a password-protected page does not leak its artifact', async ( { request, baseURL } ) => {
	const page = await rest( request, 'POST', '/wp-json/wp/v2/pages', {
		title: 'Members',
		slug: 'members',
		status: 'publish',
		password: 'hunter2',
		content: 'SECRET MEMBER CONTENT',
	} );

	const secret = '<!doctype html><p>ARTIFACT BEHIND THE PASSWORD</p>';
	await publish( request, {
		title: 'members immersive',
		slug: 'members-immersive',
		status: 'publish',
		content: secret,
		parent_id: page.id,
		deliver_for_parent: true,
	} );

	const visitor = await apiRequest.newContext( { baseURL } );
	const response = await visitor.get( pathOf( page.link ) );
	const body = await response.text();

	expect( body ).not.toContain( 'ARTIFACT BEHIND THE PASSWORD' );
	expect( body ).not.toContain( 'SECRET MEMBER CONTENT' );
	expect( body ).toContain( 'post_password' );

	await visitor.dispose();
} );

test( 'an artifact cannot be attached to a post the author cannot edit', async ( { request, baseURL } ) => {
	const page = await rest( request, 'POST', '/wp-json/wp/v2/pages', {
		title: 'Board minutes',
		slug: 'board-minutes',
		status: 'publish',
		content: 'ORIGINAL BOARD PAGE',
	} );

	const { context, call } = await asAuthor( baseURL );

	const result = await call( 'wp-artifacts/publish', {
		title: 'takeover',
		status: 'publish',
		content: '<!doctype html><p>NOT THE BOARD PAGE</p>',
		parent_id: page.id,
	} );

	expect( result.code ).toBe( 'artifact_forbidden' );

	const visitor = await apiRequest.newContext( { baseURL } );
	expect( await ( await visitor.get( `${ pathOf( page.link ) }?artifact_preview=1` ) ).text() )
		.not.toContain( 'NOT THE BOARD PAGE' );

	await visitor.dispose();
	await context.dispose();
} );

test( 'listing does not expose other users unpublished artifacts', async ( { request, baseURL } ) => {
	await publish( request, {
		title: 'EMBARGOED ADMIN DRAFT',
		slug: 'embargoed-admin-draft',
		status: 'draft',
		content: '<!doctype html><p>x</p>',
	} );

	const { context, call } = await asAuthor( baseURL );

	const own = await call( 'wp-artifacts/publish', {
		title: 'my own draft',
		slug: 'my-own-draft',
		status: 'draft',
		content: '<!doctype html><p>mine</p>',
	} );
	expect( own.id ).toBeGreaterThan( 0 );

	const listed = await call( 'wp-artifacts/list', { status: 'any', per_page: 100 } );
	const titles = ( listed.items || [] ).map( ( item ) => item.title );

	expect( titles ).not.toContain( 'EMBARGOED ADMIN DRAFT' );
	expect( titles ).toContain( 'my own draft' );

	await context.dispose();
} );

test( 'a 404 does not tell an anonymous visitor which artifacts exist', async ( { request, baseURL } ) => {
	await publish( request, {
		title: 'hidden',
		slug: 'hidden-draft',
		status: 'draft',
		content: '<!doctype html><p>hidden</p>',
	} );

	const visitor = await apiRequest.newContext( { baseURL } );

	const hidden = await visitor.get( '/a/hidden-draft/x.css' );
	const missing = await visitor.get( '/a/no-such-artifact-at-all/x.css' );

	expect( hidden.status() ).toBe( 404 );
	expect( missing.status() ).toBe( 404 );

	// The reason header is what told them apart.
	expect( hidden.headers()[ 'x-artifacts-reason' ] ).toBe( missing.headers()[ 'x-artifacts-reason' ] );

	await visitor.dispose();
} );
