// The agent-facing surface: what an MCP client sees, and the full lifecycle.

const { test, expect } = require( '@playwright/test' );
const { ability, login, cleanup } = require( './wp' );

const DOC = '<!doctype html><html><body><h1>Cycle</h1></body></html>';

test.afterAll( async ( { request } ) => cleanup( request ) );

test( 'every artifact ability is registered with its category and annotations', async ( { request } ) => {
	const nonce = await login( request );
	const response = await request.get( '/wp-json/wp-abilities/v1/abilities?per_page=100', {
		headers: { 'X-WP-Nonce': nonce },
	} );

	const all = await response.json();
	const items = Array.isArray( all ) ? all : all.items;
	const ours = items.filter( ( item ) => item.name.startsWith( 'wp-artifacts/' ) );

	const names = ours.map( ( item ) => item.name ).sort();
	expect( names ).toEqual(
		[
			'wp-artifacts/delete',
			'wp-artifacts/get',
			'wp-artifacts/guide',
			'wp-artifacts/list',
			'wp-artifacts/publish',
			'wp-artifacts/revisions',
			'wp-artifacts/rollback',
			'wp-artifacts/screenshot',
			'wp-artifacts/set-front-page',
			'wp-artifacts/share',
			'wp-artifacts/site-style',
			'wp-artifacts/site-style-resource',
			'wp-artifacts/update',
			'wp-artifacts/upload-url',
		].sort()
	);

	for ( const item of ours ) {
		expect( item.category ).toBe( 'wp-artifacts' );
		expect( item.description.length ).toBeGreaterThan( 20 );
	}

	const byName = Object.fromEntries( ours.map( ( item ) => [ item.name, item ] ) );
	expect( byName[ 'wp-artifacts/get' ].meta.annotations.readonly ).toBe( true );
	expect( byName[ 'wp-artifacts/delete' ].meta.annotations.destructive ).toBe( true );
	expect( byName[ 'wp-artifacts/publish' ].meta.annotations.readonly ).toBe( false );
} );

test( 'publish, get, update, roll back, delete', async ( { request } ) => {
	const first = '<!doctype html><html><body><p>one</p></body></html>';
	const second = '<!doctype html><html><body><p>two</p></body></html>';

	const created = await ability( request, 'wp-artifacts/publish', {
		title: 'Cycle',
		slug: 'cycle',
		status: 'publish',
		content: first,
		provenance: { tool: 'playwright', model: 'none' },
	} );
	expect( created.id ).toBeGreaterThan( 0 );

	const fetched = await ability( request, 'wp-artifacts/get', { id: created.id } );
	expect( fetched.content ).toBe( first );
	expect( fetched.provenance.tool ).toBe( 'playwright' );

	await ability( request, 'wp-artifacts/update', { id: created.id, content: second } );

	const listed = await ability( request, 'wp-artifacts/list', { status: 'any' } );
	expect( listed.items.some( ( item ) => item.id === created.id ) ).toBe( true );

	await ability( request, 'wp-artifacts/rollback', {
		id: created.id,
		revision_id: created.revision_id,
	} );
	const rolled = await ability( request, 'wp-artifacts/get', { id: created.id } );
	expect( rolled.content ).toBe( first );

	const deleted = await ability( request, 'wp-artifacts/delete', { id: created.id, force: true } );
	expect( deleted.deleted ).toBe( true );

	const missing = await ability( request, 'wp-artifacts/get', { id: created.id } );
	expect( missing.code ).toBe( 'artifact_not_found' );
} );

test( 'site-style describes the theme and the guide explains the rules', async ( { request } ) => {
	const style = await ability( request, 'wp-artifacts/site-style', {} );

	expect( style.colors.palette.length ).toBeGreaterThan( 0 );
	expect( style.colors.background ).not.toContain( '--wp--preset' );
	expect( style.spacing.content_width ).toBeTruthy();
	expect( style.typography.font_families.length ).toBeGreaterThan( 0 );
	expect( style.guidance.length ).toBeGreaterThan( 50 );

	const resource = await ability( request, 'wp-artifacts/site-style-resource', {} );
	expect( resource.colors.palette.length ).toBeGreaterThan( 0 );

	const guide = await ability( request, 'wp-artifacts/guide', {} );
	expect( guide.mime ).toBe( 'text/markdown' );
	expect( guide.content ).toContain( 'unfiltered_html' );
	expect( guide.content ).toContain( 'wp-artifacts/site-style' );
} );

test( 'bad input comes back as an actionable error, not a stack trace', async ( { request } ) => {
	const traversal = await ability( request, 'wp-artifacts/publish', {
		title: 'Bad',
		content: DOC,
		files: [ { path: '../secrets.css', data_base64: 'eA==' } ],
	} );
	expect( traversal.code ).toBe( 'artifact_invalid_path' );
	expect( traversal.message ).toContain( '../secrets.css' );

	const mime = await ability( request, 'wp-artifacts/publish', {
		title: 'Bad',
		content: DOC,
		files: [ { path: 'a.bin', mime: 'application/octet-stream', data_base64: 'eA==' } ],
	} );
	expect( mime.code ).toBe( 'artifact_mime_not_allowed' );

	const missing = await ability( request, 'wp-artifacts/get', { id: 999999 } );
	expect( missing.code ).toBe( 'artifact_not_found' );
} );
