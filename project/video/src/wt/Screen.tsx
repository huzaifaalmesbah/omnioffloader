import React from 'react';
import { Img, staticFile, Easing, interpolate } from 'remotion';
import meta from '../wt-meta.json';
import { C, SANS } from '../theme';
import { Icon } from '../components';

export type Rect = [ number, number, number, number ];
/** A rectangle: "shot:name" from wt-meta.json, or explicit image coordinates. */
export type RectRef = string | Rect;

type Meta = Record<string, { w: number; h: number; rects: Record<string, number[]> }>;
const M = meta as Meta;

export const rectOf = ( ref: RectRef ): Rect => {
	if ( Array.isArray( ref ) ) {
		return ref;
	}
	const [ shot, name ] = ref.split( ':' );
	const r = M[ shot ]?.rects[ name ];
	if ( ! r ) {
		throw new Error( `No rect ${ ref }` );
	}
	return r as Rect;
};

export interface ShotKey { src: string; at: number }
export interface CamKey { at: number; s: number; rect?: RectRef; cx?: number; cy?: number; dur?: number }
export interface MarkSpec { at: number; until?: number; rect: RectRef; pad?: number; label?: string; pos?: 'top' | 'bottom' | 'left' | 'right'; spot?: boolean }
export interface ClickSpec { at: number; rect: RectRef; fx?: number; fy?: number }
export interface TypeSpec { at: number; dur: number; rect: RectRef; text: string }
export interface CoverSpec { rect: RectRef; until: number; color?: string }

const VW = 1440;
const VH = 900;
const ease = Easing.inOut( Easing.cubic );
const ramp = ( t: number, a: number, b: number ) => interpolate( t, [ a, b ], [ 0, 1 ], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: ease } );

const center = ( k: CamKey ): [ number, number ] => {
	if ( k.rect ) {
		const [ x, y, w, h ] = rectOf( k.rect );
		return [ x + w / 2, y + h / 2 ];
	}
	return [ k.cx ?? VW / 2, k.cy ?? VH / 2 ];
};

/** The visible window (left, top, scale) at time t, easing between keys. */
const camera = ( keys: CamKey[], t: number, imgH: number ) => {
	let [ cx, cy ] = center( keys[ 0 ] );
	let s = keys[ 0 ].s;
	for ( const k of keys.slice( 1 ) ) {
		if ( t < k.at ) {
			break;
		}
		const p = ramp( t, k.at, k.at + ( k.dur ?? 1.0 ) );
		const [ nx, ny ] = center( k );
		cx += ( nx - cx ) * p;
		cy += ( ny - cy ) * p;
		s += ( k.s - s ) * p;
	}
	const vw = VW / s;
	const vh = VH / s;
	const left = Math.max( 0, Math.min( VW - vw, cx - vw / 2 ) );
	const top = Math.max( 0, Math.min( imgH - vh, cy - vh / 2 ) );
	return { left, top, s };
};

