// Standing in for a post, and standing at the site root.

const { test, expect } = require( '@playwright/test' );
const { ability, publish, rest, cleanup, pathOf } = require( './wp' );

const DOC = '<!doctype html><html><body><h1>Immersive</h1></body></html>';

test.afterAll( async ( { request } ) => cleanup( request ) );

test( 'an artifact can stand in for a page without changing its URL', async ( { request } ) => {
	const page = await rest( request, 'POST', '/wp-json/wp/v2/pages', {
		title: 'About',
		slug: 'about',
		status: 'publish',
		content: 'ORIGINAL PAGE BODY',
	} );
	const pagePath = pathOf( page.link );

	await publish( request, {
		title: 'About, immersive',
		slug: 'about-immersive',
		status: 'publish',
		content: DOC,
		parent_id: page.id,
		deliver_for_parent: true,
	} );

	const delivered = await request.get( pagePath );
	expect( delivered.status() ).toBe( 200 );
	expect( await delivered.text() ).toBe( DOC );

	// The parent owns the canonical URL and the indexing rules.
	expect( delivered.headers()[ 'x-robots-tag' ] ).toBeUndefined();

	const original = await request.get( `${ pagePath }?artifact_preview=0` );
	expect( await original.text() ).toContain( 'ORIGINAL PAGE BODY' );
} );

test( 'an artifact can be the front page', async ( { request } ) => {
	// Make sure no earlier test left something else standing at the root.
	await rest( request, 'POST', '/wp-json/wp/v2/settings', { show_on_front: 'posts' } );

	const artifact = await publish( request, {
		title: 'Home',
		slug: 'home-artifact',
		status: 'publish',
		content: DOC,
	} );

	const result = await ability( request, 'wp-artifacts/set-front-page', { id: artifact.id } );
	expect( result.ok ).toBe( true );

	const front = await request.get( '/' );
	expect( front.status() ).toBe( 200 );
	expect( await front.text() ).toBe( DOC );

	await ability( request, 'wp-artifacts/set-front-page', { id: artifact.id, restore: true } );
} );
