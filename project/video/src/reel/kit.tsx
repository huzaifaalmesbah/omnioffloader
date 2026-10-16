import React from 'react';
import { AbsoluteFill, Audio, Img, Sequence, staticFile, interpolate, Easing } from 'remotion';
import { createAvatar } from '@dicebear/core';
import * as avataaars from '@dicebear/avataaars';
import { SANS, BN, MONO } from '../theme';
import { FPS, LEAD, SEN, S, Tone } from './timing';

/* ------------------------------------------------------------------ */
/* Look                                                                 */
/* ------------------------------------------------------------------ */
export const FONT = `${ SANS }, ${ BN }`;
export { MONO };
export const K = {
	ink: '#0f1f3d',
	sub: '#5b6b85',
	line: '#e3e9f3',
	brand: '#1a5fd6',
	brandBg: '#e8f0fc',
	deep: '#0e3f9e',
	sky: '#3b86ee',
	skyBg: '#eaf3ff',
	red: '#dc3b2f',
	green: '#16a34a',
	greenBg: '#e7f6ec',
	redBg: '#fdeceb',
	blue: '#135dbe',
	blueBg: '#e8f0fc',
	navy: '#050d1f',
};
/** Headline accent per tone: brand blue for the idea, red for a problem, green for a fix. */
export const TONE: Record<Tone, string> = { idea: K.brand, problem: K.red, fix: K.green, brand: K.deep };
/** OmniOffloader logo gradient. */
export const GRAD = 'linear-gradient(135deg, #3b86ee, #1a5fd6 50%, #0e3f9e)';

/** Headline top; the headline + visual zone block sits centred in the frame. */
export const HL_TOP = 370;
/** Visual zone under the headline: scenes are laid out in 1080×830 and scaled up. */
export const VZ_TOP = 590;
export const VZ_H = 830;
export const VZ_SCALE = 1.1;

