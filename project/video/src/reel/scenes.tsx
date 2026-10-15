import React from 'react';
import { AbsoluteFill } from 'remotion';
import { S, W, sceneStart, sceneEnd, LEAD } from './timing';
import {
	K, FONT, MONO, GRAD, pop, rp, win, appear, bump, Card, Tile, Pill, BigCircle, Connector, Flow, Cursor, BrowserFrame, Character, Bubble, Glyph, Logo, Cue, Mood,
} from './kit';

type SceneProps = { t: number };

/** Scene wrapper: fades in at its first sentence, out at the next scene's. */
const Layer: React.FC<{ id: string; t: number; dy?: number; children: React.ReactNode }> = ( { id, t, dy = 0, children } ) => {
	const a = id === '01-hook' ? -LEAD - 1 : sceneStart( id ) - 0.1;
	const b = sceneEnd( id ) - 0.1;
	if ( t < a - 0.5 || t > b + 0.5 ) {
		return null;
	}
	const o = win( t, a, b, 0.4 );
	return <AbsoluteFill style={ { opacity: o, transform: `translateY(${ dy + ( 1 - rp( t, a - 0.2, a + 0.2 ) ) * 24 }px)` } }>{ children }</AbsoluteFill>;
};

/** "You": brand-blue T-shirt, curly hair, beard. */
const You: React.FC<{ t: number; mood: Mood; at?: number; x?: number; y?: number; size?: number; p?: number; label?: string }> = ( { t, mood, at = -99, x = 540, y = 470, size = 360, p = 1, label = 'আপনি' } ) => (
	<Character x={ x } y={ y } size={ size } mood={ mood } shirt={ K.brand } label={ label } p={ p } scale={ bump( t, at ) } />
);

const Row: React.FC<{ children: React.ReactNode; style?: React.CSSProperties }> = ( { children, style } ) => (
	<div style={ { display: 'flex', alignItems: 'center', gap: 18, ...style } }>{ children }</div>
);
const Txt: React.FC<{ size?: number; w?: number; c?: string; style?: React.CSSProperties; children: React.ReactNode }> = ( { size = 34, w = 700, c = K.ink, style, children } ) => (
	<div style={ { fontFamily: FONT, fontSize: size, fontWeight: w, color: c, lineHeight: 1.3, ...style } }>{ children }</div>
);
const Check: React.FC<{ p: number; ok?: boolean }> = ( { p, ok = true } ) => (
	<div style={ { marginLeft: 'auto', ...appear( p, 8 ) } }>
		<Pill color={ ok ? K.green : K.red } bg={ ok ? K.greenBg : K.redBg } size={ 26 } icon={ ok ? 'check' : 'x' }>{ ok ? 'OK' : 'না' }</Pill>
	</div>
);

/* ================================================================== */
/* 01 — Hook: optimised, still slow                                     */
/* ================================================================== */
const H = '01-hook';
const Hook: React.FC<SceneProps> = ( { t } ) => {
	const s2 = S( H, 2 );
	const rows: [ string, number ][] = [
		[ 'Cache plugin', W( H, 1, 'Cache' ) ],
		[ 'Image compress', W( H, 1, 'compress' ) ],
		[ 'Lazy load', W( H, 1, 'compress' ) + 0.45 ],
	];
	const dim = rp( t, s2, s2 + 0.4 );
	const load = rp( t, s2 + 0.3, s2 + 9 ) * 0.34;
	return (
		<Layer id={ H } t={ t }>
			<Card x={ 150 } y={ 10 } w={ 780 } p={ pop( t, -LEAD - 1 ) } style={ { padding: '30px 36px', opacity: 1 - dim * 0.6, transform: `scale(${ 1 - dim * 0.06 })` } }>
				<Row style={ { marginBottom: 20 } }>
					<Tile icon="bolt" color={ K.brand } bg={ K.brandBg } size={ 64 } />
					<Txt size={ 36 }>Speed checklist</Txt>
				</Row>
				{ rows.map( ( [ label, at ] ) => (
					<Row key={ label } style={ { padding: '16px 0', borderTop: `2px solid ${ K.line }`, opacity: 0.25 + 0.75 * pop( t, at - 0.3 ) } }>
						<Glyph name="sparkle" size={ 30 } color={ K.sub } />
						<Txt size={ 32 } w={ 600 }>{ label }</Txt>
						<Check p={ pop( t, at ) } />
					</Row>
				) ) }
			</Card>
			{ /* still loading */ }
			<Card x={ 170 } y={ 120 } w={ 740 } h={ 300 } p={ pop( t, s2 + 0.1 ) } style={ { overflow: 'hidden' } }>
				<div style={ { height: 54, background: '#f1f3f7', display: 'flex', alignItems: 'center', gap: 10, padding: '0 22px' } }>
					{ [ '#ff5f57', '#febc2e', '#28c840' ].map( ( c ) => <div key={ c } style={ { width: 13, height: 13, borderRadius: 7, background: c } } /> ) }
					<div style={ { marginLeft: 14, flex: 1, height: 32, borderRadius: 9, background: '#fff', fontFamily: FONT, fontSize: 20, color: K.sub, display: 'flex', alignItems: 'center', padding: '0 14px' } }>yoursite.com</div>
				</div>
				<div style={ { height: 6, width: `${ load * 100 }%`, background: K.red } } />
				<div style={ { display: 'flex', gap: 18, padding: 26 } }>
					{ [ 0, 1, 2 ].map( ( i ) => (
						<div key={ i } style={ { flex: 1, height: 150, borderRadius: 16, background: `linear-gradient(90deg, #eef0f5 ${ ( ( t * 0.8 + i * 0.2 ) % 1 ) * 100 - 30 }%, #f8f9fb ${ ( ( t * 0.8 + i * 0.2 ) % 1 ) * 100 }%, #eef0f5 ${ ( ( t * 0.8 + i * 0.2 ) % 1 ) * 100 + 30 }%)`, display: 'flex', alignItems: 'center', justifyContent: 'center' } }>
							<Glyph name="image" size={ 54 } color="#c5cad6" />
						</div>
					) ) }
				</div>
				<div style={ { position: 'absolute', right: 26, top: 74 } }>
					<Glyph name="refresh" size={ 34 } color={ K.red } style={ { transform: `rotate(${ t * 300 }deg)` } } />
				</div>
			</Card>
			<You t={ t } mood={ t < s2 + 0.3 ? 'happy' : 'worried' } at={ s2 + 0.3 } y={ 470 } />
		</Layer>
	);
};
const hookCues: Cue[] = [
	{ at: W( H, 1, 'Cache' ), name: 'pop' },
	{ at: W( H, 1, 'compress' ), name: 'pop' },
	{ at: W( H, 1, 'compress' ) + 0.45, name: 'pop' },
	{ at: S( H, 2 ) + 0.1, name: 'whoosh', volume: 0.2 },
];

