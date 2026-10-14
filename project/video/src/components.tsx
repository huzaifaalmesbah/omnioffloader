import React from 'react';
import { AbsoluteFill, Img, staticFile, useCurrentFrame, interpolate } from 'remotion';
import { C, SANS, MONO, useIn, rise } from './theme';

/* ------------------------------------------------------------------ */
/* Background: deep navy, drifting glow orbs, faint perspective grid. */
/* ------------------------------------------------------------------ */
export const Background: React.FC<{ hue?: number }> = ( { hue = 0 } ) => {
	const f = useCurrentFrame();
	const orb = ( x: number, y: number, r: number, color: string, speed: number, phase: number ) => (
		<div
			style={ {
				position: 'absolute',
				left: x + Math.sin( f / speed + phase ) * 60,
				top: y + Math.cos( f / ( speed * 1.3 ) + phase ) * 40,
				width: r,
				height: r,
				borderRadius: '50%',
				background: color,
				filter: 'blur(120px)',
				opacity: 0.55,
			} }
		/>
	);
	return (
		<AbsoluteFill style={ { background: `radial-gradient(ellipse at 30% 20%, ${ C.bg1 } 0%, ${ C.bg0 } 70%)`, overflow: 'hidden', filter: `hue-rotate(${ hue }deg)` } }>
			{ orb( -200, -150, 800, '#1a5fd6', 90, 0 ) }
			{ orb( 1300, 500, 700, '#0e3f9e', 110, 2 ) }
			{ orb( 700, 800, 500, '#3b86ee', 140, 4 ) }
			<AbsoluteFill
				style={ {
					backgroundImage:
						'linear-gradient(rgba(124,180,255,0.06) 1px, transparent 1px), linear-gradient(90deg, rgba(124,180,255,0.06) 1px, transparent 1px)',
					backgroundSize: '64px 64px',
					backgroundPosition: `0 ${ ( f * 0.4 ) % 64 }px`,
					maskImage: 'radial-gradient(ellipse at center, black 30%, transparent 75%)',
					WebkitMaskImage: 'radial-gradient(ellipse at center, black 30%, transparent 75%)',
				} }
			/>
		</AbsoluteFill>
	);
};

