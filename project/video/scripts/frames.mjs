// Usage: node scripts/frames.mjs hook:2 intro:9.5 … → prints "label frame" per arg.
import fs from 'node:fs';
const timing = JSON.parse( fs.readFileSync( new URL( '../src/timing.json', import.meta.url ) ) );
const FPS = 30, LEAD = 0.45, TAIL = 0.75, TR = 16, HOLD = 2.6;
let start = 0; const starts = {};
timing.forEach( ( s, i ) => { starts[ s.id ] = start; start += Math.round( ( LEAD + s.duration + TAIL + ( i === timing.length - 1 ? HOLD : 0 ) ) * FPS ) - TR; } );
for ( const a of process.argv.slice( 2 ) ) { const [ id, t ] = a.split( ':' ); console.log( `${ id }-${ t } ${ starts[ id ] + Math.round( ( +t + LEAD ) * FPS ) }` ); }