/* ================================================================== */
/* 02 — Where do the images come from?                                  */
/* ================================================================== */
const Q = '02-question';
const Question: React.FC<SceneProps> = ( { t } ) => {
	const [ s0, s1, s2, s3, s4, s5 ] = [ 0, 1, 2, 3, 4, 5 ].map( ( k ) => S( Q, k ) );
	const page = pop( t, s1 ) * ( 1 - rp( t, s5 - 0.1, s5 + 0.3 ) );
	const server = pop( t, W( Q, 2, 'ছোট' ) ) * ( 1 - rp( t, s5 - 0.1, s5 + 0.3 ) );
	const slow = t >= s4;
	const html = rp( t, s3 + 0.5, s3 + 1.1 );
	const mood: Mood = t < s2 ? 'thinking' : t < s4 ? 'neutral' : 'worried';
	const weight = pop( t, s5 + 0.1 );
	const seg = ( a: number, b: number ) => rp( t, s5 + a, s5 + b );
	return (
		<Layer id={ Q } t={ t }>
			<BigCircle icon="question" color={ K.brand } p={ pop( t, s0, 0.5 ) * ( 1 - rp( t, s1 - 0.2, s1 + 0.2 ) ) } y={ 190 } />
			{ /* the page */ }
			<Card x={ 90 } y={ 30 } w={ 400 } h={ 400 } p={ page } style={ { overflow: 'hidden' } }>
				<div style={ { height: 46, background: '#f1f3f7', display: 'flex', alignItems: 'center', gap: 8, padding: '0 18px' } }>
					{ [ '#ff5f57', '#febc2e', '#28c840' ].map( ( c ) => <div key={ c } style={ { width: 11, height: 11, borderRadius: 6, background: c } } /> ) }
					<Txt size={ 18 } w={ 500 } c={ K.sub } style={ { marginLeft: 10 } }>yoursite.com</Txt>
				</div>
				<div style={ { padding: 22, display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 } }>
					{ [ 0, 1, 2, 3 ].map( ( i ) => (
						<div key={ i } style={ { height: 120, borderRadius: 14, background: slow ? '#fdeceb' : '#eef0f5', display: 'flex', alignItems: 'center', justifyContent: 'center' } }>
							<Glyph name="image" size={ 48 } color={ slow ? K.red : '#b8bfcc' } />
						</div>
					) ) }
				</div>
				<div style={ { position: 'absolute', left: 22, bottom: 22, opacity: html } }>
					<Pill color={ K.green } bg={ K.greenBg } size={ 24 } icon="check">Cache</Pill>
				</div>
			</Card>
			{ /* the one small server */ }
			<Card x={ 650 } y={ 110 } w={ 340 } h={ 240 } p={ server } style={ { display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 14 } }>
				<Tile icon="server" color={ slow ? K.red : K.blue } bg={ slow ? K.redBg : K.blueBg } size={ 100 } />
				<Txt size={ 28 }>Shared hosting</Txt>
			</Card>
			{ page > 0.9 && server > 0.9 ? (
				<>
					<Connector a={ [ 650, 230 ] } b={ [ 490, 230 ] } t={ t } p={ rp( t, W( Q, 2, 'ছোট' ) + 0.2, W( Q, 2, 'ছোট' ) + 0.7 ) } color={ slow ? K.red : '#9aa3b5' } dots={ false } />
					{ /* HTML arrives fast */ }
					{ t > s3 + 0.4 && t < s3 + 1.2 ? (
						<div style={ { position: 'absolute', left: 650 - 160 * rp( t, s3 + 0.4, s3 + 1.0 ) - 40, top: 205, opacity: 1 - rp( t, s3 + 0.95, s3 + 1.15 ) } }>
							<Pill color="#fff" bg={ K.green } size={ 22 }>HTML</Pill>
						</div>
					) : null }
					<Flow a={ [ 650, 230 ] } b={ [ 490, 230 ] } t={ t } from={ s4 + 0.2 } rate={ 1.2 } dur={ 3.2 } color={ K.red } size={ 42 } />
				</>
			) : null }
			<div style={ { position: 'absolute', left: 820, top: 380, transform: 'translateX(-50%)', ...appear( pop( t, W( Q, 4, 'server' ) ) * ( 1 - rp( t, s5 - 0.1, s5 + 0.3 ) ), 10 ) } }>
				<Pill color={ K.red } bg={ K.redBg } size={ 26 }>সব ছবি এখান থেকে</Pill>
			</div>
			{ /* page weight */ }
			<Card x={ 110 } y={ 40 } w={ 860 } p={ weight } style={ { padding: '34px 40px' } }>
				<Txt size={ 32 } style={ { marginBottom: 24 } }>একটা পেজের ওজন</Txt>
				<div style={ { display: 'flex', height: 74, borderRadius: 18, overflow: 'hidden', background: '#f1f3f7' } }>
					<div style={ { width: `${ 9 * seg( 0.3, 0.8 ) }%`, background: '#9fb3d1' } } />
					<div style={ { width: `${ 16 * seg( 0.6, 1.1 ) }%`, background: K.sky } } />
					<div style={ { width: `${ 75 * seg( 0.9, 2.2 ) }%`, background: K.deep } } />
				</div>
				<Row style={ { marginTop: 22, gap: 26 } }>
					{ ( [ [ 'HTML', '#9fb3d1' ], [ 'CSS / JS', K.sky ], [ 'ছবি', K.deep ] ] as const ).map( ( [ l, c ], i ) => (
						<Row key={ l } style={ { gap: 10, opacity: seg( 0.3 + i * 0.3, 0.7 + i * 0.3 ) } }>
							<div style={ { width: 22, height: 22, borderRadius: 6, background: c } } />
							<Txt size={ 28 } w={ l === 'ছবি' ? 700 : 500 } c={ l === 'ছবি' ? K.deep : K.sub }>{ l }</Txt>
						</Row>
					) ) }
				</Row>
			</Card>
			<You t={ t } mood={ mood } at={ mood === 'worried' ? s4 : s2 } y={ 470 } />
		</Layer>
	);
};
const questionCues: Cue[] = [
	{ at: S( Q, 0 ), name: 'pop' },
	{ at: S( Q, 1 ), name: 'pop' },
	{ at: W( Q, 2, 'ছোট' ), name: 'pop' },
	{ at: S( Q, 3 ) + 0.4, name: 'whoosh', volume: 0.15 },
	{ at: S( Q, 5 ) + 0.1, name: 'pop' },
];