/* ------------------------------------------------------------------ */
/* Icons (inline, stroke-based so they inherit colour).                */
/* ------------------------------------------------------------------ */
type IconName = 'thumbsUp' | 'share' | 'arrowDown' | 'arrowRight' | 'clock' | 'warning' | 'sparkles' | 'cloud' | 'cloudUp' | 'cloudDown' | 'server' | 'image' | 'check' | 'shield' | 'lock' | 'globe' | 'terminal' | 'bolt' | 'db' | 'users' | 'refresh' | 'trash' | 'file' | 'wp';
const paths: Record<IconName, React.ReactNode> = {
	thumbsUp: (
		<>
			<path d="M7 10v11H4a1 1 0 0 1-1-1v-9a1 1 0 0 1 1-1h3Z" />
			<path d="M7 10l4-7a2.5 2.5 0 0 1 2.5 2.5V9h5.3a2 2 0 0 1 2 2.3l-1.3 8A2 2 0 0 1 17.5 21H7" />
		</>
	),
	share: (
		<>
			<circle cx="18" cy="5" r="2.6" />
			<circle cx="6" cy="12" r="2.6" />
			<circle cx="18" cy="19" r="2.6" />
			<path d="m8.3 10.7 7.4-4.4M8.3 13.3l7.4 4.4" />
		</>
	),
	arrowDown: <path d="M12 4v15m-6-6 6 6 6-6" />,
	arrowRight: <path d="M4 12h15m-6-6 6 6-6 6" />,
	clock: (
		<>
			<circle cx="12" cy="12" r="9" />
			<path d="M12 7v5l3.2 2" />
		</>
	),
	warning: (
		<>
			<path d="M12 3.5 2.5 20h19L12 3.5Z" />
			<path d="M12 10v4.5M12 17.4h.01" />
		</>
	),
	sparkles: (
		<>
			<path d="M10 3.5 11.6 8 16 9.6l-4.4 1.6L10 15.7l-1.6-4.5L4 9.6 8.4 8 10 3.5Z" />
			<path d="M18 14l.8 2.2L21 17l-2.2.8L18 20l-.8-2.2L15 17l2.2-.8L18 14Z" />
		</>
	),
	cloud: <path d="M7 18a5 5 0 0 1-.6-9.96A6.5 6.5 0 0 1 19 9.5a4.25 4.25 0 0 1-.75 8.5H7Z" />,
	cloudUp: (
		<>
			<path d="M7 18a5 5 0 0 1-.6-9.96A6.5 6.5 0 0 1 19 9.5a4.25 4.25 0 0 1-.75 8.5H16" />
			<path d="M12 20v-8m-3 3 3-3 3 3" />
		</>
	),
	cloudDown: (
		<>
			<path d="M7 17a5 5 0 0 1-.6-9.96A6.5 6.5 0 0 1 19 8.5a4.25 4.25 0 0 1-.75 8.5" />
			<path d="M12 11v9m-3-3 3 3 3-3" />
		</>
	),
	server: (
		<>
			<rect x="3" y="4" width="18" height="7" rx="2" />
			<rect x="3" y="13" width="18" height="7" rx="2" />
			<path d="M7 7.5h.01M7 16.5h.01" />
		</>
	),
	image: (
		<>
			<rect x="3" y="4" width="18" height="16" rx="2.5" />
			<circle cx="8.5" cy="9.5" r="1.5" />
			<path d="m21 16-5-5-9 9" />
		</>
	),
	check: <path d="m5 12.5 4.5 4.5L19 7.5" />,
	shield: (
		<>
			<path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.3 7.5 9.5 4.3-1.2 7.5-4.9 7.5-9.5V6L12 3Z" />
			<path d="m8.8 12 2.2 2.2 4.4-4.4" />
		</>
	),
	lock: (
		<>
			<rect x="5" y="11" width="14" height="10" rx="2" />
			<path d="M8 11V8a4 4 0 0 1 8 0v3" />
		</>
	),
	globe: (
		<>
			<circle cx="12" cy="12" r="9" />
			<path d="M3 12h18M12 3c2.5 2.8 3.5 5.8 3.5 9s-1 6.2-3.5 9c-2.5-2.8-3.5-5.8-3.5-9s1-6.2 3.5-9Z" />
		</>
	),
	terminal: (
		<>
			<rect x="3" y="4" width="18" height="16" rx="2.5" />
			<path d="m7 9 3 3-3 3M12.5 15H17" />
		</>
	),
	bolt: <path d="M13 3 5 13.5h6L10 21l8-10.5h-6L13 3Z" />,
	db: (
		<>
			<ellipse cx="12" cy="6" rx="7" ry="3" />
			<path d="M5 6v12c0 1.7 3.1 3 7 3s7-1.3 7-3V6M5 12c0 1.7 3.1 3 7 3s7-1.3 7-3" />
		</>
	),
	users: (
		<>
			<circle cx="9" cy="8" r="3.5" />
			<path d="M2.5 20c.8-3.5 3.4-5.5 6.5-5.5s5.7 2 6.5 5.5M16 4.8a3.5 3.5 0 0 1 0 6.4M18 14.8c1.8.8 3 2.6 3.5 5.2" />
		</>
	),
	refresh: <path d="M20 11a8 8 0 0 0-14.6-4.5L4 8m0-4v4h4m-4 5a8 8 0 0 0 14.6 4.5L20 16m0 4v-4h-4" />,
	trash: <path d="M4 7h16M9 7V4.5h6V7m-8.5 0 1 13h9l1-13" />,
	file: (
		<>
			<path d="M6 3h8l4 4v14H6V3Z" />
			<path d="M14 3v4h4" />
		</>
	),
	wp: (
		<>
			<circle cx="12" cy="12" r="9" />
			<path d="M5.5 8.5 9.5 19l2.5-7 2.5 7 4-10.5M4.5 8.5h3M10 8.5h3.5" />
		</>
	),
};
export const Icon: React.FC<{ name: IconName; size?: number; color?: string; stroke?: number; style?: React.CSSProperties }> = ( { name, size = 32, color = 'currentColor', stroke = 1.8, style } ) => (
	<svg width={ size } height={ size } viewBox="0 0 24 24" fill="none" stroke={ color } strokeWidth={ stroke } strokeLinecap="round" strokeLinejoin="round" style={ style }>
		{ paths[ name ] }
	</svg>
);

