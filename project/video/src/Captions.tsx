import React from 'react';
import { useCurrentFrame, useVideoConfig, interpolate } from 'remotion';
import { SANS, LEAD } from './theme';

export type Cue = { start: number; end: number; text: string };

/** Split long sentence cues into short caption lines, timed by character share. */
const chunk = ( cues: Cue[], maxWords = 9 ): Cue[] => {
	const out: Cue[] = [];
	for ( const c of cues ) {
		const words = c.text.split( /\s+/ );
		const n = Math.ceil( words.length / maxWords );
		const per = Math.ceil( words.length / n );
		const parts: string[] = [];
		for ( let i = 0; i < words.length; i += per ) {
			parts.push( words.slice( i, i + per ).join( ' ' ) );
		}
		const total = parts.reduce( ( s, p ) => s + p.length, 0 );
		let t = c.start;
		for ( const p of parts ) {
			const d = ( ( c.end - c.start ) * p.length ) / total;
			out.push( { start: t, end: t + d, text: p } );
			t += d;
		}
	}
	return out;
};

export const Captions: React.FC<{ cues: Cue[] }> = ( { cues } ) => {
	const frame = useCurrentFrame();
	const { fps } = useVideoConfig();
	const t = frame / fps - LEAD;
	const lines = chunk( cues );
	const cur = lines.find( ( c, i ) => t >= c.start - 0.05 && ( t < c.end || ( i === lines.length - 1 && t < c.end + 0.4 ) ) );
	if ( ! cur ) {
		return null;
	}
	const op = interpolate( t, [ cur.start - 0.05, cur.start + 0.1 ], [ 0, 1 ], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' } );
	return (
		<div style={ { position: 'absolute', left: 0, right: 0, bottom: 52, display: 'flex', justifyContent: 'center', opacity: op } }>
			<div
				style={ {
					maxWidth: 1500,
					padding: '14px 30px',
					borderRadius: 16,
					background: 'rgba(3,9,24,0.72)',
					border: '1px solid rgba(255,255,255,0.08)',
					color: '#fff',
					fontFamily: SANS,
					fontSize: 34,
					fontWeight: 600,
					letterSpacing: -0.2,
					textAlign: 'center',
				} }
			>
				{ cur.text.replace( 'omnioffloader dot vercel dot app', 'omnioffloader.vercel.app' ) }
			</div>
		</div>
	);
};
