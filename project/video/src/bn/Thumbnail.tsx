import React from 'react';
import { AbsoluteFill } from 'remotion';
import { C, BN, SANS } from '../theme';
import { Icon, Logo } from '../components';
import { CF } from './kit';

const FONT = `${ BN }, ${ SANS }`;

/** YouTube thumbnail (1280×720) for the Bangla Cloudflare R2 tutorial. */
export const Thumbnail: React.FC = () => (
	<AbsoluteFill style={ { background: 'radial-gradient(ellipse at 75% 40%, #123a86 0%, #071531 55%, #040a1a 100%)', overflow: 'hidden', fontFamily: FONT } }>
		{ /* Glows */ }
		<div style={ { position: 'absolute', right: -120, top: -80, width: 760, height: 760, borderRadius: '50%', background: C.blueLight, opacity: 0.3, filter: 'blur(140px)' } } />
		<div style={ { position: 'absolute', left: -200, bottom: -260, width: 700, height: 700, borderRadius: '50%', background: C.blue, opacity: 0.45, filter: 'blur(140px)' } } />
		{ /* Faint grid */ }
		<AbsoluteFill style={ { backgroundImage: 'linear-gradient(rgba(124,180,255,0.07) 1px, transparent 1px), linear-gradient(90deg, rgba(124,180,255,0.07) 1px, transparent 1px)', backgroundSize: '56px 56px', maskImage: 'radial-gradient(ellipse at 70% 50%, black 20%, transparent 70%)', WebkitMaskImage: 'radial-gradient(ellipse at 70% 50%, black 20%, transparent 70%)' } } />

		{ /* Left: the promise */ }
		<div style={ { position: 'absolute', left: 64, top: 60 } }>
			<div style={ { display: 'inline-flex', alignItems: 'center', gap: 12, padding: '10px 22px', borderRadius: 999, background: 'rgba(255,255,255,0.1)', border: '1px solid rgba(255,255,255,0.25)', color: '#fff', fontSize: 30, fontWeight: 700 } }>
				<Icon name="wp" size={ 34 } color="#fff" stroke={ 2 } /> WordPress মিডিয়া
			</div>
			<div style={ { marginTop: 18, display: 'flex', alignItems: 'baseline', gap: 18 } }>
				<div style={ { fontFamily: SANS, fontSize: 250, fontWeight: 900, color: '#fff', lineHeight: 0.95, letterSpacing: -10, textShadow: '0 12px 50px rgba(0,0,0,0.5)' } }>10</div>
				<div style={ { fontFamily: SANS, fontSize: 130, fontWeight: 900, color: CF.orange, lineHeight: 1, letterSpacing: -4 } }>GB</div>
			</div>
			<div style={ { display: 'inline-block', marginTop: 10, padding: '6px 34px 14px', borderRadius: 24, background: 'linear-gradient(135deg, #22c55e, #15803d)', color: '#fff', fontSize: 108, fontWeight: 700, lineHeight: 1.15, transform: 'rotate(-3deg)', boxShadow: '0 20px 60px rgba(34,197,94,0.45)' } }>ফ্রি!</div>
			<div style={ { marginTop: 34, color: '#e8f0ff', fontSize: 46, fontWeight: 700, lineHeight: 1.2 } }>
				নিজের <span style={ { color: C.sky } }>CDN</span> · মাত্র ৫ ধাপে
			</div>
		</div>

		{ /* Right: WordPress → cloud → visitors */ }
		<div style={ { position: 'absolute', right: 70, top: 120, width: 470, height: 470 } }>
			<div style={ { position: 'absolute', left: 65, top: 40, width: 340, height: 340, borderRadius: '50%', border: '2px dashed rgba(124,180,255,0.35)' } } />
			{ /* Plugin logo in the centre */ }
			<div style={ { position: 'absolute', left: 95, top: 60, width: 280, height: 280, filter: 'drop-shadow(0 30px 80px rgba(59,134,238,0.65))' } }>
				<Logo size={ 280 } style={ { filter: 'none' } } />
			</div>
			{ /* Orbiting badges */ }
			{ [
				{ x: 10, y: 10, icon: 'image' as const, bg: '#2563eb' },
				{ x: 360, y: 0, icon: 'globe' as const, bg: '#0ea5e9' },
				{ x: 0, y: 300, icon: 'server' as const, bg: '#475569' },
				{ x: 360, y: 300, icon: 'bolt' as const, bg: '#16a34a' },
			].map( ( b, i ) => (
				<div key={ i } style={ { position: 'absolute', left: b.x, top: b.y, width: 108, height: 108, borderRadius: 30, background: b.bg, display: 'flex', alignItems: 'center', justifyContent: 'center', boxShadow: '0 18px 40px rgba(0,0,0,0.45), inset 0 1px 0 rgba(255,255,255,0.3)', border: '2px solid rgba(255,255,255,0.25)' } }>
					<Icon name={ b.icon } size={ 58 } color="#fff" stroke={ 1.8 } />
				</div>
			) ) }
		</div>

		{ /* Bottom-right tag */ }
		<div style={ { position: 'absolute', right: 64, bottom: 54, display: 'flex', alignItems: 'center', gap: 16 } }>
			<div style={ { padding: '12px 24px', borderRadius: 14, background: '#dc2626', color: '#fff', fontSize: 34, fontWeight: 700, boxShadow: '0 12px 30px rgba(220,38,38,0.45)' } }>বাংলা টিউটোরিয়াল</div>
		</div>
	</AbsoluteFill>
);

