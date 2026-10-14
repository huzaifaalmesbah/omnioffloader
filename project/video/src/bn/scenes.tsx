import React from 'react';
import { AbsoluteFill, useCurrentFrame, interpolate, Easing } from 'remotion';
import { C, BN, SANS, MONO, useT } from '../theme';
import { Background, Icon, Logo, FileTile, FlowLine, Node, lerp } from '../components';
import { Screen } from '../wt/Screen';
import { S, W, sceneDur, rp, pop, fadeWin, bn, reveal, Sfx, StepTracker, Headline, CfWindow, CfButton, CfField, CF, typed, Pointer, Note } from './kit';

const FONT = `${ BN }, ${ SANS }`;
const ADMIN = 'example.com/wp-admin/admin.php?page=omnioffloader&view=';

/** Notes column on the right of a screen. */
const Side: React.FC<{ children: React.ReactNode; top?: number }> = ( { children, top = 150 } ) => (
	<div style={ { position: 'absolute', left: 1338, top, width: 540, display: 'flex', flexDirection: 'column', gap: 18 } }>{ children }</div>
);

/** Moves a motion scene's content down so it sits centred in the frame. */
const Shift: React.FC<{ dy: number; children: React.ReactNode }> = ( { dy, children } ) => (
	<AbsoluteFill style={ { transform: `translateY(${ dy }px)` } }>{ children }</AbsoluteFill>
);

const StepTitle: React.FC<{ t: number; n: number; title: string }> = ( { t, n, title } ) => {
	const p = pop( t, -0.2 );
	return (
		<div style={ { fontFamily: FONT, ...reveal( p, 20 ), marginBottom: 8 } }>
			<div style={ { color: CF.orange, fontSize: 24, fontWeight: 700, letterSpacing: 1 } }>ধাপ { bn( n ) }</div>
			<div style={ { color: C.white, fontSize: 50, fontWeight: 700, lineHeight: 1.15 } }>{ title }</div>
		</div>
	);
};

/** Small server with a disk meter. */
const Server: React.FC<{ fill: number; x: number; y: number; scale?: number; shake?: number }> = ( { fill, x, y, scale = 1, shake = 0 } ) => {
	const col = fill < 0.6 ? C.blueLight : fill < 0.85 ? C.amber : C.red;
	return (
		<div style={ { position: 'absolute', left: x, top: y, transform: `translateX(${ shake }px) scale(${ scale })`, transformOrigin: 'top left', fontFamily: FONT } }>
			{ [ 0, 1, 2 ].map( ( i ) => (
				<div key={ i } style={ { width: 420, height: 84, marginBottom: 12, borderRadius: 16, background: 'linear-gradient(180deg,#1c2b4d,#101b33)', border: '1px solid rgba(255,255,255,0.14)', display: 'flex', alignItems: 'center', padding: '0 24px', gap: 12, boxShadow: '0 20px 50px rgba(0,0,0,0.4)' } }>
					{ [ 0, 1, 2 ].map( ( k ) => (
						<div key={ k } style={ { width: 12, height: 12, borderRadius: 6, background: k === 2 && fill > 0.85 ? C.red : C.greenSoft, boxShadow: `0 0 10px ${ k === 2 && fill > 0.85 ? C.red : C.greenSoft }` } } />
					) ) }
					<div style={ { flex: 1, marginLeft: 16, height: 5, borderRadius: 3, background: 'rgba(255,255,255,0.12)' } } />
				</div>
			) ) }
			<div style={ { display: 'flex', justifyContent: 'space-between', color: C.text, fontSize: 24, marginTop: 10 } }>
				<span>ডিস্ক</span>
				<span style={ { color: col, fontWeight: 700, fontFamily: SANS } }>{ Math.round( fill * 100 ) }%</span>
			</div>
			<div style={ { marginTop: 8, width: 420, height: 14, borderRadius: 7, background: 'rgba(255,255,255,0.1)', overflow: 'hidden' } }>
				<div style={ { width: `${ fill * 100 }%`, height: '100%', background: `linear-gradient(90deg, ${ C.blueLight }, ${ col })`, boxShadow: `0 0 18px ${ col }` } } />
			</div>
		</div>
	);
};

/* ================================================================== */
/* 01 Hook                                                              */
/* ================================================================== */
export const Hook: React.FC = () => {
	const t = useT();
	const f = useCurrentFrame();
	const id = '01-hook';
	const s1 = S( id, 1 );
	const s2 = S( id, 2 );
	const s3 = S( id, 3 );
	const s4 = S( id, 4 );
	const fill = lerp( 0.45, 0.97, rp( t, 0, s1 - 0.3 ) );
	const a = 1 - rp( t, s1 - 0.3, s1 + 0.2 );
	const gbAt = W( id, 1, 'দশ' );
	const counter = Math.round( lerp( 0, 10, rp( t, gbAt, gbAt + 1.2 ) ) );
	return (
		<AbsoluteFill>
			<Background />
			<Sfx at={ gbAt } name="pop" volume={ 0.4 } />
			<Sfx at={ s3 - 0.2 } name="whoosh" volume={ 0.35 } />
			<Shift dy={ 70 }>
			{ /* Problem: disk filling */ }
			<div style={ { opacity: a } }>
				<div style={ { position: 'absolute', left: 120, top: 250, width: 900 } }>
					<Headline t={ t } at={ 0.15 } text="ছবি রাখতে দামি হোস্টিং খরচ করছেন?" size={ 82 } accent="দামি" />
				</div>
				{ Array.from( { length: 14 } ).map( ( _, i ) => {
					const k = ( t - i * 0.32 ) / 1.1;
					if ( k <= 0 || k >= 1 ) {
						return null;
					}
					return <FileTile key={ i } size={ 70 } hue={ ( i * 53 ) % 360 } style={ { position: 'absolute', left: 1250 + ( ( i * 89 ) % 300 ), top: lerp( -120, 330, k * k ), opacity: k > 0.85 ? ( 1 - k ) / 0.15 : 1 } } />;
				} ) }
				<Server fill={ fill } x={ 1220 } y={ 360 } shake={ fill > 0.9 ? Math.sin( f * 1.7 ) * 2.5 : 0 } />
			</div>
			{ /* Promise: 10 GB free + CDN */ }
			<div style={ { opacity: rp( t, s1 - 0.2, s1 + 0.3 ) * ( 1 - rp( t, s3 - 0.3, s3 + 0.2 ) ) } }>
				<div style={ { position: 'absolute', left: 0, right: 0, top: 170, textAlign: 'center', fontFamily: FONT } }>
					<div style={ { display: 'inline-flex', alignItems: 'center', gap: 16, color: CF.orange, fontSize: 40, fontWeight: 700, opacity: pop( t, s1 ) } }>
						<Icon name="cloud" size={ 50 } color={ CF.orange } stroke={ 2.2 } /> Cloudflare R2
					</div>
					<div style={ { marginTop: 10, fontFamily: SANS, fontWeight: 800, fontSize: 210, color: C.white, lineHeight: 1, letterSpacing: -6, transform: `scale(${ 0.8 + 0.2 * pop( t, gbAt ) })`, opacity: pop( t, gbAt - 0.1 ) } }>
						{ counter } <span style={ { fontSize: 120, color: C.sky } }>GB</span>
					</div>
					<div style={ { fontSize: 64, fontWeight: 700, color: C.greenSoft, marginTop: 6, opacity: pop( t, W( id, 1, 'একদম' ) ) } }>একদম ফ্রি</div>
					<div style={ { display: 'inline-flex', alignItems: 'center', gap: 14, marginTop: 30, padding: '14px 28px', borderRadius: 999, background: 'rgba(124,180,255,0.12)', border: '1px solid rgba(124,180,255,0.35)', color: C.white, fontSize: 32, fontWeight: 600, opacity: pop( t, W( id, 1, 'দুনিয়াজোড়া' ) ) } }>
						<Icon name="globe" size={ 34 } color={ C.sky } /> সাথে দুনিয়াজোড়া CDN
					</div>
				</div>
				<div style={ { position: 'absolute', right: 120, top: 120, opacity: pop( t, W( id, 2, 'মিনিটে' ) - 0.5 ), fontFamily: FONT } }>
					<div style={ { display: 'flex', alignItems: 'center', gap: 12, padding: '14px 24px', borderRadius: 16, background: C.blue, color: '#fff', fontSize: 30, fontWeight: 700, boxShadow: '0 20px 50px rgba(26,95,214,0.5)' } }><Icon name="clock" size={ 32 } color="#fff" stroke={ 2.2 } /> মাত্র কয়েক মিনিটে</div>
				</div>
			</div>
			{ /* Open loop: the mistake */ }
			<div style={ { opacity: rp( t, s3 - 0.2, s3 + 0.3 ) } }>
				<div style={ { position: 'absolute', left: 0, right: 0, top: 150, display: 'flex', justifyContent: 'center', gap: 22 } }>
					{ Array.from( { length: 6 } ).map( ( _, i ) => {
						const gone = rp( t, W( id, 3, 'গায়েব' ) + i * 0.18, W( id, 3, 'গায়েব' ) + 0.4 + i * 0.18 );
						return (
							<div key={ i } style={ { position: 'relative', width: 200, height: 150 } }>
								<FileTile size={ 150 } hue={ 20 + i * 50 } style={ { position: 'absolute', left: 25, opacity: 1 - gone, transform: `scale(${ 1 - gone * 0.3 })` } } />
								<div style={ { position: 'absolute', left: 25, top: 0, width: 150, height: 150, borderRadius: 26, border: '3px dashed rgba(239,68,68,0.7)', display: 'flex', alignItems: 'center', justifyContent: 'center', opacity: gone } }>
									<Icon name="image" size={ 60 } color={ C.red } />
								</div>
							</div>
						);
					} ) }
				</div>
				<div style={ { position: 'absolute', left: 0, right: 0, top: 380, textAlign: 'center' } }>
					<Headline t={ t } at={ W( id, 3, 'ভুলের' ) } text="একটা ভুল = সব ছবি গায়েব" size={ 88 } color="#fca5a5" />
					<div style={ { fontFamily: FONT, fontSize: 36, color: C.muted, marginTop: 20, opacity: pop( t, W( id, 3, 'গায়েব' ) + 1.2 ) } }>কোন ভুল? সেটা বলব ভিডিওর শেষে…</div>
				</div>
				<div style={ { position: 'absolute', left: 0, right: 0, top: 690, textAlign: 'center', opacity: pop( t, s4 ), transform: `translateY(${ Math.sin( t * 5 ) * 8 }px)`, fontFamily: FONT, fontSize: 40, fontWeight: 700, color: C.sky, display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 14 } }>শেষ পর্যন্ত থাকুন <Icon name="arrowDown" size={ 40 } color={ C.sky } stroke={ 2.6 } /></div>
			</div>
			</Shift>
		</AbsoluteFill>
	);
};

