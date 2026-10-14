import React from 'react';
import { Audio, Sequence, staticFile, useCurrentFrame, useVideoConfig, interpolate, Easing } from 'remotion';
import timing from '../bn-timing.json';
import { C, BN, SANS, MONO, LEAD } from '../theme';
import { Icon } from '../components';

type Sentence = { start: number; end: number; text: string };
type Scene = { id: string; duration: number; sentences: Sentence[] };
export const BN_T = timing as Scene[];

/** Start (s) of sentence k in scene `id`, narration time. */
export const S = ( id: string, k: number ): number => {
	const sc = BN_T.find( ( s ) => s.id === id );
	if ( ! sc || ! sc.sentences[ k ] ) {
		throw new Error( `No sentence ${ k } in ${ id }` );
	}
	return sc.sentences[ k ].start;
};
/** Small delay so a visual lands as the word is heard, not before it. */
const LAG = 0.2;

/**
 * Time (s) the narrator says `word` in sentence k of scene `id`: the sentence's
 * start plus the word's share of the sentence's characters. Throws when the word
 * is not in the sentence, so a script edit can never silently break a cue.
 */
export const W = ( id: string, k: number, word: string ): number => {
	const sc = BN_T.find( ( s ) => s.id === id );
	const sen = sc?.sentences[ k ];
	if ( ! sen ) {
		throw new Error( `No sentence ${ k } in ${ id }` );
	}
	const at = sen.text.indexOf( word );
	if ( at < 0 ) {
		throw new Error( `"${ word }" not in ${ id } sentence ${ k }` );
	}
	const nonSpace = ( x: string ) => x.replace( /\s+/g, '' ).length;
	const frac = nonSpace( sen.text.slice( 0, at ) ) / nonSpace( sen.text );
	return sen.start + frac * ( sen.end - sen.start ) + LAG;
};

export const sceneDur = ( id: string ) => BN_T.find( ( s ) => s.id === id )?.duration ?? 0;

const ease = Easing.inOut( Easing.cubic );
export const rp = ( t: number, a: number, b: number ) => interpolate( t, [ a, b ], [ 0, 1 ], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: ease } );
/** Smooth ease-out (expo-like), no overshoot. */
const SMOOTH = Easing.bezier( 0.16, 1, 0.3, 1 );
export const pop = ( t: number, at: number, len = 0.7 ) => interpolate( t, [ at, at + len ], [ 0, 1 ], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: SMOOTH } );

/** Opacity + rise + un-blur for a smooth text reveal. */
export const reveal = ( p: number, dist = 22 ): React.CSSProperties => ( {
	opacity: p,
	transform: `translateY(${ ( 1 - p ) * dist }px)`,
	filter: p < 1 ? `blur(${ ( 1 - p ) * 10 }px)` : undefined,
} );

/** One-shot sound effect at narration time `at` (seconds) in the current scene. */
export const Sfx: React.FC<{ at: number; name: 'click' | 'whoosh' | 'pop'; volume?: number }> = ( { at, name, volume = 0.5 } ) => {
	const { fps } = useVideoConfig();
	return (
		<Sequence from={ Math.max( 0, Math.round( ( at + LEAD ) * fps ) ) } durationInFrames={ Math.round( fps * 1.2 ) } layout="none">
			<Audio src={ staticFile( `sfx/${ name }.wav` ) } volume={ volume } />
		</Sequence>
	);
};
export const fadeWin = ( t: number, a: number, b: number ) => rp( t, a, a + 0.45 ) * ( 1 - rp( t, b, b + 0.45 ) );

/** Bangla digits for on-screen numbers. */
const BD = '০১২৩৪৫৬৭৮৯';
export const bn = ( n: number | string ) => String( n ).replace( /[0-9]/g, ( d ) => BD[ +d ] );