/* ------------------------------------------------------------------ */
export const Logo: React.FC<{ size: number; style?: React.CSSProperties }> = ( { size, style } ) => (
	<Img src={ staticFile( 'icon.svg' ) } style={ { width: size, height: size, filter: 'drop-shadow(0 20px 60px rgba(26,95,214,0.55))', ...style } } />
);

/** Eyebrow + headline used at the top-left of most scenes. */
export const SceneTitle: React.FC<{ eyebrow: string; title: React.ReactNode; at?: number; width?: number }> = ( { eyebrow, title, at = -0.3, width = 900 } ) => {
	const p = useIn( at );
	const p2 = useIn( at + 0.12 );
	return (
		<div style={ { position: 'absolute', left: 110, top: 96, width, fontFamily: SANS } }>
			<div style={ { ...rise( p, 20 ), color: C.sky, fontSize: 24, fontWeight: 700, letterSpacing: 4, textTransform: 'uppercase', marginBottom: 14 } }>{ eyebrow }</div>
			<div style={ { ...rise( p2, 30 ), color: C.white, fontSize: 64, fontWeight: 800, lineHeight: 1.08, letterSpacing: -1.5 } }>{ title }</div>
		</div>
	);
};

export const Glass: React.FC<{ style?: React.CSSProperties; children?: React.ReactNode; glow?: boolean }> = ( { style, children, glow } ) => (
	<div
		style={ {
			background: 'linear-gradient(160deg, rgba(255,255,255,0.10), rgba(255,255,255,0.03))',
			border: `1px solid ${ C.cardBorder }`,
			borderRadius: 24,
			boxShadow: glow ? '0 0 0 2px rgba(59,134,238,0.8), 0 30px 80px rgba(26,95,214,0.45)' : '0 30px 80px rgba(0,0,0,0.35)',
			backdropFilter: 'blur(20px)',
			fontFamily: SANS,
			color: C.text,
			...style,
		} }
	>
		{ children }
	</div>
);

export const Pill: React.FC<{ color?: string; bg?: string; children: React.ReactNode; style?: React.CSSProperties; size?: number }> = ( { color = C.greenSoft, bg = 'rgba(34,197,94,0.14)', children, style, size = 24 } ) => (
	<div style={ { display: 'inline-flex', alignItems: 'center', gap: 10, padding: '10px 20px', borderRadius: 999, background: bg, color, border: `1px solid ${ color }44`, fontFamily: SANS, fontWeight: 600, fontSize: size, ...style } }>{ children }</div>
);

/** A browser window around a screenshot, with optional slow zoom. */
export const BrowserFrame: React.FC<{ src: string; width: number; height: number; zoom?: number; originY?: number; offsetY?: number; offsetX?: number; imgWidth?: number; style?: React.CSSProperties; url?: string }> = ( { src, width, height, zoom = 1, originY = 0, offsetY = 0, offsetX = 0, imgWidth, style, url = 'example.com/wp-admin' } ) => (
	<div style={ { width, borderRadius: 18, overflow: 'hidden', background: '#fff', boxShadow: '0 50px 120px rgba(0,0,0,0.55), 0 0 0 1px rgba(255,255,255,0.15)', ...style } }>
		<div style={ { height: 44, background: '#eef1f6', display: 'flex', alignItems: 'center', padding: '0 18px', gap: 9 } }>
			{ [ '#ff5f57', '#febc2e', '#28c840' ].map( ( c ) => (
				<div key={ c } style={ { width: 13, height: 13, borderRadius: 7, background: c } } />
			) ) }
			<div style={ { marginLeft: 20, flex: 1, height: 26, borderRadius: 8, background: '#fff', fontFamily: SANS, fontSize: 14, color: '#667', display: 'flex', alignItems: 'center', paddingLeft: 14 } }>
				<Icon name="lock" size={ 13 } color="#889" style={ { marginRight: 8 } } />
				{ url }
			</div>
		</div>
		<div style={ { height: height - 44, overflow: 'hidden', position: 'relative' } }>
			<Img src={ staticFile( src ) } style={ { width: imgWidth ?? '100%', maxWidth: 'none', transform: `translate(${ -offsetX }px, ${ -offsetY }px) scale(${ zoom })`, transformOrigin: `${ offsetX }px ${ originY }px` } } />
		</div>
	</div>
);

