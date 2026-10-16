import { useCurrentFrame } from 'remotion';
import timing from '../reel-timing.json';
import script from '../../reel-script.json';

export type Tone = 'idea' | 'problem' | 'fix' | 'brand';
export type Beat = { headline: string; accent: string; tone: Tone; visual: string };
type Sen = { scene: string; k: number; start: number; end: number; text: string };

export const FPS = 30;
/** Seconds of picture before the voice starts. */
export const LEAD = 0.4;
/** Seconds held on the last scene after the voice ends. */
export const OUTRO = 2.8;
export const VOICE_DUR: number = timing.duration;
export const REEL_FRAMES = Math.round( ( LEAD + VOICE_DUR + OUTRO ) * FPS );

const BEATS: Record<string, Beat[]> = Object.fromEntries( ( script as { id: string; beats: Beat[] }[] ).map( ( s ) => [ s.id, s.beats ] ) );
export const SCENE_IDS = ( script as { id: string }[] ).map( ( s ) => s.id );

export const SEN = ( timing.sentences as Sen[] ).map( ( s ) => {
	const beat = BEATS[ s.scene ]?.[ s.k ];
	if ( ! beat ) {
		throw new Error( `No beat for ${ s.scene } sentence ${ s.k } — reel-script.json and reel-timing.json disagree` );
	}
	return { ...s, beat };
} );

/** Narration time (s): 0 = the first word. */
export const useT = () => useCurrentFrame() / FPS - LEAD;

/** Start (s) of sentence k in scene `id`. */
export const S = ( id: string, k: number ): number => {
	const s = SEN.find( ( x ) => x.scene === id && x.k === k );
	if ( ! s ) {
		throw new Error( `No sentence ${ k } in ${ id }` );
	}
	return s.start;
};

/** Visual lands just after the word is heard. */
const LAG = 0.15;

/**
 * Time (s) `word` is spoken in sentence k of scene `id` — the sentence start plus
 * the word's share of its characters. Throws if the word is not in the sentence,
 * so a script edit can never silently break a cue.
 */
export const W = ( id: string, k: number, word: string ): number => {
	const s = SEN.find( ( x ) => x.scene === id && x.k === k );
	if ( ! s ) {
		throw new Error( `No sentence ${ k } in ${ id }` );
	}
	const at = s.text.indexOf( word );
	if ( at < 0 ) {
		throw new Error( `"${ word }" not in ${ id } sentence ${ k }` );
	}
	const len = ( x: string ) => x.replace( /\s+/g, '' ).length;
	return s.start + ( len( s.text.slice( 0, at ) ) / len( s.text ) ) * ( s.end - s.start ) + LAG;
};

export const sceneStart = ( id: string ) => S( id, 0 );
export const sceneEnd = ( id: string ) => {
	const i = SCENE_IDS.indexOf( id );
	return i < SCENE_IDS.length - 1 ? sceneStart( SCENE_IDS[ i + 1 ] ) : VOICE_DUR + OUTRO + 1;
};
