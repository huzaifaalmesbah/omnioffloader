#!/usr/bin/env node
/**
 * Brings the plugin in line with the version in .release-please-manifest.json.
 *
 * Release Please bumps the manifest and CHANGELOG.md only. WordPress and
 * WordPress.org read the version from four other places, and the user-facing
 * changelog lives in readme.txt, so the Release Please workflow runs this on
 * the release PR branch (and you can run it locally: `node scripts/sync-versions.mjs`):
 *
 * - `Version:` header and OMNIOFFLOADER_VERSION in omnioffloader.php
 * - `Stable tag:` in readme.txt
 * - `version` in package.json
 * - readme.txt `= Unreleased =` entries (Changelog and Upgrade Notice) become
 *   `= x.y.z =`; feature PRs write their changelog lines under `= Unreleased =`
 * - readme.txt keeps the two latest releases in its Changelog; older ones move
 *   to the top of changelog.txt (WordPress.org truncates a long Changelog)
 *
 * Safe to run more than once.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const path = ( file ) => resolve( root, file );
const read = ( file ) => readFileSync( path( file ), 'utf8' ).replace( /\r\n/g, '\n' );
const write = ( file, contents ) => writeFileSync( path( file ), contents );

const version = JSON.parse( read( '.release-please-manifest.json' ) )[ '.' ];

if ( ! /^\d+\.\d+\.\d+$/.test( version ?? '' ) ) {
	throw new Error( `No valid version in .release-please-manifest.json: ${ version }` );
}

const warnings = [];

/**
 * Replace a pattern that must exist exactly once.
 *
 * @param {string} contents    File contents.
 * @param {RegExp} pattern     Pattern with one capture group for the prefix.
 * @param {string} file        File name, for the error.
 * @return {string} Updated contents.
 */
function setVersion( contents, pattern, file ) {
	if ( ! pattern.test( contents ) ) {
		throw new Error( `Version line not found in ${ file }: ${ pattern }` );
	}

	return contents.replace( pattern, ( ...groups ) => {
		// Callback arguments: match, capture groups..., offset, input. Only the
		// strings among the captures are the prefix and (optional) suffix.
		const captures = groups.slice( 1, -2 ).map( ( group ) => group ?? '' );

		return `${ captures[ 0 ] }${ version }${ captures[ 1 ] ?? '' }`;
	} );
}

// omnioffloader.php: header and constant.
let plugin = read( 'omnioffloader.php' );
plugin = setVersion( plugin, /^( \* Version: )[^\n]+$/m, 'omnioffloader.php' );
plugin = setVersion( plugin, /(define\( 'OMNIOFFLOADER_VERSION', ')[^']+(' \);)/, 'omnioffloader.php' );
write( 'omnioffloader.php', plugin );

// package.json.
// Only the version line changes, so the file's formatting stays as it is.
write( 'package.json', setVersion( read( 'package.json' ), /^(\t"version": ")[^"]+(",)$/m, 'package.json' ) );

// readme.txt.
let readme = read( 'readme.txt' );
readme = setVersion( readme, /^(Stable tag: )[^\n]+$/m, 'readme.txt' );

/**
 * A `== Name ==` section of a file: where its body starts and ends.
 *
 * @param {string} contents File contents.
 * @param {string} name     Section name.
 * @return {{start: number, end: number}|null} Body offsets.
 */
function sectionRange( contents, name ) {
	const heading = contents.indexOf( `== ${ name } ==\n` );

	if ( -1 === heading ) {
		return null;
	}

	const start = heading + `== ${ name } ==\n`.length;
	const next = contents.indexOf( '\n== ', start );

	return { start, end: -1 === next ? contents.length : next + 1 };
}

for ( const name of [ 'Changelog', 'Upgrade Notice' ] ) {
	const range = sectionRange( readme, name );

	if ( ! range ) {
		throw new Error( `readme.txt has no == ${ name } == section.` );
	}

	let body = readme.slice( range.start, range.end );

	if ( body.includes( '= Unreleased =' ) ) {
		body = body.replace( '= Unreleased =', `= ${ version } =` );
	} else if ( ! body.includes( `= ${ version } =` ) ) {
		warnings.push( `readme.txt ${ name } has no "= Unreleased =" or "= ${ version } =" entry. Add one to the release PR's source branch before merging.` );
	}

	readme = readme.slice( 0, range.start ) + body + readme.slice( range.end );
}

// Keep the two latest releases in readme.txt; move older ones to changelog.txt.
const changelog = sectionRange( readme, 'Changelog' );
const body = readme.slice( changelog.start, changelog.end );
const pointer = body.match( /\nOlder releases:[^\n]*\n/ );
const entries = body.replace( /\nOlder releases:[^\n]*\n/, '\n' ).split( /^(?== \d+\.\d+\.\d+ =$)/m );
const intro = /^= \d/.test( entries[ 0 ] ) ? '' : entries.shift();

if ( entries.length > 2 ) {
	const keep = entries.slice( 0, 2 ).map( ( entry ) => entry.trimEnd() + '\n' );
	const move = entries.slice( 2 ).map( ( entry ) => entry.trimEnd() + '\n' );
	const newBody = intro + keep.join( '\n' ) + ( pointer ? '\n' + pointer[ 0 ].trim() + '\n' : '' ) + '\n';

	readme = readme.slice( 0, changelog.start ) + newBody + readme.slice( changelog.end );

	let older = read( 'changelog.txt' );
	const firstEntry = older.search( /^= \d+\.\d+\.\d+ =$/m );

	if ( -1 === firstEntry ) {
		throw new Error( 'changelog.txt has no "= x.y.z =" entry to insert before.' );
	}

	older = older.slice( 0, firstEntry ) + move.join( '\n' ) + '\n' + older.slice( firstEntry );
	write( 'changelog.txt', older );
}

write( 'readme.txt', readme );

console.log( `Synced version ${ version }.` );

for ( const warning of warnings ) {
	console.warn( `::warning::${ warning }` );
}