export const Screen: React.FC<{
	t: number;
	shots: ShotKey[];
	cam?: CamKey[];
	marks?: MarkSpec[];
	clicks?: ClickSpec[];
	typing?: TypeSpec[];
	covers?: CoverSpec[];
	/** Extra drawing in image coordinates (1440-wide page space). */
	layer?: React.ReactNode;
	url: string;
	x?: number;
	y?: number;
	width?: number;
	appear?: number;
}> = ( { t, shots, cam = [ { at: 0, s: 1 } ], marks = [], clicks = [], typing = [], covers = [], layer, url, x = 50, y = 96, width = 1220, appear = 1 } ) => {
	const k = width / VW;
	const active = [ ...shots ].reverse().find( ( s ) => t >= s.at ) ?? shots[ 0 ];
	const imgH = M[ active.src ]?.h ?? VH;
	const { left, top, s } = camera( cam, t, imgH );

	// Cursor: glides to each click target, presses on time.
	let cursor: { x: number; y: number; press: number; ripple: number; op: number } | null = null;
	if ( clicks.length ) {
		const pts = clicks.map( ( c ) => {
			const [ rx, ry, rw, rh ] = rectOf( c.rect );
			return { at: c.at, x: rx + rw * ( c.fx ?? 0.5 ), y: ry + rh * ( c.fy ?? 0.5 ) };
		} );
		let px = VW * 0.62;
		let py = VH * 0.92;
		let press = 0;
		let ripple = -1;
		for ( const p of pts ) {
			const m = ramp( t, p.at - 0.9, p.at - 0.05 );
			px += ( p.x - px ) * m;
			py += ( p.y - py ) * m;
			if ( t >= p.at - 0.08 && t < p.at + 0.18 ) {
				press = 1;
			}
			if ( t >= p.at && t < p.at + 0.6 ) {
				ripple = ( t - p.at ) / 0.6;
			}
		}
		const op = ramp( t, pts[ 0 ].at - 1.4, pts[ 0 ].at - 0.9 ) * ( 1 - ramp( t, pts[ pts.length - 1 ].at + 0.8, pts[ pts.length - 1 ].at + 1.2 ) );
		cursor = { x: px, y: py, press, ripple, op };
	}

	return (
		<div
			style={ {
				position: 'absolute',
				left: x,
				top: y,
				width,
				borderRadius: 16,
				overflow: 'hidden',
				background: '#fff',
				boxShadow: '0 40px 100px rgba(0,0,0,0.55), 0 0 0 1px rgba(255,255,255,0.14)',
				opacity: appear,
				transform: `translateY(${ ( 1 - appear ) * 40 }px) scale(${ 0.97 + 0.03 * appear })`,
			} }
		>
			<div style={ { height: 44, background: '#eef1f6', display: 'flex', alignItems: 'center', padding: '0 18px', gap: 9 } }>
				{ [ '#ff5f57', '#febc2e', '#28c840' ].map( ( c ) => (
					<div key={ c } style={ { width: 13, height: 13, borderRadius: 7, background: c } } />
				) ) }
				<div style={ { marginLeft: 20, flex: 1, height: 26, borderRadius: 8, background: '#fff', fontFamily: SANS, fontSize: 14, color: '#556', display: 'flex', alignItems: 'center', paddingLeft: 14 } }>
					<Icon name="lock" size={ 13 } color="#889" style={ { marginRight: 8 } } />
					{ url }
				</div>
			</div>
			<div style={ { width, height: VH * k, overflow: 'hidden', position: 'relative' } }>
				<div style={ { position: 'absolute', left: 0, top: 0, width: VW, height: imgH, transformOrigin: '0 0', transform: `scale(${ k })` } }>
					<div style={ { position: 'absolute', left: 0, top: 0, width: VW, height: imgH, transformOrigin: '0 0', transform: `scale(${ s }) translate(${ -left }px, ${ -top }px)` } }>
						{ shots.map( ( sh, i ) => {
							const op = i === 0 ? 1 : ramp( t, sh.at, sh.at + 0.35 );
							const next = shots[ i + 1 ];
							const hidden = next && t > next.at + 0.4;
							if ( op <= 0 || hidden ) {
								return null;
							}
							return <Img key={ sh.src + i } src={ staticFile( `wt/${ sh.src }.png` ) } style={ { position: 'absolute', left: 0, top: 0, width: VW, opacity: op } } />;
						} ) }
						{ covers.map( ( c, i ) => {
							const op = 1 - ramp( t, c.until, c.until + 0.3 );
							if ( op <= 0 ) {
								return null;
							}
							const [ cx, cy, cw, ch ] = rectOf( c.rect );
							return <div key={ `c${ i }` } style={ { position: 'absolute', left: cx, top: cy, width: cw, height: ch, background: c.color ?? '#fff', opacity: op } } />;
						} ) }
						{ layer }
						{ typing.map( ( ty, i ) => {
							if ( t < ty.at - 0.6 ) {
								return null;
							}
							const [ rx, ry, rw, rh ] = rectOf( ty.rect );
							const n = Math.round( interpolate( t, [ ty.at, ty.at + ty.dur ], [ 0, ty.text.length ], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' } ) );
							const blink = Math.floor( t * 2.4 ) % 2 === 0 && t < ty.at + ty.dur + 0.6;
							return (
								<div key={ i } style={ { position: 'absolute', left: rx + 2, top: ry + 2, width: rw - 4, height: rh - 4, background: '#fff', display: 'flex', alignItems: 'center', paddingLeft: 8, fontFamily: '-apple-system, "Segoe UI", Roboto, sans-serif', fontSize: 14, color: '#1e1e1e', boxShadow: '0 0 0 2px #2271b1', borderRadius: 3 } }>
									{ ty.text.slice( 0, n ) }
									<span style={ { width: 1.5, height: 17, background: blink ? '#1e1e1e' : 'transparent', marginLeft: 1 } } />
								</div>
							);
						} ) }
						{ marks.map( ( m, i ) => {
							const until = m.until ?? 1e9;
							const p = ramp( t, m.at, m.at + 0.45 ) * ( 1 - ramp( t, until, until + 0.4 ) );
							if ( p <= 0 ) {
								return null;
							}
							const [ rx, ry, rw, rh ] = rectOf( m.rect );
							const pad = m.pad ?? 8;
							const pulse = 0.5 + 0.5 * Math.sin( t * 4 );
							const pos = m.pos ?? 'top';
							const inv = 1 / s;
							return (
								<div key={ i } style={ { position: 'absolute', left: rx - pad, top: ry - pad, width: rw + pad * 2, height: rh + pad * 2, opacity: p, transform: `scale(${ 1.06 - 0.06 * p })` } }>
									<div
										style={ {
											position: 'absolute',
											inset: 0,
											borderRadius: 12,
											border: `${ 3 * inv }px solid ${ C.blueLight }`,
											boxShadow: `${ m.spot ? `0 0 0 4000px rgba(8,16,36,${ 0.42 * p }), ` : '' }0 0 ${ 18 + 10 * pulse }px rgba(59,134,238,0.85)`,
										} }
									/>
									{ m.label ? (
										<div
											style={ {
												position: 'absolute',
												...( pos === 'top' ? { bottom: '100%', left: 0, marginBottom: 10 * inv } : pos === 'bottom' ? { top: '100%', left: 0, marginTop: 10 * inv } : pos === 'left' ? { right: '100%', top: 0, marginRight: 12 * inv } : { left: '100%', top: 0, marginLeft: 12 * inv } ),
												whiteSpace: 'nowrap',
												background: C.blue,
												color: '#fff',
												fontFamily: SANS,
												fontWeight: 700,
												fontSize: 21 * inv,
												padding: `${ 7 * inv }px ${ 14 * inv }px`,
												borderRadius: 9 * inv,
												boxShadow: '0 8px 24px rgba(0,0,0,0.3)',
											} }
										>
											{ m.label }
										</div>
									) : null }
								</div>
							);
						} ) }
						{ cursor && cursor.op > 0 ? (
							<div style={ { position: 'absolute', left: cursor.x, top: cursor.y, opacity: cursor.op, transform: `scale(${ ( 1 / s ) * ( cursor.press ? 0.86 : 1 ) })`, transformOrigin: '0 0' } }>
								{ cursor.ripple >= 0 ? (
									<div style={ { position: 'absolute', left: -30 * ( 0.4 + cursor.ripple ), top: -30 * ( 0.4 + cursor.ripple ), width: 60 * ( 0.4 + cursor.ripple ), height: 60 * ( 0.4 + cursor.ripple ), borderRadius: '50%', border: '3px solid rgba(59,134,238,0.9)', opacity: 1 - cursor.ripple } } />
								) : null }
								<svg width={ 34 } height={ 34 } viewBox="0 0 24 24" style={ { filter: 'drop-shadow(0 3px 6px rgba(0,0,0,.45))' } }>
									<path d="M4 2 20 12l-7 1.5L9.5 21 4 2Z" fill="#111" stroke="#fff" strokeWidth={ 1.4 } />
								</svg>
							</div>
						) : null }
					</div>
				</div>
			</div>
		</div>
	);
};