/* ================================================================== */
/* 02 Problem                                                           */
/* ================================================================== */
export const Problem: React.FC = () => {
	const t = useT();
	const id = '02-problem';
	const s1 = S( id, 1 );
	const s2 = S( id, 2 );
	const s3 = S( id, 3 );
	const solve = rp( t, s3 - 0.2, s3 + 0.4 );
	const cloudAt = W( id, 3, 'ক্লাউডে' );
	const cdnAt = W( id, 3, 'CDN' );
	const visitors = [ [ 230, 220 ], [ 200, 560 ], [ 380, 780 ], [ 1500, 230 ], [ 1650, 560 ], [ 1450, 790 ] ];
	const meters: [ string, number ][] = [ [ 'ডিস্ক', 0.94 ], [ 'ব্যাকআপ', 0.88 ] ];
	return (
		<AbsoluteFill>
			<Background />
			<Sfx at={ s3 - 0.2 } name="whoosh" volume={ 0.35 } />
			<Sfx at={ cloudAt } name="pop" volume={ 0.3 } />
			<Sfx at={ cdnAt } name="pop" volume={ 0.3 } />
			<Shift dy={ 40 }>
			<div style={ { position: 'absolute', left: 0, right: 0, top: 70, textAlign: 'center', opacity: 1 - solve } }>
				<Headline t={ t } at={ 0 } text="একটু ভাবুন…" size={ 64 } />
			</div>
			<div style={ { opacity: 1 - solve } }>
				<Node icon="server" label="একটাই সার্ভার" sub="সাইট + ছবি + ভিডিও" p={ pop( t, W( id, 1, 'সার্ভার' ) ) } x={ 960 } y={ 470 } />
				{ visitors.map( ( [ x, y ], i ) => (
					<React.Fragment key={ i }>
						<FlowLine x1={ x } y1={ y } x2={ 960 } y2={ 420 } on={ rp( t, s1 + 1 + i * 0.4, s1 + 1.6 + i * 0.4 ) } color={ C.amber } count={ 3 } speed={ 34 } reverse />
						<div style={ { position: 'absolute', left: x - 34, top: y - 34, opacity: pop( t, s1 + 1 + i * 0.4 ) } }>
							<div style={ { width: 68, height: 68, borderRadius: 34, background: 'rgba(255,255,255,0.08)', border: '1px solid rgba(255,255,255,0.2)', display: 'flex', alignItems: 'center', justifyContent: 'center' } }>
								<Icon name="users" size={ 34 } color={ C.text } />
							</div>
						</div>
					</React.Fragment>
				) ) }
				<div style={ { position: 'absolute', left: 760, top: 640, width: 400, fontFamily: FONT } }>
					{ meters.map( ( [ label, v ], i ) => {
						const mAt = W( id, 2, i ? 'ব্যাকআপ' : 'ডিস্ক' );
						const p = rp( t, mAt, mAt + 1.4 );
						return (
							<div key={ label } style={ { marginBottom: 18, opacity: pop( t, W( id, 2, i ? 'ব্যাকআপ' : 'ডিস্ক' ) - 0.2 ) } }>
								<div style={ { display: 'flex', justifyContent: 'space-between', color: C.text, fontSize: 26 } }>
									<span>{ label }</span>
									<span style={ { fontFamily: SANS, color: p > 0.7 ? C.red : C.amber, fontWeight: 700 } }>{ Math.round( v * p * 100 ) }%</span>
								</div>
								<div style={ { marginTop: 6, height: 14, borderRadius: 7, background: 'rgba(255,255,255,0.1)', overflow: 'hidden' } }>
									<div style={ { width: `${ v * p * 100 }%`, height: '100%', background: `linear-gradient(90deg, ${ C.amber }, ${ C.red })` } } />
								</div>
							</div>
						);
					} ) }
				</div>
			</div>
			{ /* Solution: site server → cloud → CDN → visitors */ }
			<div style={ { opacity: solve } }>
				<div style={ { position: 'absolute', left: 0, right: 0, top: 120, textAlign: 'center' } }>
					<Headline t={ t } at={ s3 } text="সমাধান: ক্লাউড + CDN" size={ 70 } accent="CDN" />
				</div>
				<Node icon="server" label="আপনার সাইট" sub="শুধু সাইট চালায়" p={ pop( t, s3 + 0.2 ) } x={ 300 } y={ 520 } color="#475569" />
				<Node icon="cloud" label="ক্লাউড" sub="Cloudflare R2" p={ pop( t, cloudAt ) } x={ 760 } y={ 520 } color={ CF.orange } />
				<Node icon="globe" label="CDN" sub="দ্রুত সার্ভ" p={ pop( t, cdnAt ) } x={ 1220 } y={ 520 } />
				<Node icon="users" label="ভিজিটর" sub="কাছের সার্ভার থেকে" p={ pop( t, cdnAt + 0.7 ) } x={ 1660 } y={ 520 } color="#16a34a" />
				<FlowLine x1={ 380 } y1={ 495 } x2={ 680 } y2={ 495 } on={ rp( t, cloudAt, cloudAt + 0.7 ) } color={ C.sky } />
				<FlowLine x1={ 840 } y1={ 495 } x2={ 1140 } y2={ 495 } on={ rp( t, cdnAt, cdnAt + 0.7 ) } color={ C.sky } />
				<FlowLine x1={ 1300 } y1={ 495 } x2={ 1580 } y2={ 495 } on={ rp( t, cdnAt + 0.7, cdnAt + 1.4 ) } color={ C.greenSoft } />
				<div style={ { position: 'absolute', left: 0, right: 0, top: 790, display: 'flex', justifyContent: 'center', opacity: pop( t, cdnAt + 1.6 ) } }>
					<div style={ { display: 'flex', alignItems: 'center', gap: 14, padding: '14px 28px', borderRadius: 999, background: 'rgba(74,222,128,0.12)', border: '1px solid rgba(74,222,128,0.4)', fontFamily: FONT, fontSize: 30, color: C.greenSoft, fontWeight: 700 } }>
						<Icon name="check" size={ 30 } color={ C.greenSoft } stroke={ 2.6 } /> সার্ভারের চাপ কমে, ছবি আসে দ্রুত
					</div>
				</div>
			</div>
			</Shift>
		</AbsoluteFill>
	);
};

/* ================================================================== */
/* 03 R2 free tier                                                      */
/* ================================================================== */
const DualCard: React.FC<{ p: number; t: number }> = ( { p, t } ) => {
	const shine = ( ( t * 0.6 ) % 2 ) - 0.5;
	return (
		<div style={ { width: 520, height: 320, borderRadius: 28, position: 'relative', overflow: 'hidden', background: 'linear-gradient(135deg, #0f766e, #1e3a8a 60%, #312e81)', boxShadow: '0 40px 90px rgba(0,0,0,0.5)', transform: `perspective(1200px) rotateY(${ ( 1 - p ) * -70 }deg) rotateX(8deg)`, opacity: p, fontFamily: SANS } }>
			<div style={ { position: 'absolute', inset: 0, background: `linear-gradient(110deg, transparent ${ shine * 100 }%, rgba(255,255,255,0.25) ${ shine * 100 + 8 }%, transparent ${ shine * 100 + 16 }%)` } } />
			<div style={ { position: 'absolute', left: 36, top: 34, color: '#fff', fontWeight: 800, fontSize: 24, letterSpacing: 2 } }>DUAL CURRENCY</div>
			<div style={ { position: 'absolute', left: 36, top: 104, width: 70, height: 52, borderRadius: 10, background: 'linear-gradient(135deg,#fde68a,#d97706)' } } />
			<div style={ { position: 'absolute', left: 36, top: 190, color: '#e0e7ff', fontFamily: MONO, fontSize: 30, letterSpacing: 4 } }>•••• •••• •••• 4821</div>
			<div style={ { position: 'absolute', right: 34, top: 30, display: 'flex', gap: 10 } }>
				<div style={ { padding: '6px 14px', borderRadius: 8, background: 'rgba(255,255,255,0.18)', color: '#fff', fontWeight: 800, fontSize: 22 } }>৳</div>
				<div style={ { padding: '6px 14px', borderRadius: 8, background: 'rgba(255,255,255,0.18)', color: '#fff', fontWeight: 800, fontSize: 22 } }>$</div>
			</div>
			<div style={ { position: 'absolute', left: 36, bottom: 30, color: '#c7d2fe', fontSize: 18, letterSpacing: 2 } }>YOUR BANK</div>
		</div>
	);
};