/* ------------------------------------------------------------------ */
/* Captions: script text, split into short lines timed by char share. */
/* ------------------------------------------------------------------ */
export const BnCaptions: React.FC<{ id: string }> = ( { id } ) => {
	const frame = useCurrentFrame();
	const { fps } = useVideoConfig();
	const t = frame / fps - LEAD;
	const sc = BN_T.find( ( s ) => s.id === id );
	if ( ! sc ) {
		return null;
	}
	const lines: Sentence[] = [];
	for ( const s of sc.sentences ) {
		const words = s.text.split( /\s+/ );
		const n = Math.ceil( words.length / 8 );
		const per = Math.ceil( words.length / n );
		const parts: string[] = [];
		for ( let i = 0; i < words.length; i += per ) {
			parts.push( words.slice( i, i + per ).join( ' ' ) );
		}
		const total = parts.reduce( ( a, p ) => a + p.length, 0 );
		let at = s.start;
		for ( const p of parts ) {
			const d = ( ( s.end - s.start ) * p.length ) / total;
			lines.push( { start: at, end: at + d, text: p } );
			at += d;
		}
	}
	const cur = lines.find( ( c, i ) => t >= c.start - 0.05 && ( t < c.end || ( i === lines.length - 1 && t < c.end + 0.5 ) ) );
	if ( ! cur ) {
		return null;
	}
	const op = interpolate( t, [ cur.start - 0.05, cur.start + 0.12 ], [ 0, 1 ], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' } );
	return (
		<div style={ { position: 'absolute', left: 0, right: 0, bottom: 46, display: 'flex', justifyContent: 'center', opacity: op } }>
			<div style={ { maxWidth: 1500, padding: '12px 30px 14px', borderRadius: 16, background: 'rgba(3,9,24,0.78)', border: '1px solid rgba(255,255,255,0.08)', color: '#fff', fontFamily: `${ BN }, ${ SANS }`, fontSize: 38, fontWeight: 600, lineHeight: 1.35, textAlign: 'center' } }>{ cur.text }</div>
		</div>
	);
};

/* ------------------------------------------------------------------ */
/* Step tracker (top right) for the five how-to steps.                  */
/* ------------------------------------------------------------------ */
const STEPS = [ 'Bucket', 'Domain', 'Token', 'Plugin', 'Offload' ];
export const StepTracker: React.FC<{ t: number; step: number }> = ( { t, step } ) => {
	const p = rp( t, -0.4, 0.2 );
	return (
		<div style={ { position: 'absolute', right: 56, top: 36, display: 'flex', alignItems: 'center', gap: 10, opacity: p, fontFamily: `${ BN }, ${ SANS }` } }>
			<div style={ { color: C.muted, fontSize: 22, fontWeight: 600, marginRight: 6 } }>ধাপ { bn( step ) }/{ bn( 5 ) }</div>
			{ STEPS.map( ( s, i ) => {
				const done = i + 1 < step;
				const cur = i + 1 === step;
				return (
					<div key={ s } style={ { display: 'flex', alignItems: 'center', gap: 8, padding: '7px 14px', borderRadius: 999, fontSize: 18, fontWeight: 700, fontFamily: SANS, background: cur ? C.blue : done ? 'rgba(74,222,128,0.16)' : 'rgba(255,255,255,0.06)', color: cur ? '#fff' : done ? C.greenSoft : C.muted, border: `1px solid ${ cur ? C.blueLight : 'rgba(255,255,255,0.1)' }`, transform: `scale(${ cur ? 1 + 0.05 * Math.sin( t * 4 ) : 1 })` } }>
						{ done ? <Icon name="check" size={ 16 } color={ C.greenSoft } stroke={ 3 } /> : null }
						{ s }
					</div>
				);
			} ) }
		</div>
	);
};

/** Big Bangla headline with a word-by-word reveal. */
export const Headline: React.FC<{ t: number; at: number; text: string; size?: number; color?: string; style?: React.CSSProperties; accent?: string }> = ( { t, at, text, size = 76, color = C.white, style, accent } ) => {
	const words = text.split( ' ' );
	return (
		<div style={ { fontFamily: `${ BN }, ${ SANS }`, fontSize: size, fontWeight: 700, lineHeight: 1.25, color, ...style } }>
			{ words.map( ( w, i ) => {
				const p = pop( t, at + i * 0.08, 0.8 );
				const hot = accent && w.includes( accent );
				return (
					<span key={ i } style={ { display: 'inline-block', marginRight: size * 0.28, ...reveal( p, 24 ), color: hot ? C.sky : undefined } }>
						{ w }
					</span>
				);
			} ) }
		</div>
	);
};

/* ------------------------------------------------------------------ */
/* Simplified Cloudflare dashboard (illustration, not a screenshot).    */
/* ------------------------------------------------------------------ */
export const CF = { orange: '#f38020', ink: '#1d1f20', line: '#e5e7eb', soft: '#f6f7f8', blue: '#0051c3' };

export const CfWindow: React.FC<{ t: number; children: React.ReactNode; nav?: string; appear?: number; x?: number; y?: number; w?: number; h?: number; crumb?: string }> = ( { children, nav = 'R2 Object Storage', appear = 1, x = 44, y = 120, w = 1260, h = 806, crumb } ) => (
	<div style={ { position: 'absolute', left: x, top: y, width: w, height: h, borderRadius: 18, overflow: 'hidden', background: '#fff', boxShadow: '0 40px 100px rgba(0,0,0,0.55), 0 0 0 1px rgba(255,255,255,0.14)', opacity: appear, transform: `translateY(${ ( 1 - appear ) * 40 }px)`, fontFamily: SANS, color: CF.ink } }>
		<div style={ { height: 44, background: '#eef1f6', display: 'flex', alignItems: 'center', padding: '0 18px', gap: 9 } }>
			{ [ '#ff5f57', '#febc2e', '#28c840' ].map( ( c ) => (
				<div key={ c } style={ { width: 13, height: 13, borderRadius: 7, background: c } } />
			) ) }
			<div style={ { marginLeft: 20, flex: 1, height: 26, borderRadius: 8, background: '#fff', fontSize: 14, color: '#556', display: 'flex', alignItems: 'center', paddingLeft: 14 } }>dash.cloudflare.com</div>
		</div>
		<div style={ { display: 'flex', height: h - 44 } }>
			<div style={ { width: 230, borderRight: `1px solid ${ CF.line }`, padding: '18px 0', background: '#fbfbfc' } }>
				<div style={ { display: 'flex', alignItems: 'center', gap: 10, padding: '0 20px 20px', fontWeight: 800, fontSize: 19 } }>
					<Icon name="cloud" size={ 26 } color={ CF.orange } stroke={ 2.4 } /> Cloudflare
				</div>
				{ [ 'Account Home', 'Domains', 'Analytics', 'R2 Object Storage', 'Workers', 'Manage Account' ].map( ( n ) => (
					<div key={ n } style={ { padding: '11px 20px', fontSize: 16, fontWeight: n === nav ? 700 : 500, color: n === nav ? CF.blue : '#4b5563', background: n === nav ? '#eaf1fd' : 'transparent', borderLeft: `3px solid ${ n === nav ? CF.blue : 'transparent' }` } }>{ n }</div>
				) ) }
			</div>
			<div style={ { flex: 1, padding: '26px 34px', position: 'relative', overflow: 'hidden' } }>
				{ crumb ? <div style={ { fontSize: 14, color: '#6b7280', marginBottom: 8 } }>{ crumb }</div> : null }
				{ children }
			</div>
		</div>
		<div style={ { position: 'absolute', right: 14, bottom: 10, fontSize: 12, color: '#9ca3af' } }>simplified illustration</div>
	</div>
);

export const CfButton: React.FC<{ label: string; press?: boolean; primary?: boolean; style?: React.CSSProperties }> = ( { label, press, primary = true, style } ) => (
	<div style={ { display: 'inline-flex', alignItems: 'center', padding: '11px 20px', borderRadius: 8, fontSize: 17, fontWeight: 700, background: primary ? CF.blue : '#fff', color: primary ? '#fff' : CF.blue, border: `1px solid ${ CF.blue }`, transform: `scale(${ press ? 0.92 : 1 })`, ...style } }>{ label }</div>
);

export const CfField: React.FC<{ label: string; value: string; focus?: boolean; mono?: boolean; blur?: boolean }> = ( { label, value, focus, mono, blur } ) => (
	<div style={ { marginBottom: 18 } }>
		<div style={ { fontSize: 15, fontWeight: 700, color: '#374151', marginBottom: 7 } }>{ label }</div>
		<div style={ { height: 46, borderRadius: 8, border: `1.5px solid ${ focus ? CF.blue : '#d1d5db' }`, boxShadow: focus ? '0 0 0 3px rgba(0,81,195,0.15)' : 'none', display: 'flex', alignItems: 'center', padding: '0 14px', fontSize: 17, fontFamily: mono ? MONO : SANS, filter: blur ? 'blur(6px)' : 'none' } }>{ value }</div>
	</div>
);

/** Typed text with a caret. */
export const typed = ( t: number, at: number, text: string, cps = 16 ) => {
	const n = Math.max( 0, Math.min( text.length, Math.floor( ( t - at ) * cps ) ) );
	const caret = t >= at && n < text.length + 1 && Math.floor( t * 2.4 ) % 2 === 0 && t < at + text.length / cps + 0.8;
	return text.slice( 0, n ) + ( caret ? '|' : '' );
};

/** Mouse pointer gliding between points (CSS px inside the parent). */
export const Pointer: React.FC<{ t: number; path: { at: number; x: number; y: number; click?: boolean }[] }> = ( { t, path } ) => {
	if ( ! path.length || t < path[ 0 ].at - 1 ) {
		return null;
	}
	let x = path[ 0 ].x + 140;
	let y = path[ 0 ].y + 160;
	let press = false;
	let ripple = -1;
	for ( const p of path ) {
		const m = rp( t, p.at - 0.8, p.at - 0.05 );
		x += ( p.x - x ) * m;
		y += ( p.y - y ) * m;
		if ( p.click && t >= p.at - 0.06 && t < p.at + 0.16 ) {
			press = true;
		}
		if ( p.click && t >= p.at && t < p.at + 0.6 ) {
			ripple = ( t - p.at ) / 0.6;
		}
	}
	const last = path[ path.length - 1 ].at;
	const op = rp( t, path[ 0 ].at - 1, path[ 0 ].at - 0.6 ) * ( 1 - rp( t, last + 0.9, last + 1.3 ) );
	return (
		<div style={ { position: 'absolute', left: x, top: y, opacity: op, transform: `scale(${ press ? 0.86 : 1 })`, transformOrigin: '0 0', zIndex: 50, pointerEvents: 'none' } }>
			{ ripple >= 0 ? <div style={ { position: 'absolute', left: -28 * ( 0.4 + ripple ), top: -28 * ( 0.4 + ripple ), width: 56 * ( 0.4 + ripple ), height: 56 * ( 0.4 + ripple ), borderRadius: '50%', border: '3px solid rgba(243,128,32,0.9)', opacity: 1 - ripple } } /> : null }
			<svg width={ 34 } height={ 34 } viewBox="0 0 24 24" style={ { filter: 'drop-shadow(0 3px 6px rgba(0,0,0,.45))' } }>
				<path d="M4 2 20 12l-7 1.5L9.5 21 4 2Z" fill="#111" stroke="#fff" strokeWidth={ 1.4 } />
			</svg>
		</div>
	);
};

/** Bangla side note card. */
export const Note: React.FC<{ t: number; at: number; icon?: 'check' | 'bolt' | 'shield' | 'lock' | 'globe' | 'cloud'; title: string; text?: string; tone?: 'ok' | 'warn' | 'info'; style?: React.CSSProperties }> = ( { t, at, icon = 'check', title, text, tone = 'info', style } ) => {
	const p = pop( t, at );
	const col = tone === 'ok' ? C.greenSoft : tone === 'warn' ? '#fbbf24' : C.sky;
	return (
		<div style={ { ...reveal( p, 26 ), background: 'linear-gradient(160deg, rgba(255,255,255,0.10), rgba(255,255,255,0.03))', border: `1px solid ${ col }55`, borderRadius: 20, padding: '20px 24px', display: 'flex', gap: 16, alignItems: 'flex-start', fontFamily: `${ BN }, ${ SANS }`, boxShadow: '0 20px 50px rgba(0,0,0,0.3)', ...style } }>
			<div style={ { width: 50, height: 50, borderRadius: 15, background: `${ col }22`, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 } }>
				<Icon name={ icon } size={ 28 } color={ col } stroke={ 2.2 } />
			</div>
			<div>
				<div style={ { color: C.white, fontSize: 28, fontWeight: 700, lineHeight: 1.3 } }>{ title }</div>
				{ text ? <div style={ { color: C.muted, fontSize: 22, marginTop: 4, lineHeight: 1.4 } }>{ text }</div> : null }
			</div>
		</div>
	);
};
