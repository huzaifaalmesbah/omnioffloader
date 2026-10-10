/**
 * Captures the real screens used in the walkthrough video.
 *
 * Runs against the local install (wp71.test) as an administrator. Nothing is
 * uploaded, downloaded or deleted: every job state (progress, preflights,
 * test connection) is a faked REST response, and Media Library states are
 * edited in the page only. Real keys, endpoint and bucket are replaced with
 * example values before any capture.
 *
 * Output: public/wt/<name>.png (2x) and src/wt-meta.json (page size + element
 * rectangles in CSS pixels, for highlights in the video).
 *
 * Usage: node scripts/capture.js [name …]
 */
const path = require( 'path' );
const fs = require( 'fs' );
const { execSync } = require( 'child_process' );
const puppeteer = require( path.join( __dirname, '../../../node_modules/puppeteer-core' ) );

const WP_ROOT = '/Users/huzaifa/Sites/wp/wp71';
const SITE = 'https://wp71.test';
const OUT = path.join( __dirname, '../public/wt' );
const META = path.join( __dirname, '../src/wt-meta.json' );
fs.mkdirSync( OUT, { recursive: true } );

const meta = fs.existsSync( META ) ? JSON.parse( fs.readFileSync( META, 'utf8' ) ) : {};

function wp( cmd ) {
	const raw = execSync( `wp ${ cmd } 2>/dev/null`, { cwd: WP_ROOT } ).toString();
	return raw.split( '\n' ).filter( ( l ) => l && ! l.startsWith( 'Deprecated' ) && l.trim() ).pop();
}

function authCookies() {
	const line = wp( `eval '$e=time()+86400;echo SECURE_AUTH_COOKIE."=".wp_generate_auth_cookie(1,$e,"secure_auth").";".LOGGED_IN_COOKIE."=".wp_generate_auth_cookie(1,$e,"logged_in");'` );
	return line.split( ';' ).map( ( c ) => {
		const [ name, ...rest ] = c.trim().split( '=' );
		return { name, value: rest.join( '=' ), domain: 'wp71.test', path: '/', secure: true };
	} );
}

const MEDIA = JSON.parse(
	wp( `eval 'echo wp_json_encode(array_map(fn($p)=>["id"=>$p->ID,"title"=>$p->post_title,"thumb"=>wp_get_attachment_image_url($p->ID,"thumbnail")],get_posts(["post_type"=>"attachment","posts_per_page"=>24,"post_status"=>"inherit"])));'` )
);

const SAFE_OFF = { active: false, reason: '', locked: false, live_address: 'example.com', current_address: 'example.com', environment: 'production', bring_back: true, folder_owner: '', folder: '' };
const stats = ( total, offloaded, extra = {} ) => ( { path: '/omnioffloader/v1/core/stats', data: { total, offloaded, local: total - offloaded, configured: true, provider: 'cloudflare-r2', safe_mode: SAFE_OFF, ...extra } } );
const NO_CLI = { path: '/omnioffloader/v1/core/cli-job', data: { running: false } };
const NO_MOVE = { path: '/omnioffloader/v1/move/preflight', data: { folder: 'wp-media/', elsewhere: 0 } };
const now = () => Math.floor( Date.now() / 1000 );

const recent = ( n, status = 'success' ) =>
	MEDIA.slice( 0, n ).map( ( m, i ) => ( { id: m.id, title: m.title, status, timestamp: now() - i * 3, thumb: m.thumb } ) );

const bulkState = ( processed, total, status = 'running' ) => ( {
	status,
	total,
	processed,
	errors: 0,
	updated_at: now(),
	current: status === 'running' ? MEDIA[ 6 ].id : 0,
	current_item: status === 'running' ? { id: MEDIA[ 6 ].id, title: MEDIA[ 6 ].title, status: 'processing', timestamp: now(), thumb: MEDIA[ 6 ].thumb } : null,
	recent: recent( 6 ),
} );