export const R2Free: React.FC = () => {
	const t = useT();
	const id = '03-r2-free';
	const s = ( k: number ) => S( id, k );
	const partA = 1 - rp( t, s( 4 ) - 0.3, s( 4 ) + 0.2 );
	const gb = lerp( 0, 10, rp( t, W( id, 1, 'দশ' ), W( id, 1, 'দশ' ) + 1.0 ) );
	return (
		<AbsoluteFill>
			<Background />
			<Sfx at={ W( id, 1, 'দশ' ) } name="pop" volume={ 0.4 } />
			<Sfx at={ W( id, 6, 'টাকাও' ) } name="pop" volume={ 0.4 } />
			<Shift dy={ 70 }>
			<div style={ { opacity: partA } }>
				<div style={ { position: 'absolute', left: 0, right: 0, top: 80, textAlign: 'center', fontFamily: FONT } }>
					<div style={ { display: 'inline-flex', alignItems: 'center', gap: 18, color: C.white, fontSize: 70, fontWeight: 700, opacity: pop( t, 0 ) } }>
						<Icon name="cloud" size={ 76 } color={ CF.orange } stroke={ 2.2 } /> Cloudflare R2
					</div>
				</div>
				<div style={ { position: 'absolute', left: 140, top: 300, width: 640, height: 420, borderRadius: 36, background: 'linear-gradient(160deg, rgba(243,128,32,0.18), rgba(243,128,32,0.04))', border: '1px solid rgba(243,128,32,0.4)', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', opacity: pop( t, s( 1 ) ), fontFamily: FONT } }>
					<div style={ { fontFamily: SANS, fontSize: 200, fontWeight: 800, color: C.white, lineHeight: 1, letterSpacing: -6 } }>
						{ gb.toFixed( 0 ) }<span style={ { fontSize: 100, color: CF.orange } }> GB</span>
					</div>
					<div style={ { fontSize: 44, color: C.text, marginTop: 14 } }>স্টোরেজ / প্রতি মাসে</div>
					<div style={ { marginTop: 18, padding: '8px 26px', borderRadius: 999, background: C.green, color: '#fff', fontSize: 38, fontWeight: 700, transform: `scale(${ pop( t, W( id, 1, 'একদম' ) ) })` } }>একদম ফ্রি</div>
				</div>
				<div style={ { position: 'absolute', left: 860, top: 300, width: 920, display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 26 } }>
					{ [
						[ '১০ লাখ', 'আপলোড অপারেশন', W( id, 2, 'দশ' ), 'cloudUp' ],
						[ '১ কোটি', 'রিড অপারেশন', W( id, 2, 'এক কোটি' ), 'refresh' ],
					].map( ( [ big, sub, at, icon ] ) => (
						<div key={ big as string } style={ { opacity: pop( t, at as number ), transform: `translateY(${ ( 1 - pop( t, at as number ) ) * 30 }px)`, padding: 30, borderRadius: 28, background: 'rgba(255,255,255,0.06)', border: '1px solid rgba(255,255,255,0.12)', fontFamily: FONT } }>
							<Icon name={ icon as 'cloudUp' } size={ 40 } color={ C.sky } />
							<div style={ { fontSize: 66, fontWeight: 700, color: C.white, marginTop: 10 } }>{ big as string }</div>
							<div style={ { fontSize: 28, color: C.muted } }>{ sub as string } · ফ্রি</div>
						</div>
					) ) }
					<div style={ { gridColumn: '1 / span 2', opacity: pop( t, W( id, 3, 'ডাউনলোডের' ) ), padding: '26px 30px', borderRadius: 28, background: 'linear-gradient(160deg, rgba(74,222,128,0.18), rgba(74,222,128,0.04))', border: '1px solid rgba(74,222,128,0.45)', display: 'flex', alignItems: 'center', gap: 28, fontFamily: FONT } }>
						<div style={ { fontFamily: SANS, fontSize: 110, fontWeight: 800, color: C.greenSoft, lineHeight: 1 } }>$0</div>
						<div>
							<div style={ { fontSize: 38, color: C.white, fontWeight: 700 } }>ডাউনলোড (egress) ফ্রি</div>
							<div style={ { fontSize: 26, color: C.muted, marginTop: 4 } }>ভিজিটর যত ছবিই দেখুক</div>
						</div>
						<div style={ { marginLeft: 'auto', display: 'flex', gap: 10 } }>
							{ [ 0, 1, 2 ].map( ( i ) => (
								<div key={ i } style={ { transform: `translateY(${ ( ( t * 60 + i * 20 ) % 40 ) - 20 }px)`, opacity: 0.8 } }>
									<Icon name="cloudDown" size={ 40 } color={ C.greenSoft } />
								</div>
							) ) }
						</div>
					</div>
				</div>
			</div>
			{ /* Payment method: dual currency card */ }
			<div style={ { opacity: rp( t, s( 4 ) - 0.2, s( 4 ) + 0.3 ) } }>
				<div style={ { position: 'absolute', left: 140, top: 150, width: 860 } }>
					<Headline t={ t } at={ s( 4 ) } text="R2 চালু করতে লাগবে একটা পেমেন্ট মেথড" size={ 62 } />
					<div style={ { marginTop: 40, display: 'flex', flexDirection: 'column', gap: 20 } }>
						<Note t={ t } at={ W( id, 5, 'ডুয়েল' ) } icon="check" tone="ok" title="ব্যাংকের ডুয়েল কারেন্সি কার্ড যোগ করলেই হবে" text="অ্যাকাউন্ট যাচাইয়ের জন্য" />
						<Note t={ t } at={ s( 6 ) } icon="shield" tone="ok" title="ফ্রি সীমার মধ্যে থাকলে ৳০ কাটবে না" text="১০ GB-র বেশি হলে শুধু বাড়তি অংশের বিল আসবে" />
					</div>
				</div>
				<div style={ { position: 'absolute', left: 1150, top: 300 } }>
					<DualCard p={ pop( t, W( id, 5, 'ব্যাংকের' ) - 0.3, 0.9 ) } t={ t } />
					<div style={ { position: 'absolute', left: 360, top: -40, width: 200, height: 200, borderRadius: 100, background: C.green, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', boxShadow: '0 20px 60px rgba(34,197,94,0.5)', transform: `scale(${ pop( t, W( id, 6, 'টাকাও' ) ) }) rotate(-10deg)`, fontFamily: FONT, color: '#fff' } }>
						<div style={ { fontSize: 70, fontWeight: 700, lineHeight: 1 } }>৳০</div>
						<div style={ { fontSize: 24, fontWeight: 600 } }>চার্জ</div>
					</div>
				</div>
			</div>
			</Shift>
		</AbsoluteFill>
	);
};

/* ================================================================== */
/* 04 Roadmap                                                           */
/* ================================================================== */
export const Roadmap: React.FC = () => {
	const t = useT();
	const id = '04-roadmap';
	const s1 = S( id, 1 );
	const s2 = S( id, 2 );
	const steps: [ string, string, 'cloud' | 'globe' | 'lock' | 'wp' | 'cloudUp' ][] = [
		[ 'Bucket বানানো', 'Cloudflare', 'cloud' ],
		[ 'ডোমেইন যোগ', 'নিজের CDN', 'globe' ],
		[ 'চাবি নেওয়া', 'API token', 'lock' ],
		[ 'প্লাগইন সেটআপ', 'OmniOffloader', 'wp' ],
		[ 'ছবি পাঠানো', 'Bulk Offload', 'cloudUp' ],
	];
	return (
		<AbsoluteFill>
			<Background />
			<Shift dy={ 40 }>
			<div style={ { position: 'absolute', left: 0, right: 0, top: 150, textAlign: 'center' } }>
				<Headline t={ t } at={ 0 } text="মাত্র পাঁচ ধাপে" size={ 92 } accent="পাঁচ" />
			</div>
			<div style={ { position: 'absolute', left: 90, right: 90, top: 400, display: 'flex', gap: 26 } }>
				{ steps.map( ( [ title, sub, icon ], i ) => {
					const at = W( id, 1, [ 'Bucket', 'ডোমেইন', 'চাবি', 'প্লাগইন', 'ছবি' ][ i ] );
					const p = pop( t, at );
					const go = i === 0 ? rp( t, s2, s2 + 0.5 ) : 0;
					return (
						<div key={ title } style={ { flex: 1, padding: '34px 22px', borderRadius: 28, textAlign: 'center', fontFamily: FONT, background: go > 0 ? `rgba(26,95,214,${ 0.25 + 0.35 * go })` : 'rgba(255,255,255,0.06)', border: `1px solid ${ go > 0 ? C.blueLight : 'rgba(255,255,255,0.12)' }`, opacity: Math.min( 1, p * 1.3 ) * ( t > s2 && i > 0 ? 0.55 : 1 ), transform: `translateY(${ ( 1 - p ) * 40 }px) scale(${ 1 + go * 0.06 })`, boxShadow: go > 0 ? '0 30px 80px rgba(26,95,214,0.5)' : 'none' } }>
							<div style={ { fontFamily: SANS, fontSize: 30, fontWeight: 800, color: C.sky } }>{ bn( i + 1 ) }</div>
							<div style={ { width: 92, height: 92, margin: '16px auto', borderRadius: 26, background: `linear-gradient(150deg, ${ i === 0 || i === 1 || i === 2 ? CF.orange : C.blueLight }, ${ C.blueDeep })`, display: 'flex', alignItems: 'center', justifyContent: 'center' } }>
								<Icon name={ icon } size={ 50 } color="#fff" stroke={ 1.8 } />
							</div>
							<div style={ { color: C.white, fontSize: 33, fontWeight: 700 } }>{ title }</div>
							<div style={ { color: C.muted, fontSize: 22, marginTop: 6, fontFamily: SANS } }>{ sub }</div>
						</div>
					);
				} ) }
			</div>
			<div style={ { position: 'absolute', left: 0, right: 0, top: 820, textAlign: 'center', fontFamily: FONT, fontSize: 44, fontWeight: 700, color: C.sky, opacity: pop( t, s2 ), display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 14 } }>চলুন শুরু করি <Icon name="arrowRight" size={ 44 } color={ C.sky } stroke={ 2.6 } /></div>
			</Shift>
		</AbsoluteFill>
	);
};

/* ================================================================== */
/* 05 Step 1: bucket                                                    */
/* ================================================================== */
export const Bucket: React.FC = () => {
	const t = useT();
	const id = '05-bucket';
	const s1 = S( id, 1 );
	const s2 = S( id, 2 );
	const s3 = S( id, 3 );
	const navAt = W( id, 1, 'R2' );
	const createAt = W( id, 2, 'চাপুন' );
	const typeAt = W( id, 2, 'my-site-media' ) - 0.4;
	const submitAt = W( id, 2, 'ক্লিক' );
	const form = t > createAt + 0.2;
	const done = t > submitAt + 0.3;
	return (
		<AbsoluteFill>
			<Background />
			<Sfx at={ navAt } name="click" />
			<Sfx at={ createAt } name="click" />
			<Sfx at={ submitAt } name="click" />
			<Sfx at={ submitAt + 0.3 } name="pop" volume={ 0.35 } />
			<StepTracker t={ t } step={ 1 } />
			<CfWindow t={ t } appear={ rp( t, -0.4, 0.2 ) } nav={ t > navAt ? 'R2 Object Storage' : 'Account Home' } crumb={ done ? 'R2 Object Storage / my-site-media' : 'R2 Object Storage' }>
				{ ! form ? (
					<div>
						<div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between' } }>
							<div style={ { fontSize: 30, fontWeight: 800 } }>R2 Object Storage</div>
							<CfButton label="+ Create bucket" press={ t > createAt - 0.06 && t < createAt + 0.18 } />
						</div>
						<div style={ { marginTop: 26, padding: 24, borderRadius: 12, background: CF.soft, color: '#6b7280', fontSize: 18 } }>Store data without egress fees.</div>
						<div style={ { marginTop: 26, borderRadius: 12, border: `1px solid ${ CF.line }` } }>
							{ [ 'Name', 'Location', 'Created' ].map( ( h, i ) => (
								<div key={ h } style={ { display: 'flex', padding: '14px 18px', borderBottom: i < 2 ? `1px solid ${ CF.line }` : 'none', color: i ? '#9ca3af' : '#374151', fontWeight: i ? 400 : 700, fontSize: 16 } }>{ i ? 'No buckets yet' : h }</div>
							) ) }
						</div>
					</div>
				) : ! done ? (
					<div style={ { maxWidth: 640 } }>
						<div style={ { fontSize: 30, fontWeight: 800, marginBottom: 22 } }>Create a bucket</div>
						<CfField label="Bucket name" value={ typed( t, typeAt, 'my-site-media', 9 ) } focus={ t > typeAt - 0.3 && t < submitAt } mono />
						<CfField label="Location" value="Automatic (recommended)" />
						<CfField label="Default Storage Class" value="Standard" />
						<CfButton label="Create bucket" press={ t > submitAt - 0.06 && t < submitAt + 0.18 } style={ { marginTop: 8 } } />
					</div>
				) : (
					<div>
						<div style={ { fontSize: 30, fontWeight: 800, fontFamily: MONO } }>my-site-media</div>
						<div style={ { display: 'flex', gap: 18, marginTop: 14 } }>
							{ [ 'Objects', 'Metrics', 'Settings' ].map( ( n, i ) => (
								<div key={ n } style={ { fontSize: 17, fontWeight: 700, color: i === 0 ? CF.blue : '#6b7280', paddingBottom: 8, borderBottom: i === 0 ? `2px solid ${ CF.blue }` : 'none' } }>{ n }</div>
							) ) }
						</div>
						<div style={ { marginTop: 40, display: 'flex', alignItems: 'center', gap: 14, padding: '18px 22px', borderRadius: 12, background: '#ecfdf5', color: '#047857', fontSize: 19, fontWeight: 700, transform: `scale(${ pop( t, submitAt + 0.3 ) })`, transformOrigin: 'left' } }>
							<Icon name="check" size={ 26 } color="#047857" stroke={ 3 } /> Bucket created
						</div>
					</div>
				) }
				<Pointer
					t={ t }
					path={ [
						{ at: navAt, x: -150, y: 150, click: true },
						{ at: createAt, x: 780, y: 20, click: true },
						{ at: submitAt, x: 60, y: 300, click: true },
					] }
				/>
			</CfWindow>
			<Side>
				<StepTitle t={ t } n={ 1 } title="Bucket বানান" />
				<Note t={ t } at={ s1 } icon="cloud" title="R2 Object Storage খুলুন" text="বাম পাশের মেনু থেকে" />
				<Note t={ t } at={ s2 } icon="check" title="Create bucket" text="নাম দিন, যেমন my-site-media" />
				<Note t={ t } at={ s3 } icon="check" tone="ok" title="ক্লাউড স্টোরেজ তৈরি!" />
			</Side>
		</AbsoluteFill>
	);
};

/* ================================================================== */
/* 06 Step 2: custom domain                                             */
/* ================================================================== */
const SiteGrid: React.FC<{ broken: number; x: number; y: number }> = ( { broken, x, y } ) => (
	<div style={ { position: 'absolute', left: x, top: y, width: 560, padding: 22, borderRadius: 18, background: '#fff', boxShadow: '0 30px 80px rgba(0,0,0,0.5)', fontFamily: SANS } }>
		<div style={ { height: 14, width: 180, borderRadius: 7, background: '#e5e7eb', marginBottom: 16 } } />
		<div style={ { display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 12 } }>
			{ Array.from( { length: 6 } ).map( ( _, i ) => {
				const b = Math.min( 1, Math.max( 0, broken * 6 - i ) );
				return (
					<div key={ i } style={ { position: 'relative', height: 120, borderRadius: 12, overflow: 'hidden' } }>
						<FileTile size={ 160 } hue={ 20 + i * 45 } style={ { position: 'absolute', inset: 0, width: '100%', height: '100%', borderRadius: 12, border: 'none', opacity: 1 - b } } />
						<div style={ { position: 'absolute', inset: 0, borderRadius: 12, border: '2px dashed #fca5a5', background: '#fef2f2', display: 'flex', alignItems: 'center', justifyContent: 'center', opacity: b } }>
							<Icon name="image" size={ 40 } color="#ef4444" />
						</div>
					</div>
				);
			} ) }
		</div>
	</div>
);

export const Domain: React.FC = () => {
	const t = useT();
	const id = '06-domain';
	const s = ( k: number ) => S( id, k );
	const showCf = rp( t, s( 3 ) - 0.3, s( 3 ) + 0.2 );
	const connectAt = W( id, 3, 'চাপুন' ) - 0.3;
	const typeAt = W( id, 3, 'media.yourdomain.com' ) - 0.4;
	const addAt = s( 4 ) + 0.4;
	const broken = rp( t, W( id, 1, 'ভাঙা' ) - 0.2, W( id, 1, 'ভাঙা' ) + 1.0 ) * ( 1 - rp( t, s( 5 ), s( 5 ) + 1.2 ) );
	return (
		<AbsoluteFill>
			<Background />
			<Sfx at={ connectAt } name="click" />
			<Sfx at={ addAt } name="click" />
			<Sfx at={ addAt + 0.1 } name="pop" volume={ 0.35 } />
			<StepTracker t={ t } step={ 2 } />
			{ /* The trap */ }
			<div style={ { opacity: 1 - showCf, position: 'absolute', inset: 0, transform: 'translateY(90px)' } }>
				<div style={ { position: 'absolute', left: 120, top: 170, width: 700 } }>
					<div style={ { display: 'inline-flex', alignItems: 'center', gap: 14, padding: '10px 22px', borderRadius: 999, background: 'rgba(245,158,11,0.15)', border: '1px solid rgba(245,158,11,0.5)', color: '#fbbf24', fontFamily: FONT, fontSize: 30, fontWeight: 700, opacity: pop( t, W( id, 0, 'ফাঁদ' ) - 0.3 ) } }><Icon name="warning" size={ 30 } color="#fbbf24" stroke={ 2.2 } /> প্রথম ফাঁদ</div>
					<Headline t={ t } at={ s( 1 ) } text="ডোমেইন না দিলে সব ছবি ভাঙা" size={ 70 } style={ { marginTop: 24 } } accent="ভাঙা" />
					<div style={ { marginTop: 30, opacity: pop( t, s( 2 ) ), display: 'flex', alignItems: 'center', gap: 16, padding: '18px 24px', borderRadius: 18, background: 'rgba(239,68,68,0.12)', border: '1px solid rgba(239,68,68,0.4)', fontFamily: FONT, fontSize: 30, color: C.white } }>
						<Icon name="lock" size={ 36 } color="#f87171" /> R2 bucket ডিফল্টভাবে পাবলিক নয়
					</div>
				</div>
				<SiteGrid broken={ broken } x={ 1180 } y={ 260 } />
			</div>
			{ /* Fix in the dashboard */ }
			<div style={ { opacity: showCf * ( 1 - rp( t, s( 5 ) - 0.3, s( 5 ) + 0.2 ) ) } }>
				<CfWindow t={ t } crumb="R2 Object Storage / my-site-media / Settings">
					<div style={ { fontSize: 28, fontWeight: 800, fontFamily: MONO } }>my-site-media</div>
					<div style={ { display: 'flex', gap: 18, marginTop: 14 } }>
						{ [ 'Objects', 'Metrics', 'Settings' ].map( ( n, i ) => (
							<div key={ n } style={ { fontSize: 17, fontWeight: 700, color: i === 2 ? CF.blue : '#6b7280', paddingBottom: 8, borderBottom: i === 2 ? `2px solid ${ CF.blue }` : 'none' } }>{ n }</div>
						) ) }
					</div>
					<div style={ { marginTop: 24, padding: 22, borderRadius: 12, border: `1px solid ${ CF.line }` } }>
						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } }>
							<div style={ { fontSize: 21, fontWeight: 800 } }>Custom Domains</div>
							<CfButton label="+ Connect Domain" primary={ false } press={ t > connectAt - 0.06 && t < connectAt + 0.18 } />
						</div>
						{ t > connectAt + 0.3 ? (
							<div style={ { marginTop: 18 } }>
								<CfField label="Domain" value={ typed( t, typeAt, 'media.yourdomain.com', 12 ) } focus={ t < addAt } mono />
								{ t > addAt ? (
									<div style={ { display: 'flex', alignItems: 'center', gap: 12, padding: '14px 18px', borderRadius: 10, background: '#ecfdf5', color: '#047857', fontSize: 17, fontWeight: 700, transform: `scale(${ pop( t, addAt ) })`, transformOrigin: 'left' } }>
										<Icon name="check" size={ 22 } color="#047857" stroke={ 3 } /> media.yourdomain.com · Active · DNS record added
									</div>
								) : (
									<CfButton label="Connect" press={ t > addAt - 0.06 && t < addAt + 0.18 } />
								) }
							</div>
						) : null }
					</div>
					<div style={ { marginTop: 18, padding: 18, borderRadius: 12, border: `1px solid ${ CF.line }`, color: '#6b7280', fontSize: 16 } }>Public Development URL (r2.dev) · Disabled</div>
					<Pointer t={ t } path={ [ { at: connectAt, x: 690, y: 150, click: true }, { at: addAt, x: 40, y: 330, click: true } ] } />
				</CfWindow>
				<Side>
					<StepTitle t={ t } n={ 2 } title="Custom domain যোগ করুন" />
					<Note t={ t } at={ s( 3 ) + 0.3 } icon="globe" title="Settings → Custom Domains" text="Connect Domain চাপুন" />
					<Note t={ t } at={ typeAt } icon="check" title="media.yourdomain.com" />
					<Note t={ t } at={ s( 4 ) } icon="check" tone="ok" title="DNS নিজে থেকেই সেট" text="ডোমেইন Cloudflare-এ থাকলে" />
				</Side>
			</div>
			{ /* Fixed: this is your CDN */ }
			<div style={ { opacity: rp( t, s( 5 ) - 0.2, s( 5 ) + 0.3 ), position: 'absolute', inset: 0, transform: 'translateY(90px)' } }>
				<SiteGrid broken={ broken } x={ 200 } y={ 260 } />
				<div style={ { position: 'absolute', left: 900, top: 330, width: 860 } }>
					<Headline t={ t } at={ s( 5 ) } text="এই ডোমেইনটাই আপনার CDN" size={ 72 } accent="CDN" />
					<div style={ { marginTop: 30, display: 'inline-flex', alignItems: 'center', gap: 14, padding: '16px 26px', borderRadius: 16, background: 'rgba(74,222,128,0.12)', border: '1px solid rgba(74,222,128,0.4)', fontFamily: MONO, fontSize: 30, color: C.greenSoft, opacity: pop( t, s( 5 ) + 1 ) } }>
						<Icon name="globe" size={ 32 } color={ C.greenSoft } /> media.yourdomain.com
					</div>
				</div>
			</div>
		</AbsoluteFill>
	);
};

/* ================================================================== */
/* 07 Step 3: API token                                                 */
/* ================================================================== */
export const Token: React.FC = () => {
	const t = useT();
	const id = '07-token';
	const s = ( k: number ) => S( id, k );
	const createAt = W( id, 1, 'চাপুন' );
	const permAt = W( id, 2, 'Object' );
	const bucketAt = W( id, 2, 'bucket-টা' );
	const result = t > s( 3 ) - 0.2;
	const copyAt = W( id, 5, 'কপি' );
	const form = t > createAt + 0.2;
	return (
		<AbsoluteFill>
			<Background />
			<Sfx at={ createAt } name="click" />
			<Sfx at={ permAt } name="click" />
			<Sfx at={ bucketAt } name="click" />
			<Sfx at={ s( 3 ) - 0.2 } name="pop" volume={ 0.35 } />
			<StepTracker t={ t } step={ 3 } />
			<CfWindow t={ t } appear={ rp( t, -0.4, 0.2 ) } crumb="R2 Object Storage / API Tokens">
				{ ! form ? (
					<div>
						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } }>
							<div style={ { fontSize: 28, fontWeight: 800 } }>Manage API Tokens</div>
							<CfButton label="Create API Token" press={ t > createAt - 0.06 && t < createAt + 0.18 } />
						</div>
						<div style={ { marginTop: 26, padding: 22, borderRadius: 12, background: CF.soft, color: '#6b7280', fontSize: 17 } }>No API tokens yet.</div>
					</div>
				) : ! result ? (
					<div style={ { maxWidth: 760 } }>
						<div style={ { fontSize: 28, fontWeight: 800, marginBottom: 18 } }>Create API Token</div>
						<CfField label="Token name" value="omnioffloader" />
						<div style={ { fontSize: 15, fontWeight: 700, color: '#374151', marginBottom: 10 } }>Permissions</div>
						{ [ 'Admin Read & Write', 'Object Read & Write', 'Object Read only' ].map( ( p, i ) => {
							const on = i === 1 && t > permAt;
							return (
								<div key={ p } style={ { display: 'flex', alignItems: 'center', gap: 12, padding: '12px 16px', marginBottom: 8, borderRadius: 10, border: `1.5px solid ${ on ? CF.blue : CF.line }`, background: on ? '#eaf1fd' : '#fff', fontSize: 17, fontWeight: on ? 700 : 500 } }>
									<div style={ { width: 18, height: 18, borderRadius: 9, border: `2px solid ${ on ? CF.blue : '#9ca3af' }`, display: 'flex', alignItems: 'center', justifyContent: 'center' } }>
										{ on ? <div style={ { width: 9, height: 9, borderRadius: 5, background: CF.blue } } /> : null }
									</div>
									{ p }
								</div>
							);
						} ) }
						<div style={ { fontSize: 15, fontWeight: 700, color: '#374151', margin: '16px 0 10px' } }>Specify bucket(s)</div>
						<div style={ { padding: '12px 16px', borderRadius: 10, border: `1.5px solid ${ t > bucketAt ? CF.blue : CF.line }`, fontSize: 17 } }>
							{ t > bucketAt ? <span>Apply to specific buckets only: <b style={ { fontFamily: MONO } }>my-site-media</b></span> : 'Apply to all buckets in this account' }
						</div>
					</div>
				) : (
					<div style={ { maxWidth: 820 } }>
						<div style={ { display: 'flex', alignItems: 'center', gap: 12, padding: '14px 18px', borderRadius: 10, background: '#ecfdf5', color: '#047857', fontSize: 18, fontWeight: 700, marginBottom: 22 } }>
							<Icon name="check" size={ 22 } color="#047857" stroke={ 3 } /> Token created
						</div>
						{ [ [ 'Access Key ID', 'f3a9c2e7b5d84f1e6a0c9b2d7e4f8a1c' ], [ 'Secret Access Key', '9d8e7f6a5b4c3d2e1f0a9b8c7d6e5f4a3b2c1d0e9f8a7b6c' ], [ 'Endpoint (S3 API)', 'https://<account-id>.r2.cloudflarestorage.com' ] ].map( ( [ label, val ], i ) => (
							<div key={ label } style={ { position: 'relative', opacity: pop( t, W( id, 3, [ 'Access', 'Secret', 'endpoint' ][ i ] ) ) } }>
								<CfField label={ label } value={ val } mono blur={ i < 2 } />
								<div style={ { position: 'absolute', right: 12, top: 38, padding: '6px 12px', borderRadius: 6, background: t > copyAt + i * 0.5 ? '#ecfdf5' : CF.soft, color: t > copyAt + i * 0.5 ? '#047857' : '#374151', fontSize: 14, fontWeight: 700, display: 'flex', alignItems: 'center', gap: 5 } }>{ t > copyAt + i * 0.5 ? <>Copied <Icon name="check" size={ 14 } color="#047857" stroke={ 3 } /></> : 'Copy' }</div>
							</div>
						) ) }
						<div style={ { marginTop: 8, padding: '14px 18px', borderRadius: 10, background: '#fff7ed', border: '1px solid #fdba74', color: '#9a3412', fontSize: 17, fontWeight: 700, opacity: pop( t, s( 4 ) ), display: 'flex', alignItems: 'center', gap: 10 } }><Icon name="warning" size={ 20 } color="#9a3412" stroke={ 2.2 } /> The secret is shown only once.</div>
					</div>
				) }
				<Pointer t={ t } path={ [ { at: createAt, x: 760, y: 20, click: true }, { at: permAt, x: 60, y: 245, click: true }, { at: bucketAt, x: 300, y: 432, click: true } ] } />
			</CfWindow>
			<Side>
				<StepTitle t={ t } n={ 3 } title="API token নিন" />
				<Note t={ t } at={ s( 1 ) } icon="lock" title="Manage API Tokens → Create" />
				<Note t={ t } at={ s( 2 ) } icon="check" title="Object Read & Write" text="শুধু এই bucket-এর জন্য" />
				<Note t={ t } at={ s( 3 ) } icon="check" tone="ok" title="পাবেন ৩টা জিনিস" text="Access Key ID · Secret Access Key · endpoint" />
				<Note t={ t } at={ s( 4 ) } icon="bolt" tone="warn" title="Secret Key একবারই দেখায়!" text="এখনই কপি করে নিরাপদে রাখুন" />
			</Side>
		</AbsoluteFill>
	);
};

