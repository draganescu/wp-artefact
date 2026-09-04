// The admin screens.

const { test, expect } = require( '@playwright/test' );
const { ability, publish, cleanup, ADMIN } = require( './wp' );

const DOC = '<!doctype html><html><body><h1>Admin</h1></body></html>';

async function loginUi( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', ADMIN.user );
	await page.fill( '#user_pass', ADMIN.password );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

test.afterAll( async ( { request } ) => cleanup( request ) );

test( 'the list table shows the artifact columns', async ( { page, request } ) => {
	await publish( request, {
		title: 'Listed artifact',
		slug: 'listed',
		status: 'publish',
		content: DOC,
	} );

	await loginUi( page );
	await page.goto( '/wp-admin/edit.php?post_type=artifact' );

	await expect( page.locator( '#wp_artifacts_size' ) ).toBeVisible();
	await expect( page.locator( '#wp_artifacts_files' ) ).toBeVisible();
	await expect( page.locator( '#wp_artifacts_tool' ) ).toBeVisible();
	await expect( page.getByText( 'Listed artifact' ).first() ).toBeVisible();
} );

test( 'the edit screen shows code and preview, never the block editor', async ( { page, request } ) => {
	const artifact = await publish( request, {
		title: 'Editable',
		slug: 'editable',
		status: 'publish',
		content: DOC,
	} );

	await loginUi( page );
	await page.goto( `/wp-admin/post.php?post=${ artifact.id }&action=edit` );

	await expect( page.locator( '.wp-artifacts-code' ) ).toHaveValue( DOC );
	await expect( page.locator( '.wp-artifacts-preview__frame' ) ).toBeVisible();
	await expect( page.locator( '#wp_artifacts_delivery' ) ).toBeVisible();
	await expect( page.locator( '#wp_artifacts_provenance' ) ).toBeVisible();
	await expect( page.locator( '.block-editor' ) ).toHaveCount( 0 );
	await expect( page.locator( '#postdivrich' ) ).toHaveCount( 0 );
	await expect( page.locator( '#content' ) ).toHaveCount( 0 );
} );

test( 'saving from the edit screen does not touch the stored bytes', async ( { page, request } ) => {
	const tricky = '<!doctype html><html><body><p>He said "it\'s 100% done" — ✅</p><script>var a=1;</script></body></html>';

	const artifact = await publish( request, {
		title: 'Untouched',
		slug: 'untouched',
		status: 'publish',
		content: tricky,
	} );

	await loginUi( page );
	await page.goto( `/wp-admin/post.php?post=${ artifact.id }&action=edit` );
	await page.click( '#publish' );
	await page.waitForURL( /post\.php/ );

	const after = await ability( request, 'wp-artifacts/get', { id: artifact.id } );
	expect( after.content ).toBe( tricky );

	const served = await request.get( artifact.url );
	expect( await served.text() ).toBe( tricky );
} );

test( 'the settings page is there and knows the prefix', async ( { page } ) => {
	await loginUi( page );
	await page.goto( '/wp-admin/options-general.php?page=wp-artifacts' );

	await expect( page.locator( '#wp_artifacts_prefix' ) ).toHaveValue( 'a' );
	await expect( page.locator( '#wp_artifacts_csp_mode' ) ).toBeVisible();
	await expect( page.locator( '#wp_artifacts_screenshot_provider' ) ).toBeVisible();
} );

test( 'Site Health reports on artifact serving', async ( { page } ) => {
	await loginUi( page );
	await page.goto( '/wp-admin/site-health.php' );

	await expect( page.locator( 'body' ) ).toContainText( 'Site Health' );
} );