/* Shared background for the A/B variants. */
const Bg: React.FC<{ tint: string; glow: string }> = ( { tint, glow } ) => (
	<>
		<AbsoluteFill style={ { background: tint } } />
		<div style={ { position: 'absolute', right: -140, top: -120, width: 780, height: 780, borderRadius: '50%', background: glow, opacity: 0.32, filter: 'blur(150px)' } } />
		<div style={ { position: 'absolute', left: -220, bottom: -280, width: 720, height: 720, borderRadius: '50%', background: C.blue, opacity: 0.4, filter: 'blur(150px)' } } />
		<AbsoluteFill style={ { backgroundImage: 'linear-gradient(rgba(124,180,255,0.07) 1px, transparent 1px), linear-gradient(90deg, rgba(124,180,255,0.07) 1px, transparent 1px)', backgroundSize: '56px 56px', maskImage: 'radial-gradient(ellipse at 70% 50%, black 20%, transparent 70%)', WebkitMaskImage: 'radial-gradient(ellipse at 70% 50%, black 20%, transparent 70%)' } } />
	</>
);

const Tag: React.FC = () => (
	<div style={ { position: 'absolute', right: 64, bottom: 54, padding: '12px 24px', borderRadius: 14, background: '#dc2626', color: '#fff', fontSize: 34, fontWeight: 700, fontFamily: FONT, boxShadow: '0 12px 30px rgba(220,38,38,0.45)' } }>বাংলা টিউটোরিয়াল</div>
);

/** A: "সব মিডিয়া CDN-এ · মাত্র ৫ ধাপে" — simple benefit + easy steps. */
export const ThumbA: React.FC = () => (
	<AbsoluteFill style={ { overflow: 'hidden', fontFamily: FONT } }>
		<Bg tint="radial-gradient(ellipse at 70% 40%, #123a86 0%, #071531 55%, #040a1a 100%)" glow={ C.blueLight } />
		<div style={ { position: 'absolute', left: 64, top: 64, width: 640 } }>
			<div style={ { color: '#fff', fontSize: 82, fontWeight: 700, lineHeight: 1.18 } }>
				সব মিডিয়া<br />
				<span style={ { color: C.sky } }>CDN-এ</span> হোস্ট
			</div>
			<div style={ { display: 'inline-block', marginTop: 18, padding: '4px 30px 12px', borderRadius: 22, background: 'linear-gradient(135deg, #22c55e, #15803d)', color: '#fff', fontSize: 92, fontWeight: 700, lineHeight: 1.15, transform: 'rotate(-2deg)', boxShadow: '0 20px 60px rgba(34,197,94,0.45)' } }>ফ্রিতে!</div>
		</div>
		{ /* Five steps */ }
		<div style={ { position: 'absolute', left: 64, bottom: 64, display: 'flex', alignItems: 'center', gap: 10 } }>
			{ [ '১', '২', '৩', '৪', '৫' ].map( ( n, i ) => (
				<React.Fragment key={ n }>
					<div style={ { width: 62, height: 62, borderRadius: 31, background: i === 4 ? C.green : 'rgba(255,255,255,0.12)', border: `2px solid ${ i === 4 ? '#4ade80' : 'rgba(255,255,255,0.3)' }`, color: '#fff', fontSize: 34, fontWeight: 700, display: 'flex', alignItems: 'center', justifyContent: 'center' } }>{ i === 4 ? <Icon name="check" size={ 34 } color="#fff" stroke={ 3 } /> : n }</div>
					{ i < 4 ? <div style={ { width: 16, height: 4, borderRadius: 2, background: 'rgba(124,180,255,0.5)' } } /> : null }
				</React.Fragment>
			) ) }
			<div style={ { marginLeft: 14, color: '#fff', fontSize: 46, fontWeight: 700 } }>মাত্র ৫ ধাপে</div>
		</div>
		{ /* Flow: WordPress → plugin → CDN */ }
		<div style={ { position: 'absolute', right: 70, top: 110, width: 470, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 18 } }>
			<div style={ { display: 'flex', alignItems: 'center', gap: 22 } }>
				<div style={ { width: 120, height: 120, borderRadius: 32, background: '#334155', border: '2px solid rgba(255,255,255,0.25)', display: 'flex', alignItems: 'center', justifyContent: 'center' } }>
					<Icon name="wp" size={ 70 } color="#fff" stroke={ 1.6 } />
				</div>
				<Icon name="arrowRight" size={ 56 } color={ C.sky } stroke={ 2.6 } />
				<div style={ { width: 120, height: 120, borderRadius: 32, background: '#0ea5e9', border: '2px solid rgba(255,255,255,0.25)', display: 'flex', alignItems: 'center', justifyContent: 'center' } }>
					<Icon name="globe" size={ 70 } color="#fff" stroke={ 1.6 } />
				</div>
			</div>
			<div style={ { filter: 'drop-shadow(0 30px 80px rgba(59,134,238,0.65))', marginTop: 6 } }>
				<Logo size={ 250 } style={ { filter: 'none' } } />
			</div>
		</div>
		<Tag />
	</AbsoluteFill>
);