/* ================================================================== */
/* 08 Step 4: plugin                                                    */
/* ================================================================== */
export const Plugin: React.FC = () => {
	const t = useT();
	const id = '08-plugin';
	const s = ( k: number ) => S( id, k );
	const typeAt = W( id, 1, 'OmniOffloader' ) - 0.5;
	const installAt = W( id, 2, 'Install' );
	const activateAt = W( id, 2, 'Activate' );
	const settingsAt = s( 3 ) - 0.2;
	const credAt = W( id, 3, 'Access' );
	const cdnAt = W( id, 3, 'bucket-এর' );
	const testAt = W( id, 4, 'চাপুন' );
	return (
		<AbsoluteFill>
			<Background />
			<Sfx at={ installAt } name="click" />
			<Sfx at={ activateAt } name="click" />
			<Sfx at={ testAt } name="click" />
			<Sfx at={ testAt + 0.6 } name="pop" volume={ 0.4 } />
			<StepTracker t={ t } step={ 4 } />
			<Screen
				t={ t }
				x={ 44 }
				y={ 120 }
				width={ 1260 }
				appear={ rp( t, -0.4, 0.2 ) }
				url={ t < settingsAt ? 'example.com/wp-admin/plugin-install.php' : `${ ADMIN }settings` }
				shots={ [
					{ src: 'add-plugins', at: 0 },
					{ src: 'add-plugins-installing', at: installAt },
					{ src: 'add-plugins-activate', at: installAt + 0.9 },
					{ src: 'settings', at: settingsAt },
					{ src: 'settings-cdn', at: cdnAt },
				] }
				covers={ [ { rect: [ 170, 190, 1270, 360 ], until: typeAt + 1.3, color: '#f0f0f1' }, { rect: [ 648, 396, 110, 34 ], until: testAt + 0.6 } ] }
				typing={ t < settingsAt ? [ { at: typeAt, dur: 1.0, rect: 'add-plugins:search', text: 'OmniOffloader' } ] : [] }
				cam={ [
					{ at: 0, s: 1 },
					{ at: typeAt + 1.2, s: 1.5, rect: 'add-plugins:card' },
					{ at: settingsAt, s: 1.3, rect: 'settings:providers' },
					{ at: credAt - 0.4, s: 1.25, rect: [ 497, 600, 886, 260 ] },
					{ at: cdnAt, s: 1.3, rect: [ 497, 180, 886, 260 ] },
				] }
				marks={ [
					{ at: W( id, 3, 'Cloudflare' ), until: credAt - 0.4, rect: [ 702, 251, 396, 90 ], label: 'Cloudflare R2', pos: 'top', spot: true },
					{ at: credAt, until: cdnAt - 0.2, rect: [ 497, 520, 886, 330 ], label: 'Access Key · Secret · Endpoint', pos: 'top', spot: true },
					{ at: cdnAt + 0.3, until: testAt - 0.3, rect: [ 497, 214, 886, 140 ], label: 'Bucket · Custom domain', pos: 'top', spot: true },
					{ at: testAt + 0.6, rect: [ 497, 388, 270, 46 ], label: 'Connected', pos: 'bottom', spot: true },
				] }
				clicks={ [ { at: installAt, rect: 'add-plugins:button' }, { at: activateAt, rect: 'add-plugins:button' }, { at: testAt, rect: [ 497, 394, 140, 38 ] } ] }
			/>
			<Side>
				<StepTitle t={ t } n={ 4 } title="প্লাগইন সেটআপ" />
				<Note t={ t } at={ s( 1 ) } icon="check" title="Add New Plugin → OmniOffloader" />
				<Note t={ t } at={ s( 2 ) } icon="check" title="Install → Activate" />
				<Note t={ t } at={ s( 3 ) } icon="cloud" title="Settings → Cloudflare R2" text="Key, Secret, endpoint, bucket, custom domain" />
				<Note t={ t } at={ s( 5 ) } icon="check" tone="ok" title="সবুজ Connected = কাজ শেষ!" />
			</Side>
		</AbsoluteFill>
	);
};