/* ------------------------------------------------------------------ */
/* Motion                                                               */
/* ------------------------------------------------------------------ */
const SMOOTH = Easing.bezier( 0.16, 1, 0.3, 1 );
const clamp = { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' } as const;
/** 0→1 ease-out starting at `at`. */
export const pop = ( t: number, at: number, len = 0.6 ) => interpolate( t, [ at, at + len ], [ 0, 1 ], { ...clamp, easing: SMOOTH } );
/** 0→1 ease-in-out between a and b. */
export const rp = ( t: number, a: number, b: number ) => interpolate( t, [ a, Math.max( b, a + 0.01 ) ], [ 0, 1 ], { ...clamp, easing: Easing.inOut( Easing.cubic ) } );
/** Visible between a and b with soft edges. */
export const win = ( t: number, a: number, b: number, f = 0.35 ) => rp( t, a - f / 2, a + f / 2 ) * ( 1 - rp( t, b - f / 2, b + f / 2 ) );
/** Spring-ish overshoot scale for pop-ins. */
export const springScale = ( p: number ) => 0.86 + 0.14 * p + 0.06 * Math.sin( p * Math.PI );
export const appear = ( p: number, dist = 26 ): React.CSSProperties => ( {
	opacity: Math.min( 1, p * 1.3 ),
	transform: `translateY(${ ( 1 - p ) * dist }px) scale(${ springScale( p ) })`,
} );
/** Small decaying wobble after `at` (emotion beats). */
export const bump = ( t: number, at: number ) => ( t < at ? 1 : 1 + 0.06 * Math.exp( -( t - at ) * 5 ) * Math.sin( ( t - at ) * 18 ) );

/* ------------------------------------------------------------------ */
/* Background: light canvas, two drifting blobs, a dark mood overlay    */
/* ------------------------------------------------------------------ */
export const darkness = ( t: number ) => {
	const a = S( '05-pain', 0 );
	const b = S( '06-product', 0 );
	return rp( t, a - 0.35, a + 0.25 ) * ( 1 - rp( t, b - 0.35, b + 0.2 ) );
};

export const Background: React.FC<{ t: number }> = ( { t } ) => {
	const d = darkness( t );
	return (
		<AbsoluteFill style={ { background: '#f4f7fc', overflow: 'hidden' } }>
			<div style={ { position: 'absolute', left: -330 + 40 * Math.sin( t * 0.25 ), top: -300 + 30 * Math.cos( t * 0.2 ), width: 1100, height: 1100, borderRadius: '50%', background: 'radial-gradient(circle, rgba(59,134,238,0.26), rgba(59,134,238,0) 66%)' } } />
			<div style={ { position: 'absolute', left: 330 + 35 * Math.cos( t * 0.22 ), top: 980 + 40 * Math.sin( t * 0.18 ), width: 1200, height: 1200, borderRadius: '50%', background: 'radial-gradient(circle, rgba(124,180,255,0.34), rgba(124,180,255,0) 64%)' } } />
			<AbsoluteFill style={ { opacity: d, background: 'radial-gradient(ellipse at 50% 30%, #123a86 0%, #071531 55%, #040a1a 100%)' } } />
		</AbsoluteFill>
	);
};

/* ------------------------------------------------------------------ */
/* Headline: one per sentence, key word coloured                        */
/* ------------------------------------------------------------------ */
const HeadText: React.FC<{ i: number; dark: number }> = ( { i, dark } ) => {
	const { headline, accent, tone } = SEN[ i ].beat;
	const at = accent ? headline.indexOf( accent ) : -1;
	const ink = interpolateColor( dark );
	const parts = at < 0 ? [ headline, '', '' ] : [ headline.slice( 0, at ), accent, headline.slice( at + accent.length ) ];
	const accentStyle: React.CSSProperties =
		tone === 'brand'
			? { background: 'linear-gradient(90deg, #3b86ee, #0e3f9e)', WebkitBackgroundClip: 'text', backgroundClip: 'text', color: 'transparent' }
			: { color: dark > 0.5 ? ( { idea: '#7cb4ff', problem: '#ff6b5e', fix: '#4ade80', brand: '#7cb4ff' } as const )[ tone ] : TONE[ tone ] };
	return (
		<span style={ { color: ink } }>
			{ colorTicks( parts[ 0 ] ) }
			<span style={ accentStyle }>{ parts[ 1 ] }</span>
			{ colorTicks( parts[ 2 ] ) }
		</span>
	);
};
const colorTicks = ( s: string ) =>
	s.split( /(✓)/ ).map( ( x, i ) => ( x === '✓' ? <span key={ i } style={ { color: K.green } }>✓</span> : <React.Fragment key={ i }>{ x }</React.Fragment> ) );
const interpolateColor = ( d: number ) => {
	const a = [ 0x0f, 0x1f, 0x3d ];
	const b = [ 0xff, 0xff, 0xff ];
	return `rgb(${ a.map( ( v, i ) => Math.round( v + ( b[ i ] - v ) * d ) ).join( ',' ) })`;
};

export const Headline: React.FC<{ t: number }> = ( { t } ) => {
	let i = 0;
	for ( let j = 0; j < SEN.length; j++ ) {
		if ( t >= SEN[ j ].start - 0.12 ) {
			i = j;
		}
	}
	const start = i === 0 ? -LEAD - 1 : SEN[ i ].start - 0.12; // hook headline already in place on frame 0 (the thumbnail)
	const p = pop( t, start, 0.55 );
	const out = i > 0 ? 1 - rp( t, start - 0.02, start + 0.22 ) : 0;
	const dark = darkness( t );
	const box: React.CSSProperties = { position: 'absolute', left: 70, right: 70, top: HL_TOP, textAlign: 'center', fontFamily: FONT, fontWeight: 700, fontSize: 76, lineHeight: 1.28, letterSpacing: -0.5 };
	return (
		<>
			{ out > 0 ? (
				<div style={ { ...box, opacity: out, transform: `translateY(${ -( 1 - out ) * 30 }px)`, filter: `blur(${ ( 1 - out ) * 6 }px)` } }>
					<HeadText i={ i - 1 } dark={ dark } />
				</div>
			) : null }
			<div style={ { ...box, opacity: p, transform: `translateY(${ ( 1 - p ) * 34 }px) scale(${ 0.96 + 0.04 * p })`, filter: p < 1 ? `blur(${ ( 1 - p ) * 8 }px)` : undefined } }>
				<HeadText i={ i } dark={ dark } />
			</div>
		</>
	);
};

/* ------------------------------------------------------------------ */
/* Building blocks                                                      */
/* ------------------------------------------------------------------ */
type Pos = { x: number; y: number; w?: number; h?: number };
export const Card: React.FC<Pos & { p?: number; style?: React.CSSProperties; children?: React.ReactNode }> = ( { x, y, w, h, p = 1, style, children } ) => (
	<div style={ { position: 'absolute', left: x, top: y, width: w, height: h, background: '#fff', borderRadius: 30, boxShadow: '0 24px 60px rgba(15,31,61,0.10), 0 2px 6px rgba(15,31,61,0.05)', fontFamily: FONT, color: K.ink, ...appear( p ), ...style } }>
		{ children }
	</div>
);

export const Tile: React.FC<{ icon: GlyphName; color: string; bg: string; size?: number; style?: React.CSSProperties }> = ( { icon, color, bg, size = 88, style } ) => (
	<div style={ { width: size, height: size, borderRadius: size * 0.28, background: bg, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0, ...style } }>
		<Glyph name={ icon } size={ size * 0.52 } color={ color } stroke={ 2 } />
	</div>
);

export const Pill: React.FC<{ color: string; bg: string; size?: number; icon?: GlyphName; style?: React.CSSProperties; children: React.ReactNode }> = ( { color, bg, size = 30, icon, style, children } ) => (
	<div style={ { display: 'inline-flex', alignItems: 'center', gap: size * 0.3, padding: `${ size * 0.3 }px ${ size * 0.65 }px`, borderRadius: 999, background: bg, color, fontFamily: FONT, fontWeight: 700, fontSize: size, whiteSpace: 'nowrap', ...style } }>
		{ icon ? <Glyph name={ icon } size={ size * 1.05 } color={ color } stroke={ 2.4 } /> : null }
		{ children }
	</div>
);

/** Big icon in a circle — the "wait a moment" overlay. */
export const BigCircle: React.FC<{ icon: GlyphName; color: string; p: number; size?: number; x?: number; y?: number }> = ( { icon, color, p, size = 230, x = 540, y = 200 } ) => (
	<div style={ { position: 'absolute', left: x - size / 2, top: y - size / 2, width: size, height: size, borderRadius: '50%', background: color, boxShadow: `0 0 0 22px ${ color }33, 0 30px 70px ${ color }55`, display: 'flex', alignItems: 'center', justifyContent: 'center', opacity: Math.min( 1, p * 1.5 ), transform: `scale(${ 0.6 + 0.4 * p + 0.08 * Math.sin( p * Math.PI ) })` } }>
		<Glyph name={ icon } size={ size * 0.46 } color="#fff" stroke={ 2.2 } />
	</div>
);

/** Dotted line from a to b with `n` dots travelling along it. */
export const Connector: React.FC<{ a: [ number, number ]; b: [ number, number ]; t: number; p?: number; color?: string; speed?: number; n?: number; dots?: boolean; dot?: number; width?: number }> = ( {
	a, b, t, p = 1, color = '#9aa3b5', speed = 0.5, n = 3, dots = true, dot = 14, width = 5,
} ) => {
	const L = Math.hypot( b[ 0 ] - a[ 0 ], b[ 1 ] - a[ 1 ] );
	return (
		<svg style={ { position: 'absolute', left: 0, top: 0, overflow: 'visible', pointerEvents: 'none' } } width={ 1 } height={ 1 }>
			<line x1={ a[ 0 ] } y1={ a[ 1 ] } x2={ a[ 0 ] + ( b[ 0 ] - a[ 0 ] ) * p } y2={ a[ 1 ] + ( b[ 1 ] - a[ 1 ] ) * p } stroke={ color } strokeWidth={ width } strokeDasharray="3 14" strokeLinecap="round" opacity={ 0.9 } />
			{ dots && p >= 1
				? Array.from( { length: n } ).map( ( _, i ) => {
					const f = ( ( t * speed * ( 600 / Math.max( L, 1 ) ) ) + i / n ) % 1;
					return <circle key={ i } cx={ a[ 0 ] + ( b[ 0 ] - a[ 0 ] ) * f } cy={ a[ 1 ] + ( b[ 1 ] - a[ 1 ] ) * f } r={ dot / 2 } fill={ color } opacity={ Math.sin( f * Math.PI ) } />;
				} )
				: null }
		</svg>
	);
};

/** Image tiles travelling along a straight path (files moving). */
export const Flow: React.FC<{ a: [ number, number ]; b: [ number, number ]; t: number; from: number; rate: number; dur: number; color: string; size?: number; until?: number }> = ( { a, b, t, from, rate, dur, color, size = 46, until = 1e9 } ) => {
	if ( t < from ) {
		return null;
	}
	const items = [];
	const first = Math.max( 0, Math.floor( ( t - dur - from ) * rate ) );
	const last = Math.floor( ( Math.min( t, until ) - from ) * rate );
	for ( let i = first; i <= last; i++ ) {
		const t0 = from + i / rate;
		const f = ( t - t0 ) / dur;
		if ( f < 0 || f > 1 ) {
			continue;
		}
		const e = Easing.inOut( Easing.quad )( f );
		items.push(
			<div key={ i } style={ { position: 'absolute', left: a[ 0 ] + ( b[ 0 ] - a[ 0 ] ) * e - size / 2, top: a[ 1 ] + ( b[ 1 ] - a[ 1 ] ) * e - size / 2, width: size, height: size, borderRadius: 10, background: color, boxShadow: '0 6px 14px rgba(0,0,0,0.12)', display: 'flex', alignItems: 'center', justifyContent: 'center', opacity: Math.min( 1, Math.sin( f * Math.PI ) * 3 ) } }>
				<Glyph name="image" size={ size * 0.6 } color="#fff" stroke={ 2.2 } />
			</div>
		);
	}
	return <>{ items }</>;
};

/** Mouse cursor moving through keyframes [time, x, y]; clicks at the listed times. */
export const Cursor: React.FC<{ t: number; keys: [ number, number, number ][]; clicks?: number[]; p?: number }> = ( { t, keys, clicks = [], p = 1 } ) => {
	const times = keys.map( ( k ) => k[ 0 ] );
	const ease = { ...clamp, easing: Easing.inOut( Easing.cubic ) };
	const x = keys.length > 1 ? interpolate( t, times, keys.map( ( k ) => k[ 1 ] ), ease ) : keys[ 0 ][ 1 ];
	const y = keys.length > 1 ? interpolate( t, times, keys.map( ( k ) => k[ 2 ] ), ease ) : keys[ 0 ][ 2 ];
	const c = clicks.find( ( at ) => t >= at && t < at + 0.5 );
	const cp = c === undefined ? 1 : ( t - c ) / 0.5;
	const press = c === undefined ? 1 : 0.85 + 0.15 * Math.min( 1, cp * 3 );
	return (
		<div style={ { position: 'absolute', left: x, top: y, opacity: p } }>
			{ c !== undefined ? <div style={ { position: 'absolute', left: -40 * cp, top: -40 * cp, width: 80 * cp, height: 80 * cp, borderRadius: '50%', border: `4px solid ${ K.blue }`, opacity: 1 - cp } } /> : null }
			<svg width="54" height="54" viewBox="0 0 24 24" style={ { transform: `scale(${ press })`, transformOrigin: '0 0', filter: 'drop-shadow(0 4px 6px rgba(0,0,0,0.3))' } }>
				<path d="M4 2.5 19 13l-6.6 1.2L16 21l-3 1.4-3.6-6.9L4.5 20Z" fill="#111" stroke="#fff" strokeWidth="1.4" strokeLinejoin="round" />
			</svg>
		</div>
	);
};

/** Browser window showing a crop of a real screenshot (2880 px wide captures). */
export const BrowserFrame: React.FC<Pos & { p?: number; src: string; url: string; crop: { x: number; y: number; w: number }; style?: React.CSSProperties }> = ( { x, y, w = 900, h = 520, p = 1, src, url, crop, style } ) => {
	const scale = w / crop.w;
	return (
		<Card x={ x } y={ y } w={ w } h={ h } p={ p } style={ { overflow: 'hidden', borderRadius: 26, ...style } }>
			<div style={ { height: 56, background: '#f1f3f7', borderBottom: `1px solid ${ K.line }`, display: 'flex', alignItems: 'center', gap: 10, padding: '0 22px' } }>
				{ [ '#ff5f57', '#febc2e', '#28c840' ].map( ( c ) => <div key={ c } style={ { width: 14, height: 14, borderRadius: 7, background: c } } /> ) }
				<div style={ { marginLeft: 16, flex: 1, height: 34, borderRadius: 10, background: '#fff', display: 'flex', alignItems: 'center', padding: '0 16px', fontFamily: SANS, fontSize: 20, color: K.sub } }>{ url }</div>
			</div>
			<div style={ { position: 'relative', width: w, height: h - 56, overflow: 'hidden' } }>
				<Img src={ staticFile( src ) } style={ { position: 'absolute', width: 2880 * scale, left: -crop.x * scale, top: -crop.y * scale } } />
			</div>
		</Card>
	);
};

/** One-shot sound effect at narration time `at`. */
export const Sfx: React.FC<{ at: number; name: 'click' | 'whoosh' | 'pop'; volume?: number }> = ( { at, name, volume = 0.3 } ) => (
	<Sequence from={ Math.max( 0, Math.round( ( at + LEAD ) * FPS ) ) } durationInFrames={ Math.round( FPS * 1.2 ) } layout="none">
		<Audio src={ staticFile( `sfx/${ name }.wav` ) } volume={ volume } />
	</Sequence>
);
export type Cue = { at: number; name: 'click' | 'whoosh' | 'pop'; volume?: number };

/* ------------------------------------------------------------------ */
/* Icons (24×24 stroke)                                                 */
/* ------------------------------------------------------------------ */
const G = {
	cloud: <path d="M7 19h10.5a4.5 4.5 0 0 0 .7-8.95A6.5 6.5 0 0 0 5.8 9.1 5 5 0 0 0 7 19Z" />,
	server: (
		<>
			<rect x="3.5" y="3.5" width="17" height="7" rx="2" />
			<rect x="3.5" y="13.5" width="17" height="7" rx="2" />
			<path d="M7.5 7h.01M7.5 17h.01M11 7h5M11 17h5" />
		</>
	),
	image: (
		<>
			<rect x="3" y="4" width="18" height="16" rx="3" />
			<circle cx="8.5" cy="9.5" r="1.6" />
			<path d="m21 16-5-5-9.5 9" />
		</>
	),
	check: <path d="M5 12.5 10 17.5 19.5 7" />,
	x: <path d="M6.5 6.5l11 11M17.5 6.5l-11 11" />,
	question: (
		<>
			<path d="M9 9a3 3 0 1 1 4.2 2.75c-.75.35-1.2.95-1.2 1.75V14.5" />
			<path d="M12 18.5h.01" />
		</>
	),
	store: <path d="M3.5 9 5 4h14l1.5 5M3.5 9h17M3.5 9c0 1.7 1.3 3 3 3s3-1.3 3-3c0 1.7 1.3 3 3 3s3-1.3 3-3c0 1.7 1.3 3 2.5 3M5 12v8h14v-8M10 20v-5h4v5" />,
	globe: (
		<>
			<circle cx="12" cy="12" r="9" />
			<path d="M3 12h18M12 3c2.5 2.8 3.5 5.8 3.5 9s-1 6.2-3.5 9c-2.5-2.8-3.5-5.8-3.5-9s1-6.2 3.5-9Z" />
		</>
	),
	upload: <path d="M12 15.5V4m0 0L7.5 8.5M12 4l4.5 4.5M4 15v3.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V15" />,
	download: <path d="M12 4v11.5m0 0-4.5-4.5m4.5 4.5 4.5-4.5M4 15v3.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V15" />,
	link: <path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1" />,
	trash: <path d="M4 7h16M9.5 7V4.5h5V7M6.5 7l1 13h9l1-13M10 11v5M14 11v5" />,
	disk: (
		<>
			<rect x="3" y="6" width="18" height="12" rx="2.5" />
			<path d="M7 14h.01M11 14h6" />
		</>
	),
	bolt: <path d="M13 3 5 13.5h6L10 21l8-10.5h-6L13 3Z" />,
	weight: <path d="M6.5 9h11l2.5 11H4L6.5 9ZM9.5 9a2.5 2.5 0 1 1 5 0" />,
	comment: <path d="M4 5.5h16v11H9.5L5 20v-3.5H4z" />,
	user: (
		<>
			<circle cx="12" cy="8" r="4" />
			<path d="M4.5 21c1-4 4-6 7.5-6s6.5 2 7.5 6" />
		</>
	),
	userPlus: (
		<>
			<circle cx="10" cy="8" r="4" />
			<path d="M3 21c.9-4 3.6-6 7-6 1.5 0 2.9.4 4 1.1M18.5 14v6M15.5 17h6" />
		</>
	),
	share: <path d="M14 5l6 6-6 6M20 11H10a6 6 0 0 0-6 6v2" />,
	pin: <path d="M12 16.5V22M8 3h8l-1 6 3 3v2.5H6V12l3-3-1-6Z" />,
	code: <path d="m8.5 7.5-5 4.5 5 4.5M15.5 7.5l5 4.5-5 4.5" />,
	wp: (
		<>
			<circle cx="12" cy="12" r="9" />
			<path d="M5.5 8.5 9.5 19l2.5-7 2.5 7 4-10.5M4.5 8.5h3M10 8.5h3.5" />
		</>
	),
	refresh: <path d="M20 11a8 8 0 0 0-14.6-4.5L4 8m0-4v4h4m-4 5a8 8 0 0 0 14.6 4.5L20 16m0 4v-4h-4" />,
	clock: (
		<>
			<circle cx="12" cy="12" r="9" />
			<path d="M12 7v5l3 2" />
		</>
	),
	shield: <path d="M12 3 5 6v5.5c0 4.3 3 7.9 7 9.5 4-1.6 7-5.2 7-9.5V6l-7-3ZM9 12l2 2 4-4" />,
	arrowRight: <path d="M4 12h15m0 0-6-6m6 6-6 6" />,
	file: <path d="M6 3h8l4 4v14H6V3ZM14 3v4h4" />,
	sparkle: <path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5 18 18M6 18l2.5-2.5M15.5 8.5 18 6" />,
};
export type GlyphName = keyof typeof G;
export const Glyph: React.FC<{ name: GlyphName; size?: number; color?: string; stroke?: number; style?: React.CSSProperties }> = ( { name, size = 32, color = 'currentColor', stroke = 2, style } ) => (
	<svg width={ size } height={ size } viewBox="0 0 24 24" fill="none" stroke={ color } strokeWidth={ stroke } strokeLinecap="round" strokeLinejoin="round" style={ style }>
		{ G[ name ] }
	</svg>
);

export const Logo: React.FC<{ size: number; style?: React.CSSProperties }> = ( { size, style } ) => (
	<Img src={ staticFile( 'icon.svg' ) } style={ { width: size, height: size, ...style } } />
);

/* ------------------------------------------------------------------ */
/* Character: Avataaars bust (DiceBear; design by Pablo Stanley, free   */
/* for personal and commercial use) with mood-driven expressions        */
/* ------------------------------------------------------------------ */
export type Mood = 'happy' | 'worried' | 'neutral' | 'thinking';
const FACE: Record<Mood, Partial<avataaars.Options>> = {
	happy: { eyes: [ 'happy' ], eyebrows: [ 'defaultNatural' ], mouth: [ 'smile' ] },
	worried: { eyes: [ 'default' ], eyebrows: [ 'sadConcernedNatural' ], mouth: [ 'sad' ] },
	neutral: { eyes: [ 'default' ], eyebrows: [ 'defaultNatural' ], mouth: [ 'default' ] },
	thinking: { eyes: [ 'side' ], eyebrows: [ 'raisedExcitedNatural' ], mouth: [ 'serious' ] },
};
const avatarCache = new Map<string, string>();
const avatarUri = ( mood: Mood, shirt: string ) => {
	const key = `${ mood }${ shirt }`;
	let uri = avatarCache.get( key );
	if ( ! uri ) {
		const svg = createAvatar( avataaars, {
			seed: 'omni',
			top: [ 'shortCurly' ],
			hairColor: [ '2c1b18' ],
			facialHair: [ 'beardLight' ],
			facialHairProbability: 100,
			facialHairColor: [ '2c1b18' ],
			accessoriesProbability: 0,
			clothing: [ 'shirtCrewNeck' ],
			clothesColor: [ shirt.replace( '#', '' ) ],
			skinColor: [ 'd08b5b' ],
			...FACE[ mood ],
		} ).toString();
		uri = `data:image/svg+xml;utf8,${ encodeURIComponent( svg ) }`;
		avatarCache.set( key, uri );
	}
	return uri;
};

export const Character: React.FC<{ x: number; y: number; size?: number; mood: Mood; shirt: string; label?: string; p?: number; scale?: number }> = ( { x, y, size = 300, mood, shirt, label, p = 1, scale = 1 } ) => (
	<div style={ { position: 'absolute', left: x - size / 2, top: y, width: size, height: size, opacity: Math.min( 1, p * 1.4 ), transform: `translateY(${ ( 1 - p ) * 40 }px) scale(${ scale })`, transformOrigin: '50% 100%' } }>
		<Img src={ avatarUri( mood, shirt ) } style={ { width: size, height: size, WebkitMaskImage: 'linear-gradient(to bottom, #000 72%, transparent 100%)', maskImage: 'linear-gradient(to bottom, #000 72%, transparent 100%)' } } />
		{ mood === 'worried' ? (
			<svg width={ size * 0.09 } height={ size * 0.13 } viewBox="0 0 20 28" style={ { position: 'absolute', left: size * 0.66, top: size * 0.2 } }>
				<path d="M10 0C3 11 2 18 2 20a8 8 0 0 0 16 0c0-2-1-9-8-20Z" fill="#7cc4ff" />
			</svg>
		) : null }
		{ label ? (
			<div style={ { position: 'absolute', left: '50%', bottom: size * 0.02, transform: 'translateX(-50%)', padding: '6px 20px', borderRadius: 12, background: '#fff', boxShadow: '0 6px 16px rgba(15,31,61,0.12)', fontFamily: FONT, fontWeight: 700, fontSize: 26, color: shirt, whiteSpace: 'nowrap' } }>{ label }</div>
		) : null }
	</div>
);

/** Thought / speech bubble. */
export const Bubble: React.FC<{ x: number; y: number; p: number; children: React.ReactNode; tail?: 'left' | 'right' }> = ( { x, y, p, children, tail = 'left' } ) => (
	<div style={ { position: 'absolute', left: x, top: y, ...appear( p, 16 ) } }>
		<div style={ { position: 'relative', padding: '18px 28px', borderRadius: 26, background: '#fff', boxShadow: '0 14px 34px rgba(15,31,61,0.12)', fontFamily: FONT, fontWeight: 700, fontSize: 32, color: K.ink, whiteSpace: 'nowrap' } }>
			{ children }
			<div style={ { position: 'absolute', bottom: -12, [ tail ]: 34, width: 26, height: 26, background: '#fff', transform: 'rotate(45deg)', borderRadius: 4 } } />
		</div>
	</div>
);
