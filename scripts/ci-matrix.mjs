#!/usr/bin/env node
/**
 * Builds the integration-test matrix for .github/workflows/tests.yml.
 *
 * Nothing is hard-coded: the supported range comes from readme.txt
 * (`Requires at least`, `Requires PHP`), WordPress versions from the
 * WordPress.org API (as Gutenberg does), and the PHP versions core supports
 * from wordpress-develop's .version-support-php.json (as core's own CI does).
 *
 * - WordPress: the newest point release of `Requires at least` ("oldest"),
 *   of the previous major ("previous") and the latest release ("latest"),
 *   plus "nightly" (or NEXT_WP) as an experimental job.
 * - PHP: what core supports for the latest WordPress major, from
 *   `Requires PHP` up. A newer PHP that core lists only for a coming major
 *   (8.6 while 7.1 is current) runs as an experimental job, and becomes a
 *   required one by itself once that WordPress ships.
 *
 * Reduced matrix (pull requests, pushes): lowest and highest PHP against
 * oldest/latest WordPress, highest PHP against previous, plus one job each
 * for multisite, a Redis object cache and MariaDB. Full matrix (FULL=true:
 * weekly, the `full matrix` PR label, or on demand): every PHP × oldest,
 * previous and latest × single site and multisite, plus those extras.
 *
 * Usage: FULL=true NEXT_WP=7.2-RC3 node scripts/ci-matrix.mjs
 * Writes `matrix=<json>` to $GITHUB_OUTPUT when set, and a table to
 * $GITHUB_STEP_SUMMARY; prints both otherwise.
 */
import { appendFileSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const STABLE_CHECK = 'https://api.wordpress.org/core/stable-check/1.0/';
const PHP_SUPPORT =
	'https://raw.githubusercontent.com/WordPress/wordpress-develop/trunk/.version-support-php.json';

const DB_DEFAULT = 'mysql:8.0';
const DB_OLDEST = 'mysql:5.7';
const DB_MARIADB = 'mariadb:10.6';

// Database images, pinned by digest (zizmor's unpinned-images audit). The
// workflow can't pin an image chosen per job, so the pins live here. Refresh
// with: curl -s https://hub.docker.com/v2/repositories/library/<name>/tags/<tag> | jq -r .digest
const DB_IMAGES = {
	'mysql:8.0':
		'mysql:8.0@sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b',
	'mysql:5.7':
		'mysql:5.7@sha256:4bc6bc963e6d8443453676cae56536f4b8156d78bae03c0145cbe47c2aad73bb',
	'mariadb:10.6':
		'mariadb:10.6@sha256:75079c0fb96894284d00d035bc4bef94234814f6f6c28b79877bc69d3285d760',
};

const full = process.env.FULL === 'true';
const nextWp = ( process.env.NEXT_WP || '' ).trim() || 'nightly';

/**
 * Compare two dotted version strings.
 *
 * @param {string} a Version.
 * @param {string} b Version.
 * @return {number} Negative, zero or positive.
 */
function compare( a, b ) {
	const pa = a.split( '.' ).map( Number );
	const pb = b.split( '.' ).map( Number );
	for ( let i = 0; i < Math.max( pa.length, pb.length ); i++ ) {
		const d = ( pa[ i ] || 0 ) - ( pb[ i ] || 0 );
		if ( d !== 0 ) {
			return d;
		}
	}
	return 0;
}

/**
 * "7.1.5" → "7.1".
 *
 * @param {string} version Version.
 * @return {string} Major series.
 */
const series = ( version ) => version.split( '.' ).slice( 0, 2 ).join( '.' );

/**
 * A readme.txt header value.
 *
 * @param {string} readme Readme contents.
 * @param {string} field  Header name.
 * @return {string} Value.
 */
function header( readme, field ) {
	const match = readme.match( new RegExp( `^${ field }:\\s*(\\S+)`, 'mi' ) );
	if ( ! match ) {
		throw new Error( `readme.txt has no "${ field }:" header.` );
	}
	return match[ 1 ];
}

/**
 * Fetch JSON, failing loudly: a wrong matrix is worse than no run.
 *
 * @param {string} url URL.
 * @return {Promise<any>} Decoded body.
 */
async function getJson( url ) {
	const response = await fetch( url, { headers: { Accept: 'application/json' } } );
	if ( ! response.ok ) {
		throw new Error( `${ url } answered HTTP ${ response.status }.` );
	}
	return response.json();
}

const readme = readFileSync( join( ROOT, 'readme.txt' ), 'utf8' );
const requiresWp = series( header( readme, 'Requires at least' ) );
const requiresPhp = series( header( readme, 'Requires PHP' ) );

const [ stable, phpSupport ] = await Promise.all( [
	getJson( STABLE_CHECK ),
	getJson( PHP_SUPPORT ),
] );

const versions = Object.keys( stable ).filter( ( v ) => /^\d+\.\d+(\.\d+)?$/.test( v ) );
const latest = Object.keys( stable ).find( ( v ) => stable[ v ] === 'latest' );
if ( ! latest ) {
	throw new Error( 'The WordPress.org API names no latest version.' );
}

/**
 * Newest release of a major series, e.g. "6.6" → "6.6.12".
 *
 * @param {string} major Series.
 * @return {string|undefined} Version.
 */
const newestOf = ( major ) =>
	versions.filter( ( v ) => series( v ) === major ).sort( compare ).pop();

const allSeries = [ ...new Set( versions.map( series ) ) ].sort( compare );
const latestSeries = series( latest );
const previousSeries = allSeries[ allSeries.indexOf( latestSeries ) - 1 ];

const wp = {
	oldest: newestOf( requiresWp ),
	previous: previousSeries ? newestOf( previousSeries ) : undefined,
	latest,
};
if ( ! wp.oldest ) {
	throw new Error( `No WordPress ${ requiresWp }.x release found ("Requires at least").` );
}
// The previous major can be the oldest supported one: test it once.
if ( wp.previous && series( wp.previous ) === requiresWp ) {
	delete wp.previous;
}

// PHP: core's list for the latest major ("7-1"), from Requires PHP up.
const phpKeys = Object.keys( phpSupport ).sort( ( a, b ) =>
	compare( a.replace( '-', '.' ), b.replace( '-', '.' ) )
);
const latestKey = latestSeries.replace( '.', '-' );
const supported = ( phpSupport[ latestKey ] || phpSupport[ phpKeys.at( -1 ) ] )
	.filter( ( v ) => compare( v, requiresPhp ) >= 0 )
	.sort( compare );
if ( ! supported.length ) {
	throw new Error( `Core lists no PHP version from ${ requiresPhp } up.` );
}
const phpMin = supported[ 0 ];
const phpMax = supported.at( -1 );
const phpNext = phpSupport[ phpKeys.at( -1 ) ]
	.filter( ( v ) => compare( v, phpMax ) > 0 )
	.sort( compare )
	.pop();

const jobs = new Map();

/**
 * Add one job; duplicates collapse.
 *
 * @param {Object} job Job.
 */
function add( job ) {
	const entry = {
		php: job.php,
		wp: job.wp,
		wp_label: job.wp_label,
		multisite: Boolean( job.multisite ),
		object_cache: Boolean( job.object_cache ),
		db: job.db || DB_DEFAULT,
		db_image: DB_IMAGES[ job.db || DB_DEFAULT ],
		experimental: Boolean( job.experimental ),
	};
	const key = [ entry.php, entry.wp, entry.multisite, entry.object_cache, entry.db ].join( '|' );
	if ( ! jobs.has( key ) ) {
		jobs.set( key, entry );
	}
}

const wpJob = ( label ) => ( { wp: wp[ label ], wp_label: label, db: label === 'oldest' ? DB_OLDEST : DB_DEFAULT } );

if ( full ) {
	for ( const php of supported ) {
		for ( const label of Object.keys( wp ) ) {
			add( { php, ...wpJob( label ) } );
			add( { php, ...wpJob( label ), multisite: true } );
		}
	}
} else {
	add( { php: phpMin, ...wpJob( 'oldest' ) } );
	add( { php: phpMin, ...wpJob( 'latest' ) } );
	add( { php: phpMax, ...wpJob( 'latest' ) } );
	if ( wp.previous ) {
		add( { php: phpMax, ...wpJob( 'previous' ) } );
	}
	add( { php: phpMax, ...wpJob( 'latest' ), multisite: true } );
}

// In both: a persistent object cache, MariaDB, and what comes next.
add( { php: phpMax, ...wpJob( 'latest' ), object_cache: true } );
add( { php: phpMax, ...wpJob( 'latest' ), db: DB_MARIADB } );
if ( phpNext ) {
	add( { php: phpNext, ...wpJob( 'latest' ), experimental: true } );
}
add( { php: phpMax, wp: nextWp, wp_label: 'next', experimental: true } );

const include = [ ...jobs.values() ];
const matrix = JSON.stringify( { include } );

const summary = [
	`### Test matrix (${ full ? 'full' : 'reduced' }: ${ include.length } jobs)`,
	'',
	`Requires at least **${ requiresWp }**, Requires PHP **${ requiresPhp }**. WordPress: oldest **${ wp.oldest }**, previous **${ wp.previous || '—' }**, latest **${ wp.latest }**, next **${ nextWp }**. PHP: **${ supported.join( ', ' ) }**${ phpNext ? `, experimental **${ phpNext }**` : '' }.`,
	'',
	'| PHP | WordPress | Site | Object cache | Database | Required |',
	'|---|---|---|---|---|---|',
	...include.map(
		( j ) =>
			`| ${ j.php } | ${ j.wp } (${ j.wp_label }) | ${ j.multisite ? 'multisite' : 'single' } | ${ j.object_cache ? 'Redis' : '—' } | ${ j.db } | ${ j.experimental ? 'no (experimental)' : 'yes' } |`
	),
	'',
].join( '\n' );

if ( process.env.GITHUB_OUTPUT ) {
	appendFileSync( process.env.GITHUB_OUTPUT, `matrix=${ matrix }\n` );
} else {
	process.stdout.write( `matrix=${ matrix }\n\n` );
}
if ( process.env.GITHUB_STEP_SUMMARY ) {
	appendFileSync( process.env.GITHUB_STEP_SUMMARY, summary );
} else {
	process.stdout.write( summary );
}