/* ================================================================== */
/* 09 Step 5: offload                                                   */
/* ================================================================== */
export const Offload: React.FC = () => {
	const t = useT();
	const id = '09-offload';
	const s = ( k: number ) => S( id, k );
	const screenIn = rp( t, s( 2 ) - 0.3, s( 2 ) + 0.3 );
	const startAt = W( id, 4, 'চাপলেই' );
	const cli = s( 5 );
	return (
		<AbsoluteFill>
			<Background />
			<Sfx at={ startAt } name="click" />
			<StepTracker t={ t } step={ 5 } />
			{ /* New uploads go automatically */ }
			<div style={ { opacity: 1 - screenIn, position: 'absolute', inset: 0, transform: 'translateY(60px)' } }>
				<div style={ { position: 'absolute', left: 0, right: 0, top: 200, textAlign: 'center' } }>
					<Headline t={ t } at={ W( id, 1, 'নতুন' ) } text="নতুন ছবি? অটোমেটিক ক্লাউডে" size={ 76 } accent="অটোমেটিক" />
				</div>
				<Node icon="wp" label="Upload" p={ pop( t, s( 1 ) + 0.5 ) } x={ 640 } y={ 600 } />
				<Node icon="cloud" label="R2" p={ pop( t, W( id, 1, 'ক্লাউডে' ) ) } x={ 1280 } y={ 600 } color={ CF.orange } />
				<FlowLine x1={ 720 } y1={ 575 } x2={ 1200 } y2={ 575 } on={ rp( t, W( id, 1, 'ক্লাউডে' ), W( id, 1, 'ক্লাউডে' ) + 0.8 ) } color={ C.sky } />
			</div>
			<div style={ { opacity: screenIn } }>
				<Screen
					t={ t }
					x={ 44 }
					y={ 120 }
					width={ 1260 }
					url={ `${ ADMIN }offload` }
					shots={ [
						{ src: 'offload', at: 0 },
						{ src: 'offload-run-1', at: startAt + 0.3 },
						{ src: 'offload-run-2', at: startAt + 1.6 },
						{ src: 'offload-run-3', at: startAt + 2.9 },
						{ src: 'offload-run-4', at: startAt + 4.2 },
					] }
					cam={ [ { at: 0, s: 1.15, rect: [ 497, 225, 886, 300 ] }, { at: s( 3 ) - 0.3, s: 1.3, rect: 'offload:scope' }, { at: startAt + 0.2, s: 1.15, rect: [ 497, 560, 886, 300 ] } ] }
					marks={ [
						{ at: s( 3 ), until: startAt - 0.7, rect: 'offload:scope', label: 'প্রথমবার ১০টা দিয়ে টেস্ট', pos: 'top', spot: true },
						{ at: startAt + 0.8, until: cli - 0.2, rect: [ 497, 552, 886, 150 ], label: 'একের পর এক ক্লাউডে', pos: 'top', spot: true },
					] }
					clicks={ [ { at: startAt, rect: 'offload:start' } ] }
				/>
				<Side>
					<StepTitle t={ t } n={ 5 } title="পুরনো ছবি পাঠান" />
					<Note t={ t } at={ s( 2 ) } icon="check" title="Offload পেজ" />
					<Note t={ t } at={ s( 3 ) } icon="check" title="আগে ১০টা, তারপর বাকিগুলো" />
					<Note t={ t } at={ s( 4 ) } icon="cloud" tone="ok" title="Start Bulk Offload" text="ব্যাকগ্রাউন্ডে চলে" />
					<div style={ { opacity: pop( t, cli ), transform: `translateY(${ ( 1 - pop( t, cli ) ) * 20 }px)`, borderRadius: 16, overflow: 'hidden', border: '1px solid rgba(255,255,255,0.14)' } }>
						<div style={ { background: '#1b2335', padding: '8px 16px', color: '#9fb0d0', fontSize: 18, fontWeight: 600, fontFamily: FONT } }>৫০০০+ ফাইল? WP-CLI</div>
						<div style={ { background: 'rgba(6,10,20,0.96)', padding: '14px 18px', fontFamily: MONO, fontSize: 19, color: '#d6e2ff' } }>
							<span style={ { color: C.greenSoft } }>$ </span>
							{ typed( t, cli + 0.4, 'wp omnioffloader offload --all', 22 ) }
						</div>
					</div>
				</Side>
			</div>
		</AbsoluteFill>
	);
};

