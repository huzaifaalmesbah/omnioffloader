// Usage: node scripts/wt-frames.mjs bulk:12 media:4 … → "label frame" per arg (Walkthrough composition).
import fs from 'node:fs';
const timing = JSON.parse( fs.readFileSync( new URL( '../src/wt-timing.json', import.meta.url ) ) );
const FPS = 30, LEAD = 0.45, TAIL = 0.75, TR = 16, HOLD = 3.2;
let start = 0; const starts = {};
timing.forEach( ( s, i ) => { starts[ s.id ] = start; start += Math.round( ( LEAD + s.duration + TAIL + ( i === timing.length - 1 ? HOLD : 0 ) ) * FPS ) - TR; } );
for ( const a of process.argv.slice( 2 ) ) { const [ id, t ] = a.split( ':' ); console.log( `${ id }-${ t } ${ starts[ id ] + Math.round( ( +t + LEAD ) * FPS ) }` ); }
