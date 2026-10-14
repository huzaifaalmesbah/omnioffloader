// Usage: node scripts/bn-frames.mjs 01-hook:6 … → "label frame" (BanglaR2 composition).
import fs from 'node:fs';
const timing = JSON.parse( fs.readFileSync( new URL( '../src/bn-timing.json', import.meta.url ) ) );
const FPS = 30, LEAD = 0.45, TAIL = 0.75, TR = 16, HOLD = 3.0;
let start = 0; const starts = {};
timing.forEach( ( s, i ) => { starts[ s.id ] = start; start += Math.round( ( LEAD + s.duration + TAIL + ( i === timing.length - 1 ? HOLD : 0 ) ) * FPS ) - TR; } );
for ( const a of process.argv.slice( 2 ) ) { const [ id, t ] = a.split( ':' ); console.log( `${ id }-${ t } ${ starts[ id ] + Math.round( ( +t + LEAD ) * FPS ) }` ); }