/* ================================================================== */
/* 10 Result                                                            */
/* ================================================================== */
const EDGES = [ [ 300, 330 ], [ 420, 470 ], [ 560, 300 ], [ 820, 280 ], [ 900, 420 ], [ 1040, 520 ], [ 1180, 360 ], [ 1320, 300 ], [ 1450, 450 ], [ 1580, 620 ], [ 700, 600 ], [ 520, 640 ] ];

export const Result: React.FC = () => {
	const t = useT();
	const f = useCurrentFrame();
	const id = '10-result';
	const s = ( k: number ) => S( id, k );
	const morph = rp( t, W( id, 1, 'media.yourdomain.com' ) - 0.2, W( id, 1, 'media.yourdomain.com' ) + 0.6 );
	const mapIn = rp( t, s( 2 ) - 0.3, s( 2 ) + 0.3 );
	const visitor = [ 1000, 560 ];
	const nearest = EDGES.reduce( ( a, e ) => ( Math.hypot( e[ 0 ] - visitor[ 0 ], e[ 1 ] - visitor[ 1 ] ) < Math.hypot( a[ 0 ] - visitor[ 0 ], a[ 1 ] - visitor[ 1 ] ) ? e : a ) );
	return (
		<AbsoluteFill>
			<Background />
			<Sfx at={ W( id, 1, 'media.yourdomain.com' ) } name="pop" volume={ 0.35 } />
			<Shift dy={ 40 }>
			<div style={ { position: 'absolute', left: 0, right: 0, top: 90, textAlign: 'center' } }>
				<div style={ { display: 'inline-flex', alignItems: 'center', gap: 18 } }>
					<Headline t={ t } at={ 0 } text="এবার আসল মজা" size={ 70 } />
					<div style={ { opacity: pop( t, 0.3 ), transform: `rotate(${ Math.sin( t * 3 ) * 8 }deg)` } }>
						<Icon name="sparkles" size={ 60 } color="#fbbf24" stroke={ 2 } />
					</div>
				</div>
			</div>
			{ /* URL morph */ }
			<div style={ { position: 'absolute', left: 160, right: 160, top: 250, opacity: pop( t, s( 1 ) ) * ( 1 - mapIn ), transform: `translateY(${ -mapIn * 60 }px)` } }>
				<div style={ { fontFamily: FONT, fontSize: 28, color: C.muted, marginBottom: 14 } }>ছবির ঠিকানা</div>
				<div style={ { padding: '26px 32px', borderRadius: 20, background: 'rgba(0,0,0,0.35)', border: '1px solid rgba(255,255,255,0.12)', fontFamily: MONO, fontSize: 38, color: C.text, position: 'relative', height: 104, boxSizing: 'border-box' } }>
					<div style={ { position: 'absolute', left: 32, top: 26, opacity: 1 - morph, filter: `blur(${ morph * 8 }px)`, textDecoration: morph > 0.1 ? 'line-through' : 'none', color: '#fca5a5' } }>https://example.com/wp-content/uploads/2026/10/photo.jpg</div>
					<div style={ { position: 'absolute', left: 32, top: 26, opacity: morph, filter: `blur(${ ( 1 - morph ) * 8 }px)` } }>
						https://<span style={ { color: C.greenSoft, background: 'rgba(74,222,128,0.15)', borderRadius: 6, padding: '0 8px' } }>media.yourdomain.com</span>/2026/10/photo.jpg
					</div>
				</div>
			</div>
			{ /* Edge map */ }
			<div style={ { opacity: mapIn } }>
				<div style={ { position: 'absolute', left: 180, top: 240, width: 1560, height: 520, borderRadius: 40, background: 'radial-gradient(ellipse at center, rgba(26,95,214,0.18), transparent 70%)' } } />
				{ Array.from( { length: 220 } ).map( ( _, i ) => {
					const x = 200 + ( ( i * 137 ) % 1520 );
					const y = 250 + ( ( i * 71 ) % 500 );
					return <div key={ i } style={ { position: 'absolute', left: x, top: y, width: 4, height: 4, borderRadius: 2, background: 'rgba(124,180,255,0.25)' } } />;
				} ) }
				{ EDGES.map( ( [ x, y ], i ) => {
					const on = pop( t, s( 2 ) + 0.3 + i * 0.12 );
					const ring = ( ( f / 30 + i * 0.3 ) % 1.6 ) / 1.6;
					return (
						<div key={ i } style={ { position: 'absolute', left: x - 9, top: y - 9, opacity: on } }>
							<div style={ { width: 18, height: 18, borderRadius: 9, background: CF.orange, boxShadow: `0 0 18px ${ CF.orange }` } } />
							<div style={ { position: 'absolute', left: 9 - 30 * ring, top: 9 - 30 * ring, width: 60 * ring, height: 60 * ring, borderRadius: '50%', border: `2px solid rgba(243,128,32,${ 1 - ring })` } } />
						</div>
					);
				} ) }
				<FlowLine x1={ nearest[ 0 ] } y1={ nearest[ 1 ] } x2={ visitor[ 0 ] } y2={ visitor[ 1 ] } on={ rp( t, W( id, 2, 'কাছের' ), W( id, 2, 'কাছের' ) + 0.8 ) } color={ C.greenSoft } count={ 3 } speed={ 30 } />
				<div style={ { position: 'absolute', left: visitor[ 0 ] - 32, top: visitor[ 1 ] - 32, opacity: pop( t, W( id, 2, 'ভিজিটরের' ) ) } }>
					<div style={ { width: 64, height: 64, borderRadius: 32, background: C.green, display: 'flex', alignItems: 'center', justifyContent: 'center', boxShadow: '0 0 30px rgba(34,197,94,0.6)' } }>
						<Icon name="users" size={ 34 } color="#fff" />
					</div>
				</div>
				<div style={ { position: 'absolute', left: visitor[ 0 ] + 50, top: visitor[ 1 ] - 20, fontFamily: FONT, fontSize: 28, color: C.white, fontWeight: 600, opacity: pop( t, W( id, 2, 'কাছের' ) ) } }>সবচেয়ে কাছের সার্ভার থেকে</div>
				<div style={ { position: 'absolute', left: 0, right: 0, top: 800, display: 'flex', justifyContent: 'center', opacity: pop( t, s( 3 ) ) } }>
					<div style={ { display: 'flex', alignItems: 'center', gap: 16, padding: '16px 30px', borderRadius: 999, background: 'rgba(74,222,128,0.14)', border: '1px solid rgba(74,222,128,0.45)', fontFamily: FONT, fontSize: 32, color: C.greenSoft, fontWeight: 700 } }>
						<Icon name="db" size={ 34 } color={ C.greenSoft } /> পোস্ট বা ডাটাবেসে ০ লাইন বদল
					</div>
				</div>
			</div>
			</Shift>
		</AbsoluteFill>
	);
};

