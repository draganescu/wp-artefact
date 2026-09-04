// Talks to the Playground instance the way an agent would: over the Abilities REST API.

const ADMIN = { user: 'admin', password: 'password' };

// An author: may publish artifacts, has no unfiltered_html.
const LOW_PRIV = { user: 'lowpriv', password: 'Low-Pass-12345' };

// Core picks the HTTP method from the ability annotations.
const READ_ONLY = new Set( [ 'get', 'list', 'revisions', 'site-style', 'site-style-resource', 'guide' ] );
const DELETING = new Set( [ 'delete' ] );

const nonces = new WeakMap();

async function login( request, credentials = ADMIN ) {
	if ( nonces.has( request ) ) {
		return nonces.get( request );
	}

	// A Playground instance that has just booted can drop the first request or two.
	for ( let attempt = 0; attempt < 3; attempt++ ) {
		await request.get( '/wp-login.php' );
		await request.post( '/wp-login.php', {
			form: {
				log: credentials.user,
				pwd: credentials.password,
				'wp-submit': 'Log In',
				redirect_to: '/wp-admin/',
				testcookie: '1',
			},
			maxRedirects: 5,
		} );

		const response = await request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' );
		const nonce = ( await response.text() ).trim();

		if ( /^[a-f0-9]{10}$/.test( nonce ) ) {
			nonces.set( request, nonce );

			return nonce;
		}

		await new Promise( ( resolve ) => setTimeout( resolve, 1000 ) );
	}

	throw new Error( 'Could not log in to the Playground instance. Is `npm run test:e2e:server` running?' );
}

// GET and DELETE carry the ability input as nested query parameters.
function toQuery( payload, prefix = 'input' ) {
	const pairs = [];

	const walk = ( key, value ) => {
		if ( value === null || value === undefined ) {
			return;
		}
		if ( Array.isArray( value ) ) {
			value.forEach( ( item, index ) => walk( `${ key }[${ index }]`, item ) );
		} else if ( typeof value === 'object' ) {
			Object.entries( value ).forEach( ( [ subKey, subValue ] ) => walk( `${ key }[${ subKey }]`, subValue ) );
		} else if ( typeof value === 'boolean' ) {
			pairs.push( [ key, value ? 'true' : 'false' ] );
		} else {
			pairs.push( [ key, String( value ) ] );
		}
	};

	walk( prefix, payload );

	return new URLSearchParams( pairs ).toString();
}

// Core picks the verb from the ability's annotations, so every caller has to.
async function callAbility( context, nonce, name, input = {} ) {
	const slug = name.split( '/' )[ 1 ];
	const route = `/wp-json/wp-abilities/v1/abilities/${ name }/run`;
	const headers = { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' };

	let response;
	if ( READ_ONLY.has( slug ) || DELETING.has( slug ) ) {
		const query = toQuery( input );
		const url = query ? `${ route }?${ query }` : route;
		response = READ_ONLY.has( slug )
			? await context.get( url, { headers } )
			: await context.delete( url, { headers } );
	} else {
		response = await context.post( route, { headers, data: { input } } );
	}

	return response.json();
}

async function ability( request, name, input = {} ) {
	const nonce = await login( request );

	return callAbility( request, nonce, name, input );
}

async function rest( request, method, path, data ) {
	const nonce = await login( request );
	const options = { headers: { 'X-WP-Nonce': nonce } };
	if ( data !== undefined ) {
		options.data = data;
	}

	const response = await request[ method.toLowerCase() ]( path, options );

	return response.json();
}

const publish = ( request, args ) => ability( request, 'wp-artifacts/publish', args );
const update = ( request, args ) => ability( request, 'wp-artifacts/update', args );

async function cleanup( request ) {
	await rest( request, 'POST', '/wp-json/wp/v2/settings', { show_on_front: 'posts' } );

	const list = await ability( request, 'wp-artifacts/list', { status: 'any', per_page: 100 } );

	for ( const item of list.items || [] ) {
		await ability( request, 'wp-artifacts/delete', { id: item.id, force: true } );
	}

	// Pages the delivery suite created, so a second run starts from the same place.
	const pages = await rest( request, 'GET', '/wp-json/wp/v2/pages?per_page=100&status=publish,draft' );

	for ( const page of Array.isArray( pages ) ? pages : [] ) {
		if ( page.slug && page.slug.startsWith( 'about' ) ) {
			await rest( request, 'DELETE', `/wp-json/wp/v2/pages/${ page.id }?force=true` );
		}
	}
}

const b64 = ( value ) => Buffer.from( value ).toString( 'base64' );
const pathOf = ( url ) => new URL( url ).pathname;

module.exports = { login, ability, callAbility, rest, publish, update, cleanup, b64, pathOf, ADMIN, LOW_PRIV };