/* ================================================================== */
/* 03 — Analogy: one shop in Dhaka vs a branch in every city           */
/* ================================================================== */
const A = '03-analogy';
const CITIES: { name: string; x: number; y: number }[] = [
	{ name: 'সিলেট', x: 640, y: 150 },
	{ name: 'রাজশাহী', x: 200, y: 220 },
	{ name: 'খুলনা', x: 260, y: 520 },
	{ name: 'চট্টগ্রাম', x: 640, y: 560 },
];
const DHAKA = { x: 430, y: 340 };
const Analogy: React.FC<SceneProps> = ( { t } ) => {
	const [ s0, s1, s2, s3, s4, s5, s6 ] = [ 0, 1, 2, 3, 4, 5, 6 ].map( ( k ) => S( A, k ) );
	const MX = 90; // map card offset in the zone
	const MY = 20;
	const branches = rp( t, s4 + 0.2, s4 + 0.8 );
	const cdn = rp( t, s5 + 0.1, s5 + 0.6 );
	const roads = rp( t, s2, s2 + 0.6 ) * ( 1 - branches );
	const queue = Math.round( 8 * rp( t, s3, s3 + 1.6 ) * ( 1 - branches ) );
	const shopTile = ( x: number, y: number, p: number, main: boolean ) => (
		<div style={ { position: 'absolute', left: x - 42, top: y - 42, ...appear( p, 10 ) } }>
			<div style={ { position: 'relative', width: 84, height: 84 } }>
				<div style={ { position: 'absolute', inset: 0, opacity: 1 - cdn } }>
					<Tile icon="store" color={ main ? K.brand : K.green } bg={ main ? '#d6e5fb' : '#d8f2e1' } size={ 84 } />
				</div>
				<div style={ { position: 'absolute', inset: 0, opacity: cdn, borderRadius: 24, background: GRAD, display: 'flex', alignItems: 'center', justifyContent: 'center' } }>
					<Glyph name="globe" size={ 46 } color="#fff" />
				</div>
			</div>
		</div>
	);
	const visitorP = pop( t, s6 + 0.1 );
	return (
		<Layer id={ A } t={ t } dy={ 50 }>
			<Card x={ MX } y={ MY } w={ 900 } h={ 720 } p={ pop( t, s0 ) }>
				<svg width="900" height="720" style={ { position: 'absolute', left: 0, top: 0 } }>
					<path d="M250 70C380 30 520 50 600 90c80 40 150 20 180 90 30 80-40 130-70 200-20 70 40 160-20 240-40 50-100 30-140-30-40-60-80-20-140 20-80 50-160 0-200-80-40-80-100-110-80-200 20-90-20-210 120-250Z" fill="#e6f4ec" stroke="#c4e5d1" strokeWidth="4" />
				</svg>
				{ /* roads into Dhaka */ }
				{ CITIES.map( ( c ) => (
					<Connector key={ c.name } a={ [ c.x, c.y ] } b={ [ DHAKA.x, DHAKA.y ] } t={ t } p={ roads } color={ t > s3 ? K.red : '#8f9bb3' } speed={ 0.35 } n={ 3 } dot={ 18 } />
				) ) }
				{ /* cities */ }
				{ CITIES.map( ( c, i ) => (
					<div key={ c.name } style={ { position: 'absolute', left: c.x - 60, top: c.y + 40, width: 120, textAlign: 'center', opacity: pop( t, s0 + 0.3 + i * 0.12 ) } }>
						<Txt size={ 26 } w={ 600 } c={ K.sub }>{ c.name }</Txt>
					</div>
				) ) }
				{ CITIES.map( ( c, i ) => (
					<div key={ `${ c.name }d` } style={ { position: 'absolute', left: c.x - 12, top: c.y - 12, width: 24, height: 24, borderRadius: 12, background: '#8f9bb3', opacity: pop( t, s0 + 0.3 + i * 0.12 ) * ( 1 - branches ) } } />
				) ) }
				{ CITIES.map( ( c, i ) => <React.Fragment key={ `${ c.name }s` }>{ shopTile( c.x, c.y, pop( t, s4 + 0.2 + i * 0.15 ), false ) }</React.Fragment> ) }
				{ shopTile( DHAKA.x, DHAKA.y, pop( t, W( A, 1, 'দোকান' ) - 0.2 ), true ) }
				<div style={ { position: 'absolute', left: DHAKA.x - 80, top: DHAKA.y + 50, width: 160, textAlign: 'center', opacity: pop( t, s1 ) } }>
					<Txt size={ 28 } c={ K.brand }>ঢাকা</Txt>
				</div>
				{ /* queue */ }
				{ Array.from( { length: queue } ).map( ( _, i ) => (
					<div key={ i } style={ { position: 'absolute', left: DHAKA.x + 60 + i * 30, top: DHAKA.y - 14 + ( i % 2 ) * 4, width: 26, height: 26, borderRadius: 13, background: K.red, opacity: 0.85 } } />
				) ) }
				<div style={ { position: 'absolute', left: DHAKA.x + 60, top: DHAKA.y - 80, ...appear( pop( t, s3 + 0.3 ) * ( 1 - branches ), 8 ) } }>
					<Pill color={ K.red } bg={ K.redBg } size={ 24 } icon="clock">লম্বা লাইন</Pill>
				</div>
				{ /* CDN label */ }
				<div style={ { position: 'absolute', left: 450, top: 640, transform: 'translateX(-50%)', ...appear( pop( t, W( A, 5, 'CDN' ) ), 10 ) } }>
					<Pill color="#fff" bg={ GRAD } size={ 30 } icon="globe">CDN = সব শহরে branch</Pill>
				</div>
				{ /* visitor in Sylhet gets the image from next door */ }
				<div style={ { position: 'absolute', left: 790, top: 230, ...appear( visitorP, 10 ) } }>
					<Tile icon="user" color={ K.blue } bg={ K.blueBg } size={ 70 } />
				</div>
				{ visitorP > 0.5 ? <Flow a={ [ 650, 160 ] } b={ [ 825, 265 ] } t={ t } from={ s6 + 0.5 } rate={ 1.5 } dur={ 0.7 } color={ K.green } size={ 38 } /> : null }
				<div style={ { position: 'absolute', left: 700, top: 330, ...appear( pop( t, W( A, 6, 'কাছের' ) ), 8 ) } }>
					<Pill color={ K.green } bg={ K.greenBg } size={ 24 } icon="check">কাছ থেকে</Pill>
				</div>
			</Card>
		</Layer>
	);
};
const analogyCues: Cue[] = [
	{ at: S( A, 0 ), name: 'whoosh', volume: 0.2 },
	{ at: W( A, 1, 'দোকান' ) - 0.2, name: 'pop' },
	{ at: S( A, 4 ) + 0.2, name: 'pop' },
	{ at: S( A, 4 ) + 0.5, name: 'pop' },
	{ at: W( A, 5, 'CDN' ), name: 'pop' },
	{ at: S( A, 6 ) + 0.1, name: 'pop' },
];