/** Replace real credentials and addresses with example values. */
async function maskSecrets( page ) {
	await page.evaluate( () => {
		const swap = ( v ) => {
			if ( /r2\.cloudflarestorage\.com/.test( v ) ) return 'https://<account-id>.r2.cloudflarestorage.com';
			if ( /r2\.dev/.test( v ) ) return v.replace( /https:\/\/pub-[a-f0-9]+\.r2\.dev/, 'https://media.example.com' );
			if ( /wp71\.test/.test( v ) ) return v.replace( /wp71\.test/g, 'example.com' );
			if ( /^[a-f0-9]{24,}$/i.test( v ) ) return '3f9c2e7a5b8d4f1e6a0c9b2d7e4f8a1c';
			if ( v === 'smowp' ) return 'my-site-media';
			if ( v === 'hello' ) return 'wp-media';
			return v;
		};
		document.querySelectorAll( 'input' ).forEach( ( el ) => {
			if ( el.type === 'text' || el.type === 'url' || el.type === '' ) {
				el.value = swap( el.value );
			}
		} );
		const walker = document.createTreeWalker( document.body, NodeFilter.SHOW_TEXT );
		let n;
		while ( ( n = walker.nextNode() ) ) {
			n.nodeValue = n.nodeValue
				.replace( /https:\/\/pub-[a-f0-9]+\.r2\.dev/g, 'https://media.example.com' )
				.replace( /[a-f0-9]{32}\.r2\.cloudflarestorage\.com/g, '<account-id>.r2.cloudflarestorage.com' )
				.replace( /\bsmowp\b/g, 'my-site-media' )
				.replace( /wp71\.test/g, 'example.com' )
				.replace( /WP 7\.1 Test/g, 'My Site' )
				.replace( /\bhello\//g, 'wp-media/' );
		}
	} );
}

/** Rectangles (document CSS px) for named selectors or {text, closest} lookups. */
async function rects( page, targets ) {
	return page.evaluate( ( targets ) => {
		const out = {};
		for ( const [ name, t ] of Object.entries( targets ) ) {
			let el = null;
			if ( typeof t === 'string' ) {
				el = document.querySelector( t );
			} else {
				const all = [ ...document.querySelectorAll( t.within || 'body *' ) ].filter( ( e ) => e.children.length === 0 || t.any );
				const hit = all.find( ( e ) => ( e.textContent || '' ).trim().includes( t.text ) );
				el = hit && t.closest ? hit.closest( t.closest ) : hit;
			}
			if ( el ) {
				const r = el.getBoundingClientRect();
				out[ name ] = [ Math.round( r.left ), Math.round( r.top ), Math.round( r.width ), Math.round( r.height ) ];
			}
		}
		return out;
	}, targets );
}

async function scrollTo( page, selectorOrText, above = 120 ) {
	await page.evaluate(
		( s, above ) => {
			let el = document.querySelector( s );
			if ( ! el ) {
				el = [ ...document.querySelectorAll( 'body *' ) ].find( ( e ) => e.children.length === 0 && ( e.textContent || '' ).trim() === s );
			}
			if ( el ) {
				window.scrollTo( 0, Math.max( 0, el.getBoundingClientRect().top + window.scrollY - above ) );
			}
		},
		selectorOrText,
		above
	);
	await new Promise( ( r ) => setTimeout( r, 400 ) );
}

const OFFLOADED_CELL = '<div class="omnioffloader-status-cell omnioffloader-status-cell--offloaded" data-status="offloaded"><span class="omnioffloader-status-icon omnioffloader-status-icon--offloaded" title="Offloaded"><svg class="omnioffloader-icon omnioffloader-icon--cloud" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg></span></div>';
const SPINNER_CELL = '<div class="omnioffloader-status-cell omnioffloader-status-cell--processing" data-status="processing"><span class="omnioffloader-status-icon omnioffloader-status-icon--processing"><svg class="omnioffloader-icon omnioffloader-icon--spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg></span></div>';

/** Media Library list: set row states. states: array of 'local'|'busy'|'offloaded' per row. */
async function mediaRows( page, states, busyText = 'Offloading...' ) {
	await page.evaluate(
		( states, OFF, SPIN, busyText ) => {
			document.querySelectorAll( '#the-list tr' ).forEach( ( tr, i ) => {
				const st = states[ i ] || 'local';
				const cell = tr.querySelector( 'td.column-omnioffloader_status' );
				const action = tr.querySelector( '.omnioffloader-row-action' );
				if ( st === 'offloaded' ) {
					if ( cell ) cell.innerHTML = OFF;
					if ( action ) action.textContent = 'Bring Back';
				} else if ( st === 'busy' ) {
					if ( cell ) cell.innerHTML = SPIN;
					if ( action ) action.textContent = busyText;
				}
				const ra = tr.querySelector( '.row-actions' );
				if ( ra ) {
					ra.classList.add( 'visible' );
					ra.style.left = 'auto';
					ra.style.position = 'static';
				}
			} );
		},
		states,
		OFFLOADED_CELL,
		SPINNER_CELL,
		busyText
	);
}

const ADMIN = `${ SITE }/wp-admin/admin.php?page=omnioffloader&view=`;

// --------------------------------------------------------------------------
const SHOTS = [
	{
		name: 'wporg',
		url: 'https://wordpress.org/plugins/omnioffloader/',
		public: true,
		full: 2600,
		rects: { title: '.plugin-title', download: '.plugin-download', description: '#tab-description' },
	},
	{
		name: 'add-plugins',
		url: `${ SITE }/wp-admin/plugin-install.php?s=OmniOffloader&tab=search&type=term`,
		wait: '.plugin-card',
		before: async ( page ) => {
			await page.evaluate( () => {
				const card = document.querySelector( '.plugin-card-omnioffloader' ) || document.querySelector( '.plugin-card' );
				const btn = card.querySelector( '.plugin-action-buttons .button' );
				btn.className = 'install-now button';
				btn.textContent = 'Install Now';
				window.__btn = btn;
			} );
		},
		rects: { search: '#search-plugins', card: '.plugin-card-omnioffloader', button: '.plugin-card-omnioffloader .plugin-action-buttons .button', menuPlugins: '#menu-plugins' },
		variants: {
			installing: ( page ) => page.evaluate( () => {
				window.__btn.className = 'install-now button updating-message';
				window.__btn.textContent = 'Installing...';
			} ),
			activate: ( page ) => page.evaluate( () => {
				window.__btn.className = 'button activate-now button-primary';
				window.__btn.textContent = 'Activate';
			} ),
		},
	},
	{
		name: 'dashboard-setup',
		url: `${ ADMIN }dashboard`,
		wait: '.smowp-hero',
		mocks: [ { path: '/omnioffloader/v1/core/stats', data: { total: 1240, offloaded: 0, local: 1240, configured: false, provider: '', safe_mode: SAFE_OFF } }, NO_CLI ],
		rects: { hero: '.smowp-hero', sidebar: '.smowp-sidebar', nav: '.smowp-sidebar__nav', menu: '#toplevel_page_omnioffloader' },
	},
	{
		name: 'settings',
		url: `${ ADMIN }settings`,
		wait: '.smowp-credential-fields input',
		mocks: [
			{ path: '/omnioffloader/v1/provider/test-connection', data: { connected: true, message: 'Connected', checked_at: '', folder: { status: 'ours', owner: '', folder: 'wp-media/', message: '' } } },
			NO_MOVE,
			NO_CLI,
		],
		before: async ( page ) => {
			const btn = await page.evaluateHandle( () => [ ...document.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.trim() === 'Test Connection' ) );
			await btn.click();
			await new Promise( ( r ) => setTimeout( r, 1200 ) );
			await page.evaluate( () => window.scrollTo( 0, 0 ) );
		},
		variants: {
			keys: () => null,
			cdn: async ( page ) => scrollTo( page, 'Bucket Name', 200 ),
			retention: async ( page ) => scrollTo( page, 'Retention Policy', 130 ),
			behavior: async ( page ) => scrollTo( page, 'Behavior', 130 ),
			staging: async ( page ) => scrollTo( page, 'Staging & Dev Copies', 130 ),
		},
		rects: {
			providers: '.smowp-provider-selector-grid',
			fields: '.smowp-credential-fields',
			cdn: { text: 'Custom Domain (CDN URL)' },
			test: '.smowp-test-connection',
			retention: { text: 'Retention Policy', closest: '.smowp-card' },
			serve: { text: 'Serve Media from Cloud', closest: 'label, .components-toggle-control' },
			behavior: { text: 'Behavior', closest: '.smowp-card' },
			speed: { text: 'Transfer Speed' },
			background: { text: 'Background Cloud Offload' },
			mirror: { text: 'Mirror Delete' },
			prefix: { text: 'Custom Path Prefix' },
			versioning: { text: 'Object Versioning' },
			protect: { text: 'Protect the live site when this is a copy' },
			allowBB: { text: 'Allow Bring Back on a copy (download only)' },
			retain: { text: 'Retain Local Files', closest: 'label, div' },
			smart: { text: 'Smart Local Cleanup', closest: 'label, div' },
			full: { text: 'Full Cloud Migration', closest: 'label, div' },
			staging: { text: 'Staging & Dev Copies', closest: '.smowp-card' },
			savebar: '.smowp-savebar',
		},
	},
	{
		name: 'dashboard',
		url: `${ ADMIN }dashboard`,
		wait: '.smowp-hero',
		mocks: [ stats( 1240, 1240 ), NO_CLI ],
		rects: { hero: '.smowp-hero', stats: '.smowp-stats-grid', quick: '.smowp-quick-actions-grid', nav: '.smowp-sidebar__nav' },
	},
	{
		name: 'offload',
		url: `${ ADMIN }offload`,
		wait: '.smowp-batch-scope',
		mocks: [ stats( 1240, 0 ), NO_CLI, { path: '/omnioffloader/v1/offload/bulk/progress', data: { status: 'idle', total: 0, processed: 0, errors: 0, updated_at: 0, recent: [] } } ],
		rects: { summary: '.smowp-media-summary', cli: '.smowp-cli-hint', scope: '.smowp-batch-scope', start: '.smowp-bulk-action__btn-wrap button' },
	},
	...[
		[ 'offload-run-1', 62 ],
		[ 'offload-run-2', 538 ],
		[ 'offload-run-3', 1104 ],
	].map( ( [ name, n ] ) => ( {
		name,
		url: `${ ADMIN }offload`,
		wait: '.smowp-progress',
		scroll: [ '.smowp-media-summary', 150 ],
		mocks: [ stats( 1240, n ), NO_CLI, { path: '/omnioffloader/v1/offload/bulk/progress', data: bulkState( n, 1240 ) } ],
		rects: { progress: '.smowp-progress', log: '.smowp-activity-log', summary: '.smowp-media-summary' },
	} ) ),
	{
		name: 'offload-run-4',
		url: `${ ADMIN }offload`,
		wait: '.smowp-progress',
		scroll: [ '.smowp-media-summary', 150 ],
		mocks: [ stats( 1240, 1239 ), NO_CLI, { path: '/omnioffloader/v1/offload/bulk/progress', data: bulkState( 1239, 1240 ) } ],
		rects: { progress: '.smowp-progress', log: '.smowp-activity-log', summary: '.smowp-media-summary' },
	},
	{
		name: 'cli-job',
		url: `${ ADMIN }offload`,
		wait: '.smowp-cli-job-notice',
		mocks: [
			stats( 59177, 3420 ),
			{ path: '/omnioffloader/v1/core/cli-job', data: { running: true, job: 'offload', processed: 3420, total: 59177, failed: 2, started: now() - 1500, updated: now() - 2, per_minute: 137, seconds_left: 24480, files_at_once: 64, background: true, pid: 11030, log: '/home/example/offload.log', stopping: false, quiet: false } },
		],
		rects: { notice: '.smowp-cli-job-notice', start: '.smowp-bulk-action__btn-wrap button' },
	},
	{
		name: 'media',
		url: `${ SITE }/wp-admin/upload.php?mode=list`,
		wait: '#the-list',
		before: ( page ) => mediaRows( page, [] ),
		rects: { filter: 'select[name="omnioffloader_filter"]', column: 'th#omnioffloader_status', row1: '#the-list tr:first-child', action1: '#the-list tr:first-child .omnioffloader-row-action', bulk: '.tablenav.top .bulkactions', check1: '#the-list tr:first-child th.check-column' },
		variants: {
			'busy': ( page ) => mediaRows( page, [ 'busy' ] ),
			'offloaded': ( page ) => mediaRows( page, [ 'offloaded', 'offloaded', 'offloaded', 'offloaded', 'offloaded', 'offloaded', 'offloaded', 'offloaded', 'offloaded' ] ),
			'restoring': ( page ) => mediaRows( page, [ 'busy', 'offloaded', 'offloaded', 'offloaded', 'offloaded', 'offloaded', 'offloaded', 'offloaded', 'offloaded' ], 'Restoring...' ),
		},
	},
	{
		name: 'attachment-edit',
		url: `${ SITE }/wp-admin/post.php?post=${ MEDIA[ 0 ].id }&action=edit`,
		wait: '#omnioffloader_meta_box',
		scroll: [ '#omnioffloader_meta_box', 260 ],
		rects: { box: '#omnioffloader_meta_box' },
	},
	{
		name: 'bringback',
		url: `${ ADMIN }bring-back`,
		wait: '.smowp-bringback-options',
		mocks: [
			stats( 1240, 1240 ),
			NO_CLI,
			{ path: '/omnioffloader/v1/bring-back/preflight', data: { offloaded: 1240, estimated_bytes: 2576980378, unsized: 0, quota: null, free_bytes: 56963203072, disk_ok: true } },
			{ path: '/omnioffloader/v1/bring-back/bulk/progress', data: { ...bulkState( 0, 0, 'idle' ), delete_cloud: false, recent: [] } },
		],
		rects: { summary: '.smowp-media-summary', preflight: '.smowp-preflight-info', scope: '.smowp-batch-scope', options: '.smowp-bringback-options', start: '.smowp-bulk-action__btn-wrap button', cli: '.smowp-cli-hint' },
		variants: { options: async ( page ) => scrollTo( page, '.smowp-batch-scope', 140 ) },
	},
	{
		name: 'bringback-run',
		url: `${ ADMIN }bring-back`,
		wait: '.smowp-progress',
		scroll: [ '.smowp-bringback-options', 120 ],
		mocks: [
			stats( 1240, 620 ),
			NO_CLI,
			{ path: '/omnioffloader/v1/bring-back/preflight', data: { offloaded: 620, estimated_bytes: 1288490189, unsized: 0, quota: null, free_bytes: 56963203072, disk_ok: true } },
			{ path: '/omnioffloader/v1/bring-back/bulk/progress', data: { ...bulkState( 620, 1240 ), delete_cloud: false } },
		],
		rects: { progress: '.smowp-progress', log: '.smowp-activity-log' },
	},
	{
		name: 'tools',
		url: `${ ADMIN }tools`,
		wait: '.smowp-cli',
		mocks: [
			stats( 1240, 1240 ),
			NO_CLI,
			{ path: '/omnioffloader/v1/retention/preflight', data: { eligible: 1240, policy: 1, to_clean: 1240, to_download: 0, freed_bytes: 1932735283, download_bytes: 0, free_bytes: 56963203072, disk_ok: true } },
			{ path: '/omnioffloader/v1/move/preflight', data: { folder: 'wp-media/', elsewhere: 318 } },
		],
		rects: {
			retention: { text: 'Apply Retention Policy to Existing Media', closest: '.smowp-card' },
			retentionFacts: '.smowp-retention-facts',
			move: '#omnioffloader-move-media',
			cli: '.smowp-cli',
			cliBackground: '.smowp-cli__background',
			cliFirst: '.smowp-cli__command',
			cliHint: '.smowp-cli-hint',
		},
		variants: {
			move: async ( page ) => scrollTo( page, '#omnioffloader-move-media', 130 ),
			cli: async ( page ) => scrollTo( page, '.smowp-cli', 130 ),
			cmds: async ( page ) => scrollTo( page, '.smowp-cli__command', 130 ),
		},
	},
	{
		name: 'safe-mode',
		url: `${ ADMIN }dashboard`,
		wait: '.smowp-safe-mode-banner',
		mocks: [ stats( 1240, 1240, { safe_mode: { ...SAFE_OFF, active: true, reason: 'address', live_address: 'https://example.com', current_address: 'https://staging.example.com', environment: 'staging' } } ), NO_CLI ],
		rects: { banner: '.smowp-safe-mode-banner', hero: '.smowp-hero' },
	},
];

async function capture( browser, cookies, shot ) {
	const page = await browser.newPage();
	await page.setViewport( { width: 1440, height: 900, deviceScaleFactor: 2 } );
	if ( ! shot.public ) {
		await page.setCookie( ...cookies );
	}
	const mocks = shot.mocks || [];
	await page.setRequestInterception( true );
	page.on( 'request', ( req ) => {
		const m = mocks.find( ( mk ) => req.url().includes( mk.path ) );
		if ( m ) {
			return req.respond( { status: 200, contentType: 'application/json', body: JSON.stringify( m.data ) } );
		}
		return req.continue();
	} );
	await page.goto( shot.url, { waitUntil: 'networkidle2', timeout: 60000 } );
	if ( shot.wait ) {
		await page.waitForSelector( shot.wait, { timeout: 15000 } );
	}
	await new Promise( ( r ) => setTimeout( r, 1500 ) );
	if ( shot.before ) {
		await shot.before( page );
		await new Promise( ( r ) => setTimeout( r, 600 ) );
	}
	if ( shot.scroll ) {
		await scrollTo( page, shot.scroll[ 0 ], shot.scroll[ 1 ] );
	}
	await maskSecrets( page );
	const save = async ( name ) => {
		await maskSecrets( page );
		const file = path.join( OUT, `${ name }.png` );
		if ( shot.full ) {
			const h = shot.full === true ? await page.evaluate( () => document.documentElement.scrollHeight ) : shot.full;
			await page.screenshot( { path: file, clip: { x: 0, y: 0, width: 1440, height: h }, captureBeyondViewport: true } );
			meta[ name ] = { w: 1440, h, rects: await rects( page, shot.rects || {} ) };
		} else {
			await page.screenshot( { path: file } );
			meta[ name ] = { w: 1440, h: 900, rects: await rects( page, shot.rects || {} ) };
		}
		console.log( `  ✓ ${ name }`, Object.keys( meta[ name ].rects ).join( ',' ) );
	};
	await save( shot.name );
	for ( const [ v, fn ] of Object.entries( shot.variants || {} ) ) {
		const extra = await fn( page );
		await new Promise( ( r ) => setTimeout( r, 300 ) );
		if ( extra && typeof extra === 'object' ) {
			shot.rects = { ...( shot.rects || {} ), ...extra };
		}
		await save( `${ shot.name }-${ v }` );
	}
	await page.close();
}

( async () => {
	const only = process.argv.slice( 2 );
	const cookies = authCookies();
	const browser = await puppeteer.launch( {
		executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
		headless: 'new',
		args: [ '--ignore-certificate-errors' ],
	} );
	for ( const shot of SHOTS.filter( ( s ) => ! only.length || only.includes( s.name ) ) ) {
		try {
			await capture( browser, cookies, shot );
		} catch ( e ) {
			console.error( `  ✗ ${ shot.name }: ${ e.message }` );
		}
	}
	await browser.close();
	fs.writeFileSync( META, JSON.stringify( meta, null, 1 ) );
} )();