/** B: "এই ১টা ভুলে সব ছবি গায়েব!" — curiosity / warning. */
export const ThumbB: React.FC = () => (
	<AbsoluteFill style={ { overflow: 'hidden', fontFamily: FONT } }>
		<Bg tint="radial-gradient(ellipse at 70% 40%, #4a1020 0%, #1a0812 55%, #08050c 100%)" glow="#ef4444" />
		<div style={ { position: 'absolute', left: 64, top: 70, width: 660 } }>
			<div style={ { display: 'inline-flex', alignItems: 'center', gap: 14, padding: '10px 24px', borderRadius: 999, background: 'rgba(251,191,36,0.16)', border: '2px solid rgba(251,191,36,0.6)', color: '#fbbf24', fontSize: 38, fontWeight: 700 } }>
				<Icon name="warning" size={ 40 } color="#fbbf24" stroke={ 2.2 } /> সাবধান!
			</div>
			<div style={ { marginTop: 22, color: '#fff', fontSize: 92, fontWeight: 700, lineHeight: 1.15 } }>
				এই <span style={ { color: '#fbbf24' } }>১টা ভুলে</span>
			</div>
			<div style={ { display: 'inline-block', marginTop: 10, padding: '2px 28px 12px', borderRadius: 22, background: 'linear-gradient(135deg, #ef4444, #b91c1c)', color: '#fff', fontSize: 96, fontWeight: 700, lineHeight: 1.15, transform: 'rotate(-2deg)', boxShadow: '0 20px 60px rgba(239,68,68,0.5)' } }>সব ছবি গায়েব!</div>
			<div style={ { marginTop: 30, color: '#fecaca', fontSize: 40, fontWeight: 600 } }>সমাধানসহ পুরো সেটআপ</div>
		</div>
		{ /* Broken image grid */ }
		<div style={ { position: 'absolute', right: 70, top: 110, width: 470, padding: 22, borderRadius: 26, background: '#fff', boxShadow: '0 40px 100px rgba(0,0,0,0.6)', transform: 'rotate(3deg)' } }>
			<div style={ { height: 16, width: 200, borderRadius: 8, background: '#e5e7eb', marginBottom: 18 } } />
			<div style={ { display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 14 } }>
				{ Array.from( { length: 6 } ).map( ( _, i ) => (
					<div key={ i } style={ { height: 120, borderRadius: 14, border: '3px dashed #fca5a5', background: '#fef2f2', display: 'flex', alignItems: 'center', justifyContent: 'center' } }>
						<Icon name="image" size={ 54 } color="#ef4444" stroke={ 1.8 } />
					</div>
				) ) }
			</div>
		</div>
		<div style={ { position: 'absolute', right: 430, top: 380, width: 130, height: 130, borderRadius: 65, background: '#ef4444', border: '6px solid #fff', display: 'flex', alignItems: 'center', justifyContent: 'center', boxShadow: '0 20px 50px rgba(239,68,68,0.6)' } }>
			<Icon name="warning" size={ 70 } color="#fff" stroke={ 2.2 } />
		</div>
		<Tag />
	</AbsoluteFill>
);