/* ================================================================== */
/* 04 — Images live in cloud storage                                   */
/* ================================================================== */
const C4 = '04-cloud';
const PROVIDERS = [ 'Cloudflare R2', 'Amazon S3', 'Backblaze B2', 'DigitalOcean Spaces', 'Wasabi', 'S3-compatible' ];
const Cloud: React.FC<SceneProps> = ( { t } ) => {
	const s1 = S( C4, 1 );
	const pc = W( C4, 0, 'cloud' );
	return (
		<Layer id={ C4 } t={ t } dy={ 60 }>
			<div style={ { position: 'absolute', left: 540 - 140, top: 10, width: 280, height: 280, borderRadius: 70, background: 'linear-gradient(145deg, #3b86ee, #1a5fd6 55%, #0e3f9e)', boxShadow: '0 30px 80px rgba(26,95,214,0.35)', display: 'flex', alignItems: 'center', justifyContent: 'center', ...appear( pop( t, pc - 0.2 ) ) } }>
				<Glyph name="cloud" size={ 150 } color="#fff" stroke={ 1.6 } />
			</div>
			<div style={ { position: 'absolute', left: 90, right: 90, top: 340, display: 'flex', flexWrap: 'wrap', justifyContent: 'center', gap: 18 } }>
				{ PROVIDERS.map( ( p, i ) => {
					const r2 = i === 0;
					const at = r2 ? W( C4, 0, 'Cloudflare' ) : pc + 0.2 + i * 0.12;
					return (
						<div key={ p } style={ appear( pop( t, at ), 12 ) }>
							<Pill color={ r2 ? K.brand : K.ink } bg="#fff" size={ r2 ? 34 : 28 } style={ { boxShadow: r2 ? `0 0 0 4px ${ K.brand }` : '0 8px 20px rgba(27,32,51,0.08)' } }>{ p }</Pill>
						</div>
					);
				} ) }
			</div>
			<div style={ { position: 'absolute', left: 0, right: 0, top: 560, display: 'flex', justifyContent: 'center', gap: 22 } }>
				<div style={ appear( pop( t, W( C4, 1, 'free' ) ), 12 ) }>
					<Pill color={ K.green } bg={ K.greenBg } size={ 36 } icon="check">Free tier</Pill>
				</div>
				<div style={ appear( pop( t, W( C4, 1, 'download' ) ), 12 ) }>
					<Pill color={ K.green } bg={ K.greenBg } size={ 36 } icon="check">Download এ খরচ নেই</Pill>
				</div>
			</div>
			<div style={ { position: 'absolute', left: 0, right: 0, top: 660, textAlign: 'center', opacity: pop( t, s1 + 1.2 ) * 0.9 } }>
				<Txt size={ 22 } w={ 500 } c={ K.sub }>R2 · zero egress fees</Txt>
			</div>
		</Layer>
	);
};
const cloudCues: Cue[] = [
	{ at: W( C4, 0, 'cloud' ) - 0.2, name: 'pop' },
	{ at: W( C4, 0, 'Cloudflare' ), name: 'pop' },
	{ at: W( C4, 1, 'free' ), name: 'pop' },
	{ at: W( C4, 1, 'download' ), name: 'pop' },
];

