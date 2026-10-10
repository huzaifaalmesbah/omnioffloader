import React from 'react';
import { interpolate, Easing } from 'remotion';
import timing from '../wt-timing.json';
import { C, SANS, MONO } from '../theme';
import { Icon } from '../components';

type Scene = { id: string; duration: number; words: [ string, number ][] };
const T = timing as unknown as Scene[];

const norm = ( s: string ) => s.toLowerCase().replace( /[^a-z0-9]/g, '' );

/** Time (s) the narrator says `word` (its nth occurrence) in scene `id`. */
export const wordTime = ( id: string, word: string, nth = 1 ): number => {
	const scene = T.find( ( s ) => s.id === id );
	if ( ! scene ) {
		throw new Error( `No scene ${ id }` );
	}
	const target = norm( word );
	let seen = 0;
	for ( const [ w, at ] of scene.words ) {
		if ( norm( w ) === target || ( target.length > 3 && norm( w ).startsWith( target ) ) ) {
			seen++;
			if ( seen === nth ) {
				return at;
			}
		}
	}
	throw new Error( `Word "${ word }" (#${ nth }) not in scene ${ id }` );
};
export const sceneDuration = ( id: string ) => T.find( ( s ) => s.id === id )?.duration ?? 0;

const ease = Easing.out( Easing.cubic );
const show = ( t: number, at: number ) => interpolate( t, [ at, at + 0.5 ], [ 0, 1 ], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: ease } );
const riseStyle = ( p: number, d = 24 ): React.CSSProperties => ( { opacity: p, transform: `translateY(${ ( 1 - p ) * d }px)` } );

export interface Bullet { at: number; text: React.ReactNode; icon?: 'check' | 'bolt' | 'shield' | 'cloud' }
export type CliLine = string | { text: string; at: number };
export interface CliBlock { at: number; title?: string; lines: CliLine[]; until?: number; prompt?: boolean }
export interface Tip { at: number; title: string; text: React.ReactNode }

export const Panel: React.FC<{ t: number; step: number; title: string; bullets?: Bullet[]; cli?: CliBlock[]; tip?: Tip; x?: number; y?: number; width?: number }> = ( { t, step, title, bullets = [], cli = [], tip, x = 1310, y = 96, width = 560 } ) => {
	const head = show( t, -0.4 );
	return (
		<div style={ { position: 'absolute', left: x, top: y, width, fontFamily: SANS } }>
			<div style={ { ...riseStyle( head ), color: C.sky, fontSize: 20, fontWeight: 700, letterSpacing: 3, textTransform: 'uppercase' } }>Step { String( step ).padStart( 2, '0' ) }</div>
			<div style={ { ...riseStyle( head, 30 ), color: C.white, fontSize: 46, fontWeight: 800, lineHeight: 1.1, letterSpacing: -1, marginTop: 10, marginBottom: 26 } }>{ title }</div>
			{ bullets.map( ( b, i ) => {
				const p = show( t, b.at );
				return (
					<div key={ i } style={ { ...riseStyle( p ), display: 'flex', gap: 14, alignItems: 'flex-start', marginBottom: 14 } }>
						<div style={ { width: 32, height: 32, borderRadius: 10, background: 'rgba(74,222,128,0.14)', border: '1px solid rgba(74,222,128,0.35)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0, marginTop: 1 } }>
							<Icon name={ b.icon ?? 'check' } size={ 19 } color={ C.greenSoft } stroke={ 2.4 } />
						</div>
						<div style={ { color: C.text, fontSize: 23, lineHeight: 1.35, fontWeight: 500 } }>{ b.text }</div>
					</div>
				);
			} ) }
			{ cli.map( ( c, i ) => {
				const p = show( t, c.at ) * ( c.until ? 1 - show( t, c.until ) : 1 );
				if ( p <= 0 ) {
					return null;
				}
				let budget = Math.max( 0, ( t - c.at ) * 70 );
				return (
					<div key={ i } style={ { ...riseStyle( p ), marginTop: 18, borderRadius: 14, overflow: 'hidden', border: '1px solid rgba(255,255,255,0.14)', boxShadow: '0 20px 50px rgba(0,0,0,0.4)' } }>
						<div style={ { background: '#1b2335', padding: '9px 16px', display: 'flex', alignItems: 'center', gap: 10, color: '#9fb0d0', fontSize: 16, fontWeight: 600 } }>
							<Icon name="terminal" size={ 18 } color={ C.sky } /> { c.title ?? 'WP-CLI' }
						</div>
						<div style={ { background: 'rgba(6,10,20,0.96)', padding: '14px 18px', fontFamily: MONO, fontSize: 17.5, lineHeight: 1.6, color: '#d6e2ff', whiteSpace: 'pre-wrap', wordBreak: 'break-word' } }>
							{ c.lines.map( ( line, j ) => {
								const l = typeof line === 'string' ? line : line.text;
								const comment = l.startsWith( '#' );
								let n: number;
								if ( typeof line === 'string' ) {
									n = Math.min( l.length, Math.floor( budget ) );
									budget -= l.length;
								} else {
									n = Math.min( l.length, Math.floor( Math.max( 0, ( t - line.at ) * 80 ) ) );
								}
								if ( n <= 0 ) {
									return <div key={ j } style={ { minHeight: 28 } } />;
								}
								return (
									<div key={ j } style={ { color: comment ? '#7f8fb0' : undefined, marginTop: comment && j ? 6 : 0 } }>
										{ comment || c.prompt === false ? null : <span style={ { color: C.greenSoft } }>$ </span> }
										{ l.slice( 0, n ) }
									</div>
								);
							} ) }
						</div>
					</div>
				);
			} ) }
			{ tip ? (
				<div style={ { ...riseStyle( show( t, tip.at ) ), marginTop: 18, borderRadius: 14, padding: '16px 20px', background: 'rgba(245,158,11,0.12)', border: '1px solid rgba(245,158,11,0.45)' } }>
					<div style={ { display: 'flex', alignItems: 'center', gap: 10, color: '#fbbf24', fontWeight: 800, fontSize: 20 } }>
						<Icon name="bolt" size={ 22 } color="#fbbf24" /> { tip.title }
					</div>
					<div style={ { color: C.text, fontSize: 20, lineHeight: 1.4, marginTop: 8 } }>{ tip.text }</div>
				</div>
			) : null }
		</div>
	);
};

/** Top-left brand line. */
export const Brand: React.FC<{ t: number }> = ( { t } ) => (
	<div style={ { position: 'absolute', left: 52, top: 34, display: 'flex', alignItems: 'center', gap: 12, fontFamily: SANS, opacity: show( t, -0.4 ) } }>
		<div style={ { width: 32, height: 32, borderRadius: 9, background: `linear-gradient(150deg, ${ C.blueLight }, ${ C.blueDeep })`, display: 'flex', alignItems: 'center', justifyContent: 'center' } }>
			<Icon name="cloud" size={ 20 } color="#fff" stroke={ 2 } />
		</div>
		<div style={ { color: C.white, fontWeight: 700, fontSize: 21 } }>OmniOffloader</div>
		<div style={ { color: C.muted, fontSize: 19 } }>· Full walkthrough</div>
	</div>
);