/* ================================================================== */
/* 11 The mistake (pays off the hook)                                   */
/* ================================================================== */
export const Mistake: React.FC = () => {
	const t = useT();
	const id = '11-mistake';
	const s = ( k: number ) => S( id, k );
	const localGone = rp( t, W( id, 1, 'মুছে' ) - 0.3, W( id, 1, 'মুছে' ) + 1.2 );
	const pluginOff = rp( t, W( id, 2, 'বন্ধ' ), W( id, 2, 'বন্ধ' ) + 0.4 );
	const broken = rp( t, s( 4 ) + 0.2, s( 4 ) + 1.4 ) * ( 1 - rp( t, s( 7 ) + 0.2, s( 7 ) + 1.6 ) );
	const fix = rp( t, s( 5 ) - 0.2, s( 5 ) + 0.3 );
	const bbPress = W( id, 6, 'Bring' );
	return (
		<AbsoluteFill style={ { background: '#050d1f' } }>
			<Background hue={ fix > 0.5 ? 0 : -25 * ( 1 - fix ) } />
			<Sfx at={ W( id, 2, 'বন্ধ' ) } name="click" />
			<Sfx at={ s( 5 ) - 0.2 } name="whoosh" volume={ 0.35 } />
			<Sfx at={ bbPress } name="click" />
			<Sfx at={ s( 7 ) } name="pop" volume={ 0.4 } />
			<Shift dy={ 70 }>
			<div style={ { position: 'absolute', left: 0, right: 0, top: 60, textAlign: 'center' } }>
				<div style={ { display: 'inline-flex', alignItems: 'center', gap: 14, padding: '10px 24px', borderRadius: 999, background: fix > 0.5 ? 'rgba(74,222,128,0.15)' : 'rgba(239,68,68,0.15)', border: `1px solid ${ fix > 0.5 ? 'rgba(74,222,128,0.5)' : 'rgba(239,68,68,0.5)' }`, color: fix > 0.5 ? C.greenSoft : '#fca5a5', fontFamily: FONT, fontSize: 32, fontWeight: 700 } }>
					{ fix > 0.5 ? <><Icon name="check" size={ 32 } color={ C.greenSoft } stroke={ 2.8 } /> সমাধান</> : <><Icon name="warning" size={ 32 } color="#fca5a5" stroke={ 2.2 } /> সেই ভুলটা</> }
				</div>
			</div>
			{ /* Story row: policy → plugin off → site looks for files */ }
			<div style={ { position: 'absolute', left: 110, top: 190, display: 'flex', gap: 30 } }>
				<div style={ { width: 520, opacity: pop( t, s( 1 ) ), padding: 26, borderRadius: 24, background: 'rgba(255,255,255,0.06)', border: '1px solid rgba(255,255,255,0.12)', fontFamily: FONT } }>
					<div style={ { color: C.muted, fontSize: 22 } }>Retention Policy</div>
					<div style={ { color: C.white, fontSize: 32, fontWeight: 700, fontFamily: SANS, marginTop: 4 } }>Full Cloud Migration</div>
					<div style={ { display: 'flex', alignItems: 'center', gap: 16, marginTop: 20 } }>
						<Icon name="server" size={ 44 } color={ C.text } />
						<div style={ { display: 'flex', gap: 8 } }>
							{ [ 0, 1, 2, 3, 4 ].map( ( i ) => (
								<FileTile key={ i } size={ 52 } hue={ 30 + i * 60 } style={ { opacity: 1 - Math.min( 1, Math.max( 0, localGone * 5 - i ) ), transform: `scale(${ 1 - Math.min( 1, Math.max( 0, localGone * 5 - i ) ) * 0.5 })` } } />
							) ) }
						</div>
					</div>
					<div style={ { color: '#fca5a5', fontSize: 22, marginTop: 14, opacity: localGone } }>সার্ভারে আর লোকাল ফাইল নেই</div>
				</div>
				<div style={ { width: 520, opacity: pop( t, s( 2 ) ), padding: 26, borderRadius: 24, background: 'rgba(255,255,255,0.06)', border: '1px solid rgba(255,255,255,0.12)', fontFamily: FONT } }>
					<div style={ { color: C.muted, fontSize: 22 } }>Plugins</div>
					<div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginTop: 10 } }>
						<div style={ { color: C.white, fontSize: 32, fontWeight: 700, fontFamily: SANS } }>OmniOffloader</div>
						<div style={ { width: 74, height: 40, borderRadius: 20, background: pluginOff > 0.5 ? '#475569' : C.blue, position: 'relative' } }>
							<div style={ { position: 'absolute', top: 5, left: 5 + ( 1 - pluginOff ) * 34, width: 30, height: 30, borderRadius: 15, background: '#fff' } } />
						</div>
					</div>
					<div style={ { color: pluginOff > 0.5 ? '#fca5a5' : C.muted, fontSize: 24, marginTop: 16 } }>{ pluginOff > 0.5 ? 'বন্ধ করে দিলেন…' : 'চালু' }</div>
				</div>
				<div style={ { width: 520, opacity: pop( t, s( 3 ) ), padding: 26, borderRadius: 24, background: 'rgba(255,255,255,0.06)', border: '1px solid rgba(255,255,255,0.12)', fontFamily: FONT } }>
					<div style={ { color: C.muted, fontSize: 22 } }>আপনার সাইট খুঁজছে…</div>
					<div style={ { display: 'flex', alignItems: 'center', gap: 16, marginTop: 14 } }>
						<div style={ { transform: `translateX(${ Math.sin( t * 4 ) * 14 }px)` } }>
							<Icon name="server" size={ 54 } color={ C.text } />
						</div>
						<div style={ { color: C.white, fontSize: 28, fontFamily: MONO } }>photo.jpg ?</div>
					</div>
					<div style={ { color: '#fca5a5', fontSize: 24, marginTop: 14, opacity: pop( t, W( id, 3, 'নেই' ) ) } }>ফাইল সার্ভারে নেই</div>
				</div>
			</div>
			{ /* Site: images vanish, then come back */ }
			<SiteGrid broken={ broken } x={ 110 } y={ 470 } />
			<div style={ { position: 'absolute', left: 740, top: 520, width: 1060 } }>
				<div style={ { opacity: rp( t, s( 4 ), s( 4 ) + 0.4 ) * ( 1 - fix ) } }>
					<Headline t={ t } at={ s( 4 ) } text="ফলাফল: সব ছবি গায়েব" size={ 78 } color="#fca5a5" />
				</div>
				<div style={ { position: 'absolute', top: 0, left: 0, opacity: fix } }>
					<Headline t={ t } at={ s( 5 ) } text="বন্ধ করার আগে Bring Back চালান" size={ 60 } accent="Bring" />
					<div style={ { display: 'flex', alignItems: 'center', gap: 26, marginTop: 34 } }>
						<div style={ { padding: '20px 34px', borderRadius: 16, background: C.accent, color: '#fff', fontFamily: SANS, fontSize: 32, fontWeight: 700, display: 'flex', alignItems: 'center', gap: 14, transform: `scale(${ t > bbPress - 0.06 && t < bbPress + 0.2 ? 0.9 : 1 })`, opacity: pop( t, s( 6 ) ), boxShadow: '0 20px 50px rgba(19,93,190,0.5)' } }>
							<Icon name="cloudDown" size={ 36 } color="#fff" /> Start Bring Back
						</div>
						<div style={ { opacity: pop( t, s( 7 ) + 0.5 ), display: 'flex', alignItems: 'center', gap: 12, fontFamily: FONT, fontSize: 30, color: C.greenSoft, fontWeight: 700 } }>
							<Icon name="shield" size={ 40 } color={ C.greenSoft } /> ক্লাউডের কপিও নিরাপদ
						</div>
					</div>
				</div>
			</div>
			</Shift>
		</AbsoluteFill>
	);
};