/* ================================================================== */
/* 05 — Pain questions (dark mood)                                     */
/* ================================================================== */
const P = '05-pain';
const Pain: React.FC<SceneProps> = ( { t } ) => {
	const [ s0, s1, s2, s3 ] = [ 0, 1, 2, 3 ].map( ( k ) => S( P, k ) );
	const end = sceneEnd( P );
	const grid = pop( t, s1 ) * ( 1 - rp( t, s2 - 0.1, s2 + 0.2 ) );
	const tiles = Math.round( 28 * rp( t, s1 + 0.1, s1 + 2.2 ) );
	const post = pop( t, s2 ) * ( 1 - rp( t, s3 - 0.1, s3 + 0.2 ) );
	const up = pop( t, s3 );
	const lines = [ 'photo-1.jpg', 'banner.jpg', 'product-12.jpg' ];
	return (
		<Layer id={ P } t={ t }>
			<BigCircle icon="question" color={ K.red } p={ pop( t, s0, 0.45 ) * ( 1 - rp( t, s1 - 0.15, s1 + 0.15 ) ) } y={ 180 } />
			{ /* the whole library */ }
			<Card x={ 130 } y={ 20 } w={ 820 } p={ grid } style={ { padding: '26px 30px' } }>
				<Row style={ { marginBottom: 18 } }>
					<Tile icon="image" color={ K.blue } bg={ K.blueBg } size={ 56 } />
					<Txt size={ 30 }>Media Library</Txt>
					<div style={ { marginLeft: 'auto' } }>
						<Pill color={ K.red } bg={ K.redBg } size={ 24 } icon="question">কে নেবে?</Pill>
					</div>
				</Row>
				<div style={ { display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', gap: 10 } }>
					{ Array.from( { length: 28 } ).map( ( _, i ) => (
						<div key={ i } style={ { height: 74, borderRadius: 12, background: i < tiles ? [ '#dfe8fb', '#eaf3ff', '#d6e5fb', '#e8eef7' ][ i % 4 ] : '#f3f4f7', display: 'flex', alignItems: 'center', justifyContent: 'center', transform: `scale(${ i < tiles ? 1 : 0.8 })` } }>
							{ i < tiles ? <Glyph name="image" size={ 32 } color="#8f9bb3" /> : null }
						</div>
					) ) }
				</div>
			</Card>
			{ /* every link */ }
			<Card x={ 110 } y={ 40 } w={ 860 } p={ post } style={ { padding: '28px 32px' } }>
				<Row style={ { marginBottom: 14 } }>
					<Tile icon="file" color={ K.sky } bg={ K.skyBg } size={ 56 } />
					<Txt size={ 30 }>Post: হোম পেজ</Txt>
				</Row>
				{ lines.map( ( l, i ) => {
					const at = W( P, 2, 'link' ) + i * 0.35;
					return (
						<Row key={ l } style={ { padding: '12px 0', borderTop: `2px solid ${ K.line }` } }>
							<div style={ { position: 'relative', fontFamily: MONO, fontSize: 24, color: K.ink } }>
								{ `<img src="yoursite.com/…/${ l }">` }
								<div style={ { position: 'absolute', left: 0, bottom: -4, height: 4, borderRadius: 2, background: K.red, width: `${ rp( t, at, at + 0.4 ) * 100 }%` } } />
							</div>
							<div style={ { marginLeft: 'auto', ...appear( pop( t, at + 0.3 ), 6 ) } }>
								<Glyph name="x" size={ 34 } color={ K.red } stroke={ 3 } />
							</div>
						</Row>
					);
				} ) }
			</Card>
			{ /* new uploads */ }
			<div style={ { position: 'absolute', left: 0, right: 0, top: 60, display: 'flex', justifyContent: 'center', alignItems: 'center', gap: 34, ...appear( up ) } }>
				<Card x={ 0 } y={ 0 } style={ { position: 'relative', padding: 30, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 14 } }>
					<Tile icon="upload" color={ K.brand } bg={ K.brandBg } size={ 110 } />
					<Txt size={ 28 }>নতুন ছবি</Txt>
				</Card>
				<div style={ { width: 200, height: 6, background: `repeating-linear-gradient(90deg, #8f9bb3 0 10px, transparent 10px 22px)`, opacity: 0.8 } } />
				<div style={ { position: 'relative', width: 170, height: 170, borderRadius: 44, background: 'rgba(255,255,255,0.12)', border: '3px dashed rgba(255,255,255,0.5)', display: 'flex', alignItems: 'center', justifyContent: 'center' } }>
					<Glyph name="cloud" size={ 90 } color="rgba(255,255,255,0.8)" />
					<div style={ { position: 'absolute', right: -18, top: -18, width: 60, height: 60, borderRadius: 30, background: K.red, display: 'flex', alignItems: 'center', justifyContent: 'center', transform: `scale(${ 0.9 + 0.1 * Math.sin( t * 6 ) })` } }>
						<Glyph name="question" size={ 38 } color="#fff" stroke={ 3 } />
					</div>
				</div>
			</div>
			<You t={ t } mood="worried" at={ s0 } y={ 470 } p={ pop( t, s0 ) * ( 1 - rp( t, end - 0.3, end ) ) } />
		</Layer>
	);
};
const painCues: Cue[] = [
	{ at: S( P, 0 ), name: 'whoosh', volume: 0.25 },
	{ at: S( P, 1 ), name: 'pop' },
	{ at: S( P, 2 ), name: 'pop' },
	{ at: S( P, 3 ), name: 'pop' },
];

/* ================================================================== */
/* 06 — OmniOffloader                                                  */
/* ================================================================== */
const O = '06-product';
const Product: React.FC<SceneProps> = ( { t } ) => {
	const [ s0, s1, s2, s3, s4 ] = [ 0, 1, 2, 3, 4 ].map( ( k ) => S( O, k ) );
	const brand = pop( t, W( O, 0, 'OmniOffloader' ) - 0.25 );
	const frame = pop( t, W( O, 0, 'OmniOffloader' ) + 0.5 ) * ( 1 - rp( t, s2 - 0.15, s2 + 0.15 ) );
	const click = W( O, 2, 'Bulk' );
	const bulkEnd = Math.max( s3, click + 1.9 );
	const bulk = pop( t, s2 ) * ( 1 - rp( t, bulkEnd - 0.15, bulkEnd + 0.15 ) );
	const prog = rp( t, click + 0.3, click + 1.5 );
	const flow = pop( t, bulkEnd ) * ( 1 - rp( t, s4 - 0.15, s4 + 0.15 ) );
	const links = pop( t, s4 );
	const brandOut = 1 - rp( t, s2 - 0.2, s2 + 0.2 ); // brand row leaves when the Bulk Offload card takes over
	return (
		<Layer id={ O } t={ t }>
			<div style={ { position: 'absolute', left: 0, right: 0, top: 10, display: 'flex', justifyContent: 'center', alignItems: 'center', gap: 22, ...appear( brand ), opacity: Math.min( 1, brand * 1.3 ) * brandOut } }>
				<Logo size={ 96 } style={ { filter: 'drop-shadow(0 14px 30px rgba(26,95,214,0.35))' } } />
				<Txt size={ 56 } w={ 800 } style={ { fontFamily: FONT, letterSpacing: -1 } }>OmniOffloader</Txt>
				<div style={ { ...appear( pop( t, W( O, 1, 'free' ) ), 8 ), width: rp( t, W( O, 1, 'free' ) - 0.1, W( O, 1, 'free' ) + 0.3 ) * 230, overflow: 'hidden' } }>
					<Pill color={ K.green } bg={ K.greenBg } size={ 26 } icon="wp">Free plugin</Pill>
				</div>
			</div>
			<BrowserFrame x={ 90 } y={ 150 } w={ 900 } h={ 560 } p={ frame } src="wt/dashboard.png" url="yoursite.com/wp-admin → OmniOffloader" crop={ { x: 905, y: 70, w: 1970 } } />
			{ /* Bulk Offload */ }
			<Card x={ 110 } y={ 40 } w={ 860 } p={ bulk } style={ { padding: '34px 40px' } }>
				<Row>
					<Tile icon="upload" color={ K.blue } bg={ K.blueBg } size={ 70 } />
					<div>
						<Txt size={ 34 }>Bulk Offload</Txt>
						<Txt size={ 24 } w={ 500 } c={ K.sub }>Media Library → Cloudflare R2</Txt>
					</div>
				</Row>
				<div style={ { marginTop: 30, height: 96, borderRadius: 22, background: t > click ? '#0e4690' : K.blue, color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: FONT, fontWeight: 700, fontSize: 36, transform: `scale(${ t > click && t < click + 0.15 ? 0.97 : 1 })` } }>
					{ t > click + 0.2 ? 'Offloading…' : 'Start Bulk Offload' }
				</div>
				<div style={ { marginTop: 30, height: 22, borderRadius: 11, background: '#eef0f5', overflow: 'hidden' } }>
					<div style={ { height: '100%', width: `${ prog * 100 }%`, background: prog >= 1 ? K.green : K.blue, borderRadius: 11 } } />
				</div>
				<Row style={ { marginTop: 14 } }>
					<Txt size={ 24 } w={ 600 } c={ prog >= 1 ? K.green : K.sub }>{ prog >= 1 ? 'সব ছবি cloud এ ✓' : `${ Math.round( prog * 100 ) }%` }</Txt>
				</Row>
			</Card>
			<Cursor t={ t } p={ bulk } keys={ [ [ s2, 900, 700 ], [ click - 0.1, 560, 255 ], [ click + 0.6, 560, 255 ], [ click + 1.2, 980, 620 ] ] } clicks={ [ click ] } />
			{ /* old + new, all to the cloud */ }
			<div style={ { opacity: flow } }>
				<Card x={ 100 } y={ 150 } w={ 260 } h={ 260 } p={ flow } style={ { display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 14 } }>
					<Tile icon="server" color={ K.blue } bg={ K.blueBg } size={ 110 } />
					<Txt size={ 28 }>আপনার server</Txt>
				</Card>
				<div style={ { position: 'absolute', left: 720, top: 150, width: 260, height: 260, borderRadius: 64, background: 'linear-gradient(145deg, #3b86ee, #1a5fd6 55%, #0e3f9e)', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', ...appear( flow ) } }>
					<Glyph name="cloud" size={ 120 } color="#fff" stroke={ 1.6 } />
					<Txt size={ 26 } c="#fff">Cloud</Txt>
				</div>
				<Connector a={ [ 360, 280 ] } b={ [ 720, 280 ] } t={ t } color={ K.green } dots={ false } />
				<Flow a={ [ 380, 280 ] } b={ [ 700, 280 ] } t={ t } from={ bulkEnd + 0.1 } rate={ 3.2 } dur={ 1.0 } color={ K.green } size={ 50 } />
				<div style={ { position: 'absolute', left: 0, right: 0, top: 430, display: 'flex', justifyContent: 'center', gap: 20 } }>
					<div style={ appear( pop( t, W( O, 3, 'পুরনো' ) ), 10 ) }>
						<Pill color={ K.brand } bg={ K.brandBg } size={ 30 } icon="image">পুরনো সব</Pill>
					</div>
					<div style={ appear( pop( t, W( O, 3, 'নতুনগুলো' ) ), 10 ) }>
						<Pill color={ K.green } bg={ K.greenBg } size={ 30 } icon="upload">নতুন upload</Pill>
					</div>
				</div>
			</div>
			{ /* links switch by themselves */ }
			<Card x={ 90 } y={ 80 } w={ 900 } p={ links } style={ { padding: '28px 32px' } }>
				<Row style={ { marginBottom: 14 } }>
					<Tile icon="link" color={ K.green } bg={ K.greenBg } size={ 56 } />
					<Txt size={ 30 }>ছবির link</Txt>
				</Row>
				{ [ 'photo-1.jpg', 'banner.jpg', 'product-12.jpg' ].map( ( l, i ) => {
					const at = W( O, 4, 'link' ) + i * 0.4;
					const f = rp( t, at, at + 0.35 );
					return (
						<Row key={ l } style={ { padding: '12px 0', borderTop: `2px solid ${ K.line }`, height: 74 } }>
							<div style={ { position: 'relative', flex: 1, height: 40 } }>
								<div style={ { position: 'absolute', fontFamily: MONO, fontSize: 24, color: K.sub, opacity: 1 - f, transform: `translateY(${ -f * 20 }px)`, textDecoration: f > 0 ? 'line-through' : undefined } }>{ `yoursite.com/…/${ l }` }</div>
								<div style={ { position: 'absolute', fontFamily: MONO, fontSize: 24, color: K.green, fontWeight: 600, opacity: f, transform: `translateY(${ ( 1 - f ) * 20 }px)` } }>{ `cdn.yoursite.com/…/${ l }` }</div>
							</div>
							<div style={ appear( pop( t, at + 0.3 ), 6 ) }>
								<Glyph name="check" size={ 36 } color={ K.green } stroke={ 3 } />
							</div>
						</Row>
					);
				} ) }
			</Card>
			<You t={ t } mood="happy" at={ bulkEnd } y={ 490 } size={ 320 } p={ pop( t, s2 + 0.3 ) } />
		</Layer>
	);
};
const productCues: Cue[] = [
	{ at: S( O, 0 ), name: 'whoosh', volume: 0.25 },
	{ at: W( O, 0, 'OmniOffloader' ) - 0.25, name: 'pop', volume: 0.4 },
	{ at: W( O, 1, 'free' ), name: 'pop' },
	{ at: W( O, 2, 'Bulk' ), name: 'click', volume: 0.5 },
	{ at: Math.max( S( O, 3 ), W( O, 2, 'Bulk' ) + 1.9 ), name: 'pop' },
	{ at: W( O, 4, 'link' ), name: 'pop', volume: 0.2 },
	{ at: W( O, 4, 'link' ) + 0.4, name: 'pop', volume: 0.2 },
	{ at: W( O, 4, 'link' ) + 0.8, name: 'pop', volume: 0.2 },
];

/* ================================================================== */
/* 07 — Free the disk; Bring Back any time                             */
/* ================================================================== */
const CT = '07-control';
const Control: React.FC<SceneProps> = ( { t } ) => {
	const [ s0, s1, s2 ] = [ 0, 1, 2 ].map( ( k ) => S( CT, k ) );
	const del = W( CT, 0, 'মুছে' );
	const used = 0.84 - 0.56 * rp( t, del, del + 1.4 );
	const disk = pop( t, s0 ) * ( 1 - rp( t, s1 - 0.1, s1 + 0.25 ) );
	const back = pop( t, s2 );
	const barColor = used > 0.6 ? K.red : used > 0.4 ? K.brand : K.green;
	return (
		<Layer id={ CT } t={ t }>
			<Card x={ 130 } y={ 30 } w={ 820 } p={ disk } style={ { padding: '34px 40px' } }>
				<Row>
					<Tile icon="disk" color={ barColor } bg={ used > 0.6 ? K.redBg : K.greenBg } size={ 70 } />
					<Txt size={ 34 }>Hosting disk</Txt>
					<div style={ { marginLeft: 'auto', ...appear( pop( t, del - 0.2 ), 8 ) } }>
						<Pill color={ K.sub } bg="#f1f3f7" size={ 24 } icon="trash">local copy</Pill>
					</div>
				</Row>
				<div style={ { marginTop: 30, height: 56, borderRadius: 16, background: '#eef0f5', overflow: 'hidden' } }>
					<div style={ { height: '100%', width: `${ used * 100 }%`, background: barColor, borderRadius: 16 } } />
				</div>
				<Row style={ { marginTop: 14 } }>
					<Txt size={ 26 } w={ 600 } c={ barColor }>{ used > 0.5 ? 'প্রায় ভর্তি' : 'জায়গা খালি ✓' }</Txt>
				</Row>
			</Card>
			<Bubble x={ 600 } y={ 380 } p={ pop( t, s1 ) * ( 1 - rp( t, s2 - 0.1, s2 + 0.2 ) ) }>মত বদলালে?</Bubble>
			{ /* Bring Back: cloud → server */ }
			<div style={ { opacity: back } }>
				<div style={ { position: 'absolute', left: 100, top: 40, width: 240, height: 240, borderRadius: 60, background: 'linear-gradient(145deg, #3b86ee, #1a5fd6 55%, #0e3f9e)', display: 'flex', alignItems: 'center', justifyContent: 'center', ...appear( back ) } }>
					<Glyph name="cloud" size={ 120 } color="#fff" stroke={ 1.6 } />
				</div>
				<Card x={ 740 } y={ 40 } w={ 240 } h={ 240 } p={ back } style={ { display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 10 } }>
					<Tile icon="server" color={ K.blue } bg={ K.blueBg } size={ 100 } />
					<Txt size={ 26 }>server</Txt>
				</Card>
				<Connector a={ [ 340, 160 ] } b={ [ 740, 160 ] } t={ t } color={ K.blue } dots={ false } />
				<Flow a={ [ 360, 160 ] } b={ [ 720, 160 ] } t={ t } from={ W( CT, 2, 'Bring' ) } rate={ 3 } dur={ 1.0 } color={ K.blue } size={ 50 } />
				<div style={ { position: 'absolute', left: 0, right: 0, top: 310, display: 'flex', justifyContent: 'center', ...appear( pop( t, W( CT, 2, 'Bring' ) ), 10 ) } }>
					<Pill color="#fff" bg={ K.blue } size={ 34 } icon="download">Bring Back</Pill>
				</div>
			</div>
			<You t={ t } mood={ t < s1 ? 'neutral' : t < s2 ? 'thinking' : 'happy' } at={ t < s2 ? s1 : s2 + 0.4 } y={ 470 } p={ pop( t, s0 ) } />
		</Layer>
	);
};
const controlCues: Cue[] = [
	{ at: S( CT, 0 ), name: 'pop' },
	{ at: W( CT, 0, 'মুছে' ), name: 'whoosh', volume: 0.15 },
	{ at: S( CT, 1 ), name: 'pop' },
	{ at: W( CT, 2, 'Bring' ), name: 'pop' },
];

/* ================================================================== */
/* 08 — The honest part, then the takeaway                             */
/* ================================================================== */
const HO = '08-honest';
const Honest: React.FC<SceneProps> = ( { t } ) => {
	const [ s0, s1, s2, s3 ] = [ 0, 1, 2, 3 ].map( ( k ) => S( HO, k ) );
	const theme = pop( t, s1 ) * ( 1 - rp( t, s3 - 0.1, s3 + 0.2 ) );
	const img = pop( t, s2 ) * ( 1 - rp( t, s3 - 0.1, s3 + 0.2 ) );
	const recap = pop( t, s3 );
	const steps: [ string, React.ReactNode ][] = [
		[ 'server', <Tile key="a" icon="server" color={ K.blue } bg={ K.blueBg } size={ 110 } /> ],
		[ 'cloud + CDN', <div key="b" style={ { width: 110, height: 110, borderRadius: 30, background: GRAD, display: 'flex', alignItems: 'center', justifyContent: 'center' } }><Glyph name="globe" size={ 60 } color="#fff" /></div> ],
		[ 'visitor', <Tile key="c" icon="user" color={ K.green } bg={ K.greenBg } size={ 110 } /> ],
	];
	return (
		<Layer id={ HO } t={ t }>
			<BigCircle icon="comment" color={ K.sky } p={ pop( t, s0, 0.45 ) * ( 1 - rp( t, s1 - 0.15, s1 + 0.15 ) ) } y={ 190 } />
			<Card x={ 90 } y={ 40 } w={ 430 } h={ 360 } p={ theme } style={ { padding: 34, display: 'flex', flexDirection: 'column', gap: 18, opacity: theme * ( 1 - 0.55 * rp( t, s2, s2 + 0.4 ) ) } }>
				<Tile icon="weight" color={ K.red } bg={ K.redBg } size={ 90 } />
				<Txt size={ 32 }>ভারী theme / plugin</Txt>
				<div><Pill color={ K.red } bg={ K.redBg } size={ 24 } icon="x">আলাদা সমস্যা</Pill></div>
			</Card>
			<Card x={ 560 } y={ 40 } w={ 430 } h={ 360 } p={ img } style={ { padding: 34, display: 'flex', flexDirection: 'column', gap: 18, boxShadow: `0 0 0 5px ${ K.green }, 0 24px 60px rgba(22,163,74,0.18)` } }>
				<Tile icon="image" color={ K.green } bg={ K.greenBg } size={ 90 } />
				<Txt size={ 32 }>ছবি slow হলে</Txt>
				<div><Pill color={ K.green } bg={ K.greenBg } size={ 24 } icon="check">সহজ fix</Pill></div>
			</Card>
			<div style={ { position: 'absolute', left: 60, right: 60, top: 70, display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' } }>
				{ steps.map( ( [ label, icon ], i ) => (
					<div key={ label } style={ { width: 240, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 16, ...appear( pop( t, s3 + i * 0.35 ), 14 ) } }>
						{ icon }
						<Txt size={ 28 } w={ 600 } c={ K.sub }>{ label }</Txt>
					</div>
				) ) }
			</div>
			{ recap > 0.5 ? (
				<>
					<Connector a={ [ 245, 125 ] } b={ [ 475, 125 ] } t={ t } color={ K.green } speed={ 0.8 } />
					<Connector a={ [ 605, 125 ] } b={ [ 835, 125 ] } t={ t } color={ K.green } speed={ 0.8 } />
				</>
			) : null }
			<You t={ t } mood={ t < s2 ? 'neutral' : 'happy' } at={ s2 + 0.2 } y={ 470 } p={ pop( t, s0 ) } />
		</Layer>
	);
};
const honestCues: Cue[] = [
	{ at: S( HO, 0 ), name: 'pop' },
	{ at: S( HO, 1 ), name: 'pop' },
	{ at: S( HO, 2 ), name: 'pop' },
	{ at: S( HO, 3 ), name: 'whoosh', volume: 0.15 },
];

/* ================================================================== */
/* 09 — CTA                                                            */
/* ================================================================== */
const CTA = '09-cta';
const TYPED = 'আমার সাইটে hosting থেকেই আসে…';
const Cta: React.FC<SceneProps> = ( { t } ) => {
	const [ s0, s1, s2, s3 ] = [ 0, 1, 2, 3 ].map( ( k ) => S( CTA, k ) );
	const post = pop( t, s0 ) * ( 1 - rp( t, s3 - 0.1, s3 + 0.25 ) );
	const typed = TYPED.slice( 0, Math.round( TYPED.length * rp( t, s1 + 0.1, s2 + 0.3 ) ) );
	const profile = pop( t, s3 + 0.1 );
	const follow = W( CTA, 3, 'Follow' );
	const share = follow + 1.3;
	const [ c1, c2, c3 ] = [ '#3b86ee', '#0e3f9e', '#7cb4ff' ];
	return (
		<Layer id={ CTA } t={ t } dy={ 50 }>
			<Card x={ 100 } y={ 10 } w={ 880 } p={ post } style={ { padding: '30px 34px' } }>
				<Row>
					<Logo size={ 70 } />
					<div>
						<Txt size={ 30 }>OmniOffloader</Txt>
						<Txt size={ 22 } w={ 500 } c={ K.sub }>এইমাত্র</Txt>
					</div>
				</Row>
				<Txt size={ 32 } w={ 600 } style={ { margin: '22px 0' } }>আপনার সাইটের ছবি এখন <span style={ { color: K.brand } }>কোথা থেকে আসে?</span></Txt>
				{ [ c1, c2, c3 ].map( ( c, i ) => (
					<Row key={ c } style={ { marginBottom: 12, ...appear( pop( t, s1 + i * 0.2 ), 8 ) } }>
						<div style={ { width: 46, height: 46, borderRadius: 23, background: c, opacity: 0.85 } } />
						<div style={ { padding: '8px 18px', borderRadius: 18, background: '#f1f3f7', fontFamily: FONT, fontSize: 22, color: K.sub } }>{ '•'.repeat( 1 + ( ( ( Math.floor( t * 3 + i ) % 3 ) + 3 ) % 3 ) ) } লিখছেন</div>
					</Row>
				) ) }
				<Row style={ { marginTop: 14 } }>
					<div style={ { flex: 1, height: 64, borderRadius: 18, border: `2px solid ${ K.line }`, display: 'flex', alignItems: 'center', padding: '0 20px', fontFamily: FONT, fontSize: 26, color: K.ink } }>
						{ typed }
						<span style={ { width: 3, height: 32, background: K.brand, marginLeft: 3, opacity: Math.abs( Math.floor( t * 2.5 ) ) % 2 } } />
					</div>
					<Pill color="#fff" bg={ typed.length === TYPED.length ? K.green : '#b8bfcc' } size={ 26 } icon="comment">পাঠান</Pill>
				</Row>
			</Card>
			<You t={ t } mood={ t < s1 ? 'thinking' : 'happy' } at={ s1 } y={ 540 } size={ 290 } p={ pop( t, s0 + 0.2 ) * ( 1 - rp( t, s3 - 0.1, s3 + 0.25 ) ) } />
			<div style={ { position: 'absolute', left: 0, right: 0, top: 488, display: 'flex', justifyContent: 'center', ...appear( pop( t, s2 ) * ( 1 - rp( t, s3 - 0.1, s3 + 0.25 ) ), 12 ) } }>
				<Pill color={ K.ink } bg="#fff" size={ 28 } icon="pin" style={ { boxShadow: '0 12px 30px rgba(27,32,51,0.12)' } }>wordpress.org/plugins/omnioffloader</Pill>
			</div>
			{ /* profile card */ }
			<Card x={ 120 } y={ 60 } w={ 840 } p={ profile } style={ { padding: '40px 40px 36px', display: 'flex', flexDirection: 'column', alignItems: 'center' } }>
				<Logo size={ 130 } style={ { marginTop: -100, filter: 'drop-shadow(0 14px 30px rgba(26,95,214,0.35))' } } />
				<Txt size={ 44 } w={ 800 } style={ { marginTop: 16 } }>OmniOffloader</Txt>
				<Txt size={ 26 } w={ 500 } c={ K.sub }>Free WordPress plugin</Txt>
				<Row style={ { marginTop: 18, gap: 12 } }>
					<Pill color={ K.blue } bg={ K.blueBg } size={ 22 } icon="wp">WordPress</Pill>
					<Pill color={ K.brand } bg={ K.brandBg } size={ 22 } icon="cloud">Cloud</Pill>
					<Pill color={ K.green } bg={ K.greenBg } size={ 22 } icon="bolt">Speed</Pill>
				</Row>
				<Row style={ { marginTop: 30, gap: 20, width: '100%' } }>
					<div style={ { flex: 1, height: 84, borderRadius: 20, background: t > follow ? K.green : K.blue, color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 12, fontFamily: FONT, fontWeight: 700, fontSize: 32 } }>
						<Glyph name={ t > follow ? 'check' : 'userPlus' } size={ 34 } color="#fff" stroke={ 2.4 } />
						{ t > follow ? 'Following' : 'Follow' }
					</div>
					<div style={ { flex: 1, height: 84, borderRadius: 20, background: t > share ? K.green : K.sky, color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 12, fontFamily: FONT, fontWeight: 700, fontSize: 32 } }>
						<Glyph name={ t > share ? 'check' : 'share' } size={ 34 } color="#fff" stroke={ 2.4 } />
						{ t > share ? 'Shared' : 'Share' }
					</div>
				</Row>
			</Card>
			<div style={ { position: 'absolute', left: 0, right: 0, top: 600, display: 'flex', justifyContent: 'center', alignItems: 'center', gap: 20, ...appear( pop( t, s3 + 0.6 ), 14 ) } }>
				<Logo size={ 92 } style={ { filter: 'drop-shadow(0 12px 26px rgba(26,95,214,0.35))' } } />
				<Txt size={ 54 } w={ 800 } style={ { letterSpacing: -1 } }>OmniOffloader</Txt>
			</div>
			<Cursor t={ t } p={ profile } keys={ [ [ s3, 900, 720 ], [ follow - 0.1, 330, 540 ], [ follow + 0.4, 330, 540 ], [ share - 0.1, 750, 540 ], [ share + 1, 820, 640 ] ] } clicks={ [ follow, share ] } />
		</Layer>
	);
};
const ctaCues: Cue[] = [
	{ at: S( CTA, 0 ), name: 'pop' },
	{ at: S( CTA, 2 ), name: 'pop' },
	{ at: S( CTA, 3 ) + 0.1, name: 'whoosh', volume: 0.2 },
	{ at: W( CTA, 3, 'Follow' ), name: 'click', volume: 0.5 },
	{ at: W( CTA, 3, 'Follow' ) + 1.3, name: 'click', volume: 0.5 },
];

export const SCENES: React.FC<SceneProps>[] = [ Hook, Question, Analogy, Cloud, Pain, Product, Control, Honest, Cta ];
export const CUES: Cue[] = [ ...hookCues, ...questionCues, ...analogyCues, ...cloudCues, ...painCues, ...productCues, ...controlCues, ...honestCues, ...ctaCues ];