/** A small image-file tile with a gradient "photo". */
export const FileTile: React.FC<{ size?: number; hue?: number; style?: React.CSSProperties; label?: string }> = ( { size = 90, hue = 210, style, label } ) => (
	<div style={ { width: size, height: size, borderRadius: size * 0.18, overflow: 'hidden', position: 'relative', boxShadow: '0 10px 30px rgba(0,0,0,0.35)', border: '2px solid rgba(255,255,255,0.7)', ...style } }>
		<div style={ { position: 'absolute', inset: 0, background: `linear-gradient(160deg, hsl(${ hue },90%,70%), hsl(${ hue + 40 },80%,45%))` } } />
		<div style={ { position: 'absolute', left: '-10%', right: '-10%', bottom: '-20%', height: '60%', background: `hsl(${ hue + 80 },45%,30%)`, borderRadius: '50% 50% 0 0' } } />
		<div style={ { position: 'absolute', right: '18%', top: '16%', width: size * 0.2, height: size * 0.2, borderRadius: '50%', background: '#fff6c8' } } />
		{ label ? <div style={ { position: 'absolute', bottom: 4, left: 0, right: 0, textAlign: 'center', fontFamily: MONO, fontSize: size * 0.12, color: '#fff' } }>{ label }</div> : null }
	</div>
);

/** Animated dots travelling along a straight segment. */
export const FlowLine: React.FC<{ x1: number; y1: number; x2: number; y2: number; on: number; color?: string; count?: number; speed?: number; reverse?: boolean }> = ( { x1, y1, x2, y2, on, color = C.sky, count = 5, speed = 50, reverse } ) => {
	const f = useCurrentFrame();
	const len = Math.hypot( x2 - x1, y2 - y1 );
	return (
		<svg style={ { position: 'absolute', left: 0, top: 0, overflow: 'visible', opacity: on } } width={ 1 } height={ 1 }>
			<line x1={ x1 } y1={ y1 } x2={ x1 + ( x2 - x1 ) * on } y2={ y1 + ( y2 - y1 ) * on } stroke={ color } strokeOpacity={ 0.3 } strokeWidth={ 3 } strokeDasharray="8 10" />
			{ Array.from( { length: count } ).map( ( _, i ) => {
				let k = ( ( f * speed ) / len / 30 + i / count ) % 1;
				if ( reverse ) {
					k = 1 - k;
				}
				const fade = Math.sin( k * Math.PI );
				return <circle key={ i } cx={ x1 + ( x2 - x1 ) * k } cy={ y1 + ( y2 - y1 ) * k } r={ 7 } fill={ color } opacity={ fade * on } style={ { filter: `drop-shadow(0 0 8px ${ color })` } } />;
			} ) }
		</svg>
	);
};

/** Labelled node (icon in a glowing rounded square). */
export const Node: React.FC<{ icon: IconName; label: string; sub?: string; p: number; x: number; y: number; color?: string; children?: React.ReactNode }> = ( { icon, label, sub, p, x, y, color = C.blueLight, children } ) => (
	<div style={ { position: 'absolute', left: x - 110, top: y - 90, width: 220, textAlign: 'center', fontFamily: SANS, opacity: p, transform: `scale(${ 0.6 + 0.4 * p })` } }>
		<div style={ { width: 130, height: 130, margin: '0 auto', borderRadius: 34, background: `linear-gradient(150deg, ${ color }, ${ C.blueDeep })`, display: 'flex', alignItems: 'center', justifyContent: 'center', boxShadow: `0 20px 60px ${ color }66, inset 0 1px 0 rgba(255,255,255,0.3)` } }>
			<Icon name={ icon } size={ 66 } color="#fff" stroke={ 1.6 } />
		</div>
		<div style={ { color: C.white, fontSize: 28, fontWeight: 700, marginTop: 18 } }>{ label }</div>
		{ sub ? <div style={ { color: C.muted, fontSize: 20, marginTop: 4 } }>{ sub }</div> : null }
		{ children }
	</div>
);

export const clamp01 = ( v: number ) => Math.max( 0, Math.min( 1, v ) );
export const lerp = ( a: number, b: number, k: number ) => a + ( b - a ) * k;
export const fmt = ( n: number ) => Math.round( n ).toLocaleString( 'en-US' );
export { interpolate };
