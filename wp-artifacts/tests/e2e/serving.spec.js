// Serving: the bytes, the headers, the 404s and the redirects.

const { test, expect, request: apiRequest } = require( '@playwright/test' );
const crypto = require( 'crypto' );
const { ability, publish, update, cleanup, b64, pathOf } = require( './wp' );

const DOC = "<!doctype html><html><body><h1>Hi</h1><script>document.title='x'</script></body></html>";
const PNG_B64 =
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

const sha256 = ( value ) => crypto.createHash( 'sha256' ).update( value ).digest( 'hex' );

test.afterAll( async ( { request } ) => cleanup( request ) );

test( 'a published artifact is served byte-identical, with nothing of WordPress in it', async ( { request } ) => {
	const artifact = await publish( request, {
		title: 'Hello',
		slug: 'hello',
		status: 'publish',
		content: DOC,
	} );
	expect( artifact.id ).toBeGreaterThan( 0 );

	const response = await request.get( artifact.url );
	const body = await response.text();

	expect( response.status() ).toBe( 200 );
	expect( sha256( body ) ).toBe( sha256( DOC ) );

	expect( body ).not.toContain( 'wp-emoji' );
	expect( body ).not.toContain( 'admin-bar' );
	expect( body ).not.toContain( 'wp-includes' );

	const headers = response.headers();
	expect( headers[ 'content-type' ] ).toContain( 'text/html' );
	expect( headers[ 'x-content-type-options' ] ).toBe( 'nosniff' );
	expect( headers[ 'referrer-policy' ] ).toBe( 'strict-origin-when-cross-origin' );
	expect( headers[ 'x-robots-tag' ] ).toContain( 'noindex' );
	expect( headers[ 'content-security-policy' ] ).toContain( "frame-ancestors 'self'" );
	expect( headers[ 'x-artifacts' ] ).toBe( '1' );
	expect( headers[ 'set-cookie' ] ).toBeUndefined();
	expect( headers.etag ).toMatch( /^"rev-/ );

	const conditional = await request.get( artifact.url, {
		headers: { 'If-None-Match': headers.etag },
	} );
	expect( conditional.status() ).toBe( 304 );
} );

test( 'bundle assets are served from the manifest and nothing else', async ( { request } ) => {
	const css = 'body{color:red}';
	const artifact = await publish( request, {
		title: 'Bundle',
		slug: 'bundle',
		status: 'publish',
		content: '<!doctype html><html><head><link rel=stylesheet href=css/a.css></head><body><img src=img/x.png></body></html>',
		files: [
			{ path: 'css/a.css', data_base64: b64( css ) },
			{ path: 'img/x.png', data_base64: PNG_B64 },
		],
	} );

	const base = pathOf( artifact.url );

	const style = await request.get( `${ base }css/a.css` );
	expect( style.status() ).toBe( 200 );
	expect( await style.text() ).toBe( css );
	expect( style.headers()[ 'content-type' ] ).toContain( 'text/css' );
	expect( style.headers()[ 'cache-control' ] ).toContain( 'immutable' );

	const image = await request.get( `${ base }img/x.png` );
	expect( image.status() ).toBe( 200 );
	expect( image.headers()[ 'content-type' ] ).toContain( 'image/png' );

	// The manifest is the source of truth: an unlisted path is a 404.
	const unlisted = await request.get( `${ base }nope.css` );
	expect( unlisted.status() ).toBe( 404 );
	expect( unlisted.headers()[ 'x-artifacts' ] ).toBe( '1' );
	expect( unlisted.headers()[ 'x-artifacts-reason' ] ).toBe( 'not_in_manifest' );

	// Traversal that stays inside the artifact route has to be refused by the router.
	const traversal = await request.get( `${ base }css/../../wp-config.php` );
	expect( traversal.status() ).toBe( 404 );

	const encoded = await request.get( `${ base }css%2f..%2fa.css` );
	expect( encoded.status() ).toBe( 404 );
} );

test( 'revision-pinned asset URLs keep serving the bytes of that revision', async ( { request } ) => {
	const artifact = await publish( request, {
		title: 'Pinned',
		slug: 'pinned',
		status: 'publish',
		content: DOC,
		files: [ { path: 'a.css', data_base64: b64( 'a{color:red}' ) } ],
	} );

	const base = pathOf( artifact.url );
	const firstRevision = artifact.revision_id;

	await update( request, {
		id: artifact.id,
		files: [ { path: 'a.css', data_base64: b64( 'a{color:blue}' ) } ],
	} );

	expect( await ( await request.get( `${ base }a.css` ) ).text() ).toBe( 'a{color:blue}' );

	const pinned = await request.get( `${ base }~r${ firstRevision }/a.css` );
	expect( pinned.status() ).toBe( 200 );
	expect( await pinned.text() ).toBe( 'a{color:red}' );
} );

test( 'rolling back restores the content and the assets together', async ( { request } ) => {
	const first = '<!doctype html><html><body><p>one</p></body></html>';
	const second = '<!doctype html><html><body><p>two</p></body></html>';

	const artifact = await publish( request, {
		title: 'Versioned',
		slug: 'versioned',
		status: 'publish',
		content: first,
		files: [ { path: 'a.css', data_base64: b64( 'a{color:red}' ) } ],
	} );
	const base = pathOf( artifact.url );

	await update( request, {
		id: artifact.id,
		content: second,
		files: [ { path: 'a.css', data_base64: b64( 'a{color:blue}' ) } ],
	} );

	const revisions = await ability( request, 'wp-artifacts/revisions', { id: artifact.id } );
	expect( revisions.items.length ).toBeGreaterThanOrEqual( 2 );

	expect( await ( await request.get( base ) ).text() ).toBe( second );

	await ability( request, 'wp-artifacts/rollback', {
		id: artifact.id,
		revision_id: artifact.revision_id,
	} );

	expect( await ( await request.get( base ) ).text() ).toBe( first );
	expect( await ( await request.get( `${ base }a.css` ) ).text() ).toBe( 'a{color:red}' );
} );

test( 'a private artifact is a 404 without its share token', async ( { request, baseURL } ) => {
	const artifact = await publish( request, {
		title: 'Unlisted',
		slug: 'unlisted',
		status: 'private',
		content: DOC,
	} );

	// A separate context, so no login cookie comes along for the ride.
	const visitor = await apiRequest.newContext( { baseURL } );

	const anonymous = await visitor.get( pathOf( artifact.url ) );
	expect( anonymous.status() ).toBe( 404 );

	const shared = await visitor.get( artifact.share_url );
	expect( shared.status() ).toBe( 200 );
	expect( await shared.text() ).toBe( DOC );
	expect( shared.headers()[ 'cache-control' ] ).toContain( 'no-cache' );

	const rotated = await ability( request, 'wp-artifacts/share', {
		id: artifact.id,
		regenerate: true,
	} );
	expect( rotated.share_url ).not.toBe( artifact.share_url );

	const stale = await visitor.get( artifact.share_url );
	expect( stale.status() ).toBe( 404 );
	expect( ( await visitor.get( rotated.share_url ) ).status() ).toBe( 200 );

	await visitor.dispose();
} );

test( 'renaming an artifact leaves a 301 behind, assets included', async ( { request } ) => {
	const artifact = await publish( request, {
		title: 'Renamed',
		slug: 'before',
		status: 'publish',
		content: DOC,
		files: [ { path: 'a.css', data_base64: b64( 'a{}' ) } ],
	} );

	const before = pathOf( artifact.url );
	const renamed = await update( request, { id: artifact.id, slug: 'after' } );
	const after = pathOf( renamed.url );

	const entry = await request.get( before, { maxRedirects: 0 } );
	expect( entry.status() ).toBe( 301 );
	expect( entry.headers().location ).toContain( after );

	const asset = await request.get( `${ before }a.css`, { maxRedirects: 0 } );
	expect( asset.status() ).toBe( 301 );
	expect( asset.headers().location ).toContain( `${ after }a.css` );
} );

test( 'a deleted artifact answers 410, or 301 when a target was given', async ( { request } ) => {
	const gone = await publish( request, {
		title: 'Gone',
		slug: 'gone',
		status: 'publish',
		content: DOC,
	} );
	const gonePath = pathOf( gone.url );
	await ability( request, 'wp-artifacts/delete', { id: gone.id, force: true } );

	expect( ( await request.get( gonePath, { maxRedirects: 0 } ) ).status() ).toBe( 410 );

	const moved = await publish( request, {
		title: 'Moved',
		slug: 'moved',
		status: 'publish',
		content: DOC,
	} );
	const movedPath = pathOf( moved.url );
	await ability( request, 'wp-artifacts/delete', {
		id: moved.id,
		force: true,
		redirect_to: 'https://example.com/elsewhere/',
	} );

	const response = await request.get( movedPath, { maxRedirects: 0 } );
	expect( response.status() ).toBe( 301 );
	expect( response.headers().location ).toBe( 'https://example.com/elsewhere/' );
} );

test( 'an unknown artifact URL is answered by the router, not the theme', async ( { request } ) => {
	const response = await request.get( '/a/__probe__/' );

	expect( response.status() ).toBe( 404 );
	expect( response.headers()[ 'x-artifacts' ] ).toBe( '1' );
} );
