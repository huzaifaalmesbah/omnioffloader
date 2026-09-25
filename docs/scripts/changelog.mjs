/**
 * Builds src/content/docs/changelog.md from the plugin's readme.txt.
 *
 * readme.txt is the single source of truth for the changelog (it ships to
 * WordPress.org), so the docs page is generated on every dev/build run and
 * never edited by hand.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname( dirname( fileURLToPath( import.meta.url ) ) );
const readmePath = resolve( root, '../readme.txt' );
const outPath = resolve( root, 'src/content/docs/changelog.md' );

const readme = readFileSync( readmePath, 'utf8' ).replace( /\r\n/g, '\n' );

/**
 * Returns the body of a `== Name ==` section of the readme.
 *
 * @param {string} name Section name.
 * @return {string} Section body, or an empty string.
 */
function section( name ) {
	const match = readme.match(
		new RegExp( `^== ${ name } ==\\n([\\s\\S]*?)(?=^== |(?![\\s\\S]))`, 'm' )
	);
	return match ? match[ 1 ].trim() : '';
}

/**
 * Splits a section into `= version =` blocks.
 *
 * @param {string} body Section body.
 * @return {Array<{version: string, body: string}>} Blocks in readme order.
 */
function versions( body ) {
	return body
		.split( /^= (.+?) =$/m )
		.slice( 1 )
		.reduce( ( list, part, index, parts ) => {
			if ( index % 2 === 0 ) {
				list.push( { version: part.trim(), body: parts[ index + 1 ].trim() } );
			}
			return list;
		}, [] );
}

const notices = new Map(
	versions( section( 'Upgrade Notice' ) ).map( ( { version, body } ) => [
		version,
		body.replace( /\s+/g, ' ' ),
	] )
);

const releases = versions( section( 'Changelog' ) );
if ( ! releases.length ) {
	throw new Error( `No changelog entries found in ${ readmePath }` );
}

const stableTag = readme.match( /^Stable tag:\s*(.+)$/m )?.[ 1 ]?.trim();

const body = releases
	.map( ( { version, body: notes } ) => {
		const lines = [ `## ${ version }` ];
		const notice = notices.get( version );
		if ( notice ) {
			lines.push( '', `*${ notice }*` );
		}
		// A line that is only **Bold** is a group heading in the readme.
		lines.push( '', notes.replace( /^\*\*(.+?)\*\*$/gm, '### $1' ) );
		return lines.join( '\n' );
	} )
	.join( '\n\n' );

const page = `---
title: Changelog
description: Every OmniOffloader release and what changed in it.
tableOfContents:
  maxHeadingLevel: 2
---

<!-- Generated from readme.txt by scripts/changelog.mjs — do not edit. -->

The latest version is **${ stableTag ?? releases[ 0 ].version }**. Update from **Dashboard → Updates** in WordPress, or download it from [WordPress.org](https://wordpress.org/plugins/omnioffloader/).

${ body }
`;

writeFileSync( outPath, page );
console.log( `changelog: ${ releases.length } releases → ${ outPath.replace( root + '/', '' ) }` );