/* ================================================================== */
/* 12 Outro                                                             */
/* ================================================================== */
export const Outro: React.FC = () => {
	const t = useT();
	const f = useCurrentFrame();
	const id = '12-outro';
	const s = ( k: number ) => S( id, k );
	const chips: [ string, 'cloud' | 'cloudDown' | 'globe' | 'refresh' ][] = [
		[ '১০ GB ফ্রি স্টোরেজ', 'cloud' ],
		[ 'ফ্রি ডাউনলোড', 'cloudDown' ],
		[ 'নিজের CDN', 'globe' ],
		[ 'যেকোনো সময় Bring Back', 'refresh' ],
	];
	const end = sceneDur( id ) + 1.8;
	const out = interpolate( t, [ end, end + 1 ], [ 1, 0 ], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' } );
	return (
		<AbsoluteFill>
			<Background />
			<AbsoluteFill style={ { display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', opacity: out, fontFamily: FONT } }>
				<div style={ { transform: `scale(${ pop( t, -0.2, 0.7 ) }) translateY(${ Math.sin( f / 20 ) * 6 }px)` } }>
					<Logo size={ 170 } />
				</div>
				<div style={ { fontFamily: SANS, fontSize: 86, fontWeight: 800, color: C.white, letterSpacing: -2, marginTop: 20, opacity: pop( t, 0.2 ) } }>OmniOffloader</div>
				<div style={ { display: 'flex', gap: 18, marginTop: 34, flexWrap: 'wrap', justifyContent: 'center', maxWidth: 1500 } }>
					{ chips.map( ( [ label, icon ], i ) => {
						const p = pop( t, W( id, 1, [ 'দশ', 'ডাউনলোড', 'CDN', 'ফিরিয়ে' ][ i ] ) );
						return (
							<div key={ label } style={ { display: 'flex', alignItems: 'center', gap: 12, padding: '14px 26px', borderRadius: 999, background: 'rgba(124,180,255,0.12)', border: '1px solid rgba(124,180,255,0.35)', color: C.white, fontSize: 32, fontWeight: 600, opacity: Math.min( 1, p * 1.3 ), transform: `scale(${ 0.7 + 0.3 * p })` } }>
								<Icon name={ icon } size={ 30 } color={ C.sky } /> { label }
							</div>
						);
					} ) }
				</div>
				<div style={ { display: 'flex', gap: 20, marginTop: 40, opacity: pop( t, s( 2 ) ) } }>
					{ [ 'wordpress.org/plugins/omnioffloader', 'omnioffloader.vercel.app' ].map( ( u ) => (
						<div key={ u } style={ { padding: '14px 24px', borderRadius: 14, background: 'rgba(0,0,0,0.35)', border: '1px solid rgba(255,255,255,0.14)', fontFamily: MONO, fontSize: 26, color: C.text } }>{ u }</div>
					) ) }
				</div>
				<div style={ { display: 'flex', gap: 40, marginTop: 44, opacity: pop( t, s( 3 ) ) } }>
					{ ( [ [ 'thumbsUp', 'লাইক' ], [ 'share', 'শেয়ার' ] ] as const ).map( ( [ e, l ], i ) => (
						<div key={ l } style={ { display: 'flex', alignItems: 'center', gap: 14, fontSize: 36, color: C.white, fontWeight: 700, transform: `scale(${ 1 + 0.08 * Math.max( 0, Math.sin( ( t - s( 3 ) - i * 0.6 ) * 5 ) ) })` } }>
							<div style={ { width: 64, height: 64, borderRadius: 20, background: 'rgba(124,180,255,0.14)', border: '1px solid rgba(124,180,255,0.35)', display: 'flex', alignItems: 'center', justifyContent: 'center' } }>
								<Icon name={ e } size={ 34 } color={ C.sky } stroke={ 2.2 } />
							</div> { l }
						</div>
					) ) }
				</div>
				<div style={ { marginTop: 34, fontSize: 44, fontWeight: 700, color: C.sky, opacity: pop( t, s( 4 ) ) } }>আবার দেখা হবে!</div>
			</AbsoluteFill>
		</AbsoluteFill>
	);
};

export const BN_SCENES: Record<string, React.FC> = {
	'01-hook': Hook,
	'02-problem': Problem,
	'03-r2-free': R2Free,
	'04-roadmap': Roadmap,
	'05-bucket': Bucket,
	'06-domain': Domain,
	'07-token': Token,
	'08-plugin': Plugin,
	'09-offload': Offload,
	'10-result': Result,
	'11-mistake': Mistake,
	'12-outro': Outro,
};

export { Easing };
