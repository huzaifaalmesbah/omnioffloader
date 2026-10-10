import React from 'react';
import { AbsoluteFill, useCurrentFrame, Easing, interpolate } from 'remotion';
import { C, SANS, MONO, useT, useIn, ramp, rise } from './theme';
import { Background, Icon, Logo, SceneTitle, Glass, Pill, BrowserFrame, FileTile, FlowLine, Node, clamp01, lerp, fmt } from './components';

/* ================================================================== */
/* 6. Retention policies.                                              */
/* ================================================================== */
const Files: React.FC<{ keepOriginal: number; keepThumbs: number; hue?: number }> = ( { keepOriginal, keepThumbs, hue = 200 } ) => (
	<div style={ { display: 'flex', alignItems: 'flex-end', gap: 10, height: 86 } }>
		<FileTile size={ 80 } hue={ hue } style={ { opacity: keepOriginal, transform: `scale(${ 0.4 + 0.6 * keepOriginal })` } } />
		{ [ 0, 1, 2, 3 ].map( ( i ) => (
			<FileTile key={ i } size={ 46 } hue={ hue } style={ { opacity: keepThumbs, transform: `scale(${ 0.3 + 0.7 * keepThumbs }) translateY(${ ( 1 - keepThumbs ) * 20 }px)` } } />
		) ) }
	</div>
);

const POLICIES = [
	{ name: 'Retain local files', desc: 'Keep everything on the server. The cloud is a mirror.', at: 2.2, thumbs: () => 1, orig: () => 1 },
	{ name: 'Smart local cleanup', desc: 'Delete generated thumbnails, keep originals.', at: 3.8, thumbs: ( t: number ) => 1 - ramp( t, 4.6, 5.6 ), orig: () => 1 },
	{ name: 'Full cloud migration', desc: 'Remove every local copy. Maximum disk freed.', at: 6.5, thumbs: ( t: number ) => 1 - ramp( t, 7.3, 8.1 ), orig: ( t: number ) => 1 - ramp( t, 7.6, 8.4 ) },
];

export const Retention: React.FC = () => {
	const t = useT();
	const f = useCurrentFrame();
	const active = t >= 6.5 ? 2 : t >= 3.8 ? 1 : t >= 2.2 ? 0 : -1;
	const banner = useIn( 9.0 );
	const apply = ramp( t, 10.6, 13.4 );
	const press = t > 10.3 && t < 10.55 ? 0.9 : 1;
	return (
		<AbsoluteFill>
			<Background />
			<SceneTitle eyebrow="Retention policies" title="You decide what stays on your server" width={ 1700 } />
			<div style={ { position: 'absolute', left: 110, top: 280, display: 'flex', gap: 30 } }>
				{ POLICIES.map( ( p, i ) => {
					const pin = useIn( 0.3 + i * 0.25 );
					const on = active === i;
					const dim = active >= 0 && ! on ? 0.55 : 1;
					return (
						<Glass key={ p.name } glow={ on } style={ { width: 547, padding: 32, opacity: dim * Math.min( 1, pin * 1.4 ), transform: `translateY(${ ( 1 - pin ) * 40 }px) scale(${ on ? 1.02 : 1 })` } }>
							<div style={ { display: 'flex', alignItems: 'center', gap: 14 } }>
								<div style={ { width: 40, height: 40, borderRadius: 20, background: on ? C.accent : 'rgba(255,255,255,0.1)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 800, fontSize: 20, color: '#fff' } }>{ i }</div>
								<div style={ { fontSize: 28, fontWeight: 700, color: C.white } }>{ p.name }</div>
							</div>
							<div style={ { fontSize: 20, color: C.muted, marginTop: 12, height: 56, lineHeight: 1.4 } }>{ p.desc }</div>
							<div style={ { marginTop: 20, fontSize: 16, letterSpacing: 2, color: C.sky, fontWeight: 700, display: 'flex', alignItems: 'center', gap: 8 } }>
								<Icon name="cloud" size={ 20 } color={ C.sky } /> CLOUD
							</div>
							<div style={ { marginTop: 10 } }>
								<Files keepOriginal={ 1 } keepThumbs={ 1 } />
							</div>
							<div style={ { marginTop: 22, fontSize: 16, letterSpacing: 2, color: C.muted, fontWeight: 700, display: 'flex', alignItems: 'center', gap: 8 } }>
								<Icon name="server" size={ 20 } color={ C.muted } /> SERVER
							</div>
							<div style={ { marginTop: 10, height: 86, borderRadius: 14, border: '1px dashed rgba(255,255,255,0.15)', padding: '0 0 0 0', position: 'relative' } }>
								<Files keepOriginal={ p.orig( t ) } keepThumbs={ p.thumbs( t ) } />
								{ i === 2 && t > 8.3 ? (
									<div style={ { position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 22, fontWeight: 700, color: C.greenSoft, opacity: ramp( t, 8.3, 8.7 ) } }>Disk space freed</div>
								) : null }
							</div>
						</Glass>
					);
				} ) }
			</div>
			<Glass style={ { position: 'absolute', left: 110, top: 800, width: 1701, padding: '20px 30px', display: 'flex', alignItems: 'center', gap: 22, ...rise( banner, 30 ) } }>
				<Icon name="refresh" size={ 34 } color={ C.sky } style={ { transform: `rotate(${ apply > 0 && apply < 1 ? f * 8 : 0 }deg)` } } />
				<div style={ { flex: 1 } }>
					<div style={ { fontSize: 24, fontWeight: 700, color: C.white } }>Apply the new policy to existing media</div>
					<div style={ { fontSize: 18, color: C.muted, marginTop: 4 } }>Every file is verified in the cloud, by size, before its local copy is deleted</div>
				</div>
				<div style={ { width: 300, height: 12, borderRadius: 6, background: 'rgba(255,255,255,0.1)', overflow: 'hidden', opacity: apply > 0 ? 1 : 0 } }>
					<div style={ { width: `${ apply * 100 }%`, height: '100%', background: apply >= 1 ? C.greenSoft : C.blueLight } } />
				</div>
				<div style={ { padding: '12px 26px', borderRadius: 10, background: apply >= 1 ? '#15803d' : C.accent, color: '#fff', fontWeight: 700, fontSize: 20, transform: `scale(${ press })`, minWidth: 140, textAlign: 'center' } }>{ apply >= 1 ? '✓ Done' : apply > 0 ? 'Applying…' : 'Apply' }</div>
			</Glass>
		</AbsoluteFill>
	);
};

/* ================================================================== */
/* 7. Bring Back.                                                      */
/* ================================================================== */
export const BringBack: React.FC = () => {
	const t = useT();
	const f = useCurrentFrame();
	const vis = useIn( -0.1 );
	const shot = useIn( 0.8 );
	const flow = ramp( t, 0.8, 1.6 );
	const disk = useIn( 7.8 );
	const keep = useIn( 10.2 );
	return (
		<AbsoluteFill>
			<Background />
			<SceneTitle eyebrow="Bring Back" title="Never a one-way door" />
			{ /* cloud → server */ }
			<div style={ { opacity: vis } }>
				<Node icon="cloud" label="Cloud storage" p={ vis } x={ 400 } y={ 400 } />
				<Node icon="server" label="Your server" p={ useIn( 0.4 ) } x={ 400 } y={ 790 } color="#16a34a" />
				<FlowLine x1={ 400 } y1={ 480 } x2={ 400 } y2={ 680 } on={ flow } color={ C.greenSoft } count={ 3 } speed={ 40 } />
				{ [ 0, 1, 2 ].map( ( i ) => {
					const k = ( ( f / 30 - 1.2 + i * 0.7 ) % 2.1 ) / 2.1;
					return <FileTile key={ i } size={ 46 } hue={ 30 + i * 90 } style={ { position: 'absolute', left: 470 + ( i % 2 ) * 40, top: lerp( 510, 690, k ), opacity: flow * Math.sin( k * Math.PI ) } } />;
				} ) }
			</div>
			<div style={ { position: 'absolute', left: 760, top: 270, ...rise( shot, 50 ) } }>
				<BrowserFrame src="shots/bring-back.png" width={ 1050 } height={ 660 } imgWidth={ 1210 } offsetX={ 134 } zoom={ 1 } originY={ 60 } offsetY={ lerp( 44, 200, ramp( t, 9.4, 10.6 ) ) } url="example.com/wp-admin/admin.php?page=omnioffloader&view=bring-back" />
			</div>
			<div style={ { position: 'absolute', left: 1120, top: 790, ...rise( disk * ( 1 - ramp( t, 9.6, 10.0 ) ), 20 ), opacity: disk * ( 1 - ramp( t, 9.6, 10.0 ) ) } }>
				<Glass style={ { padding: '20px 28px', background: 'rgba(5,13,31,0.92)', display: 'flex', gap: 18, alignItems: 'center' } }>
					<Icon name="db" size={ 36 } color={ C.sky } />
					<div>
						<div style={ { fontSize: 18, color: C.muted, fontWeight: 600, letterSpacing: 1 } }>DISK SPACE CHECK</div>
						<div style={ { fontSize: 26, color: C.white, fontWeight: 700, marginTop: 4 } }>Needs 2.4 GB · 53.1 GB free</div>
					</div>
					<Icon name="check" size={ 40 } color={ C.greenSoft } stroke={ 2.6 } />
				</Glass>
			</div>
			<div style={ { position: 'absolute', left: 1120, top: 300, ...rise( keep, 20 ) } }>
				<Glass style={ { padding: '20px 28px', background: 'rgba(5,13,31,0.92)', display: 'flex', gap: 18, alignItems: 'center' } }>
					<Icon name="shield" size={ 40 } color={ C.greenSoft } />
					<div>
						<div style={ { fontSize: 26, color: C.white, fontWeight: 700 } }>Cloud copies kept</div>
						<div style={ { fontSize: 19, color: C.muted, marginTop: 4 } }>Deleting them is optional — and off by default</div>
					</div>
				</Glass>
			</div>
		</AbsoluteFill>
	);
};

/* ================================================================== */
/* 8. Safety + Safe Mode.                                              */
/* ================================================================== */
export const Safety: React.FC = () => {
	const t = useT();
	const f = useCurrentFrame();
	const items = [
		{ at: 1.6, title: 'Verified before deleted', sub: 'A local file goes only after its upload is confirmed' },
		{ at: 5.8, title: 'Atomic restores', sub: 'Temp file first, then moved into place' },
		{ at: 7.4, title: 'Safe Mode for copies', sub: 'Staging and local sites never write to your bucket' },
	];
	const shieldOut = ramp( t, 7.0, 7.6 );
	const diag = useIn( 7.5 );
	const blocked = ramp( t, 10.9, 11.4 );
	const pulse = 1 + 0.04 * Math.sin( f / 8 );
	return (
		<AbsoluteFill>
			<Background />
			<SceneTitle eyebrow="Safe by design" title={ <>Your media always has<br />a valid copy</> } width={ 900 } />
			<div style={ { position: 'absolute', left: 110, top: 440, width: 820 } }>
				{ items.map( ( it ) => {
					const p = useIn( it.at );
					return (
						<div key={ it.title } style={ { display: 'flex', gap: 24, alignItems: 'center', marginBottom: 34, ...rise( p, 30 ) } }>
							<div style={ { width: 64, height: 64, borderRadius: 20, background: 'rgba(34,197,94,0.15)', border: '1px solid rgba(74,222,128,0.4)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 } }>
								<Icon name="check" size={ 36 } color={ C.greenSoft } stroke={ 2.6 } />
							</div>
							<div style={ { fontFamily: SANS } }>
								<div style={ { fontSize: 32, fontWeight: 700, color: C.white } }>{ it.title }</div>
								<div style={ { fontSize: 22, color: C.muted, marginTop: 4 } }>{ it.sub }</div>
							</div>
						</div>
					);
				} ) }
			</div>
			{ /* Big shield */ }
			<div style={ { position: 'absolute', left: 1180, top: 230, opacity: useIn( 0 ) * ( 1 - shieldOut ), transform: `scale(${ pulse * ( 1 - 0.3 * shieldOut ) })` } }>
				<div style={ { width: 520, height: 520, borderRadius: '50%', background: 'radial-gradient(circle, rgba(34,197,94,0.25), transparent 65%)', display: 'flex', alignItems: 'center', justifyContent: 'center' } }>
					<Icon name="shield" size={ 360 } color={ C.greenSoft } stroke={ 1.1 } style={ { filter: 'drop-shadow(0 0 30px rgba(74,222,128,0.6))' } } />
				</div>
			</div>
			{ /* Safe Mode diagram */ }
			<div style={ { opacity: diag } }>
				<Node icon="globe" label="example.com" sub="live site" p={ diag } x={ 1150 } y={ 380 } />
				<Node icon="server" label="staging copy" sub="Safe Mode" p={ useIn( 8.9 ) } x={ 1150 } y={ 740 } color="#64748b" />
				<Node icon="cloud" label="Your bucket" p={ useIn( 8.0 ) } x={ 1660 } y={ 560 } />
				<FlowLine x1={ 1240 } y1={ 340 } x2={ 1575 } y2={ 500 } on={ diag } color={ C.greenSoft } />
				<svg style={ { position: 'absolute', left: 0, top: 0, overflow: 'visible' } } width={ 1 } height={ 1 }>
					<line x1={ 1240 } y1={ 690 } x2={ lerp( 1240, 1400, ramp( t, 9.5, 10.9 ) ) } y2={ lerp( 690, 610, ramp( t, 9.5, 10.9 ) ) } stroke={ C.red } strokeWidth={ 4 } strokeDasharray="10 10" opacity={ 0.8 } />
				</svg>
				<div style={ { position: 'absolute', left: 1380, top: 570, opacity: blocked, transform: `scale(${ 0.6 + 0.4 * blocked })` } }>
					<div style={ { width: 70, height: 70, borderRadius: 35, background: C.red, display: 'flex', alignItems: 'center', justifyContent: 'center', boxShadow: '0 0 30px rgba(239,68,68,0.6)' } }>
						<Icon name="lock" size={ 36 } color="#fff" stroke={ 2.2 } />
					</div>
				</div>
				<div style={ { position: 'absolute', left: 1290, top: 650, opacity: blocked, fontFamily: SANS, fontSize: 20, color: '#fca5a5', fontWeight: 700, width: 280, textAlign: 'center' } }>No uploads · no deletes</div>
			</div>
		</AbsoluteFill>
	);
};

/* ================================================================== */
/* 9. WP-CLI.                                                          */
/* ================================================================== */
const Typed: React.FC<{ text: string; from: number; to: number; t: number }> = ( { text, from, to, t } ) => {
	const n = Math.round( ramp( t, from, to, Easing.linear ) * text.length );
	const caret = t >= from - 0.4 && ( t < to + 0.3 || n < text.length );
	const blink = Math.floor( t * 2.5 ) % 2 === 0;
	return (
		<span>
			{ text.slice( 0, n ) }
			{ caret && blink ? <span style={ { background: C.sky, color: C.sky } }>_</span> : null }
		</span>
	);
};

export const Cli: React.FC = () => {
	const t = useT();
	const term = useIn( -0.2 );
	const prog = ramp( t, 3.4, 9.6, Easing.inOut( Easing.sin ) );
	const total = 12480;
	const bar = 34;
	const filled = Math.round( prog * bar );
	const secs = Math.round( prog * 312 );
	const chips = [
		{ at: 5.9, icon: 'bolt' as const, text: 'Up to 64 parallel transfers' },
		{ at: 8.3, icon: 'terminal' as const, text: 'Runs in the background with nohup' },
		{ at: 10.2, icon: 'lock' as const, text: 'wp omnioffloader stop — from anywhere' },
		{ at: 11.0, icon: 'refresh' as const, text: 'offload · restore · retention · move · status' },
	];
	const prompt = <span style={ { color: C.greenSoft } }>~/site $ </span>;
	return (
		<AbsoluteFill>
			<Background />
			<SceneTitle eyebrow="WP-CLI" title="Built for big libraries" />
			<div style={ { position: 'absolute', left: 110, top: 290, width: 1080, borderRadius: 18, overflow: 'hidden', boxShadow: '0 50px 120px rgba(0,0,0,0.6), 0 0 0 1px rgba(255,255,255,0.12)', ...rise( term, 50 ) } }>
				<div style={ { height: 44, background: '#1b2335', display: 'flex', alignItems: 'center', padding: '0 18px', gap: 9 } }>
					{ [ '#ff5f57', '#febc2e', '#28c840' ].map( ( c ) => (
						<div key={ c } style={ { width: 13, height: 13, borderRadius: 7, background: c } } />
					) ) }
					<div style={ { flex: 1, textAlign: 'center', fontFamily: SANS, fontSize: 16, color: '#8892a8', marginRight: 60 } }>ssh — example.com</div>
				</div>
				<div style={ { background: 'rgba(8,12,22,0.96)', height: 560, padding: '30px 34px', fontFamily: MONO, fontSize: 25, lineHeight: 1.75, color: '#d6e2ff', whiteSpace: 'pre' } }>
					<div>
						{ prompt }
						<Typed text="wp omnioffloader offload --all --concurrency=64" from={ 0.2 } to={ 2.6 } t={ t } />
					</div>
					{ t > 3.0 ? <div style={ { color: '#8fa3c7' } }>Uploading up to 64 files at once.</div> : null }
					{ t > 3.3 ? (
						<div>
							<span>Offloading </span>
							<span style={ { color: C.white, fontWeight: 600 } }>{ String( Math.round( prog * 100 ) ).padStart( 3, ' ' ) }%</span>
							<span> [</span>
							<span style={ { color: C.sky } }>{ '='.repeat( Math.max( 0, filled - 1 ) ) + ( filled < bar && filled > 0 ? '>' : filled === bar ? '=' : '' ) }</span>
							<span>{ ' '.repeat( bar - filled ) }] </span>
							<span style={ { color: '#8fa3c7' } }>{ `${ Math.floor( secs / 60 ) }:${ String( secs % 60 ).padStart( 2, '0' ) }` }</span>
						</div>
					) : null }
					{ t > 3.3 ? <div style={ { color: '#8fa3c7' } }>{ fmt( prog * total ) } / { fmt( total ) } attachments</div> : null }
					{ t > 9.8 ? (
						<div>
							<span style={ { color: C.greenSoft, fontWeight: 600 } }>Success:</span> { fmt( total ) } attachment(s) offloaded.
						</div>
					) : null }
					{ t > 10.2 ? <div>{ prompt }<Typed text="wp omnioffloader status" from={ 10.4 } to={ 11.4 } t={ t } /></div> : null }
				</div>
			</div>
			<div style={ { position: 'absolute', left: 1250, top: 300, width: 560 } }>
				{ chips.map( ( c ) => {
					const p = useIn( c.at );
					return (
						<Glass key={ c.text } style={ { padding: '24px 26px', marginBottom: 22, display: 'flex', alignItems: 'center', gap: 18, ...rise( p, 30 ) } }>
							<div style={ { width: 54, height: 54, borderRadius: 16, background: 'rgba(124,180,255,0.14)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 } }>
								<Icon name={ c.icon } size={ 30 } color={ C.sky } />
							</div>
							<div style={ { fontSize: 24, fontWeight: 600, color: C.white, lineHeight: 1.3 } }>{ c.text }</div>
						</Glass>
					);
				} ) }
			</div>
		</AbsoluteFill>
	);
};

/* ================================================================== */
/* 10. Outro.                                                          */
/* ================================================================== */
export const Outro: React.FC = () => {
	const t = useT();
	const f = useCurrentFrame();
	const logo = useIn( -0.3, 11 );
	const name = useIn( 0.0 );
	const words = [ 'Offload to the cloud.', 'Serve from your CDN.', 'Bring it back anytime.' ];
	const url = useIn( 5.4 );
	const providers = useIn( 5.9 );
	const out = interpolate( t, [ 7.4, 8.4 ], [ 1, 0 ], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' } );
	return (
		<AbsoluteFill>
			<Background />
			<AbsoluteFill style={ { display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', fontFamily: SANS, opacity: out, paddingBottom: 60 } }>
				<div style={ { transform: `scale(${ logo }) rotate(${ ( 1 - logo ) * -20 }deg) translateY(${ Math.sin( f / 20 ) * 6 }px)` } }>
					<Logo size={ 220 } />
				</div>
				<div style={ { fontSize: 104, fontWeight: 800, color: C.white, letterSpacing: -3, marginTop: 34, ...rise( name, 30 ) } }>OmniOffloader</div>
				<div style={ { display: 'flex', gap: 26, marginTop: 18 } }>
					{ words.map( ( w, i ) => {
						const p = useIn( [ 1.2, 2.7, 4.4 ][ i ] );
						return (
							<div key={ w } style={ { fontSize: 36, fontWeight: 600, color: i === 2 ? C.sky : C.text, ...rise( p, 20 ) } }>{ w }</div>
						);
					} ) }
				</div>
				<div style={ { marginTop: 50, ...rise( url, 20 ) } }>
					<Pill color={ C.white } bg="rgba(26,95,214,0.5)" size={ 30 } style={ { padding: '16px 34px', border: '1px solid rgba(124,180,255,0.5)' } }>
						<Icon name="globe" size={ 30 } color={ C.white } /> omnioffloader.vercel.app
					</Pill>
				</div>
				<div style={ { marginTop: 34, fontSize: 22, color: C.muted, letterSpacing: 1, ...rise( providers, 10 ) } }>
					Amazon S3 · Cloudflare R2 · DigitalOcean Spaces · Backblaze B2 · Wasabi · any S3-compatible storage
				</div>
			</AbsoluteFill>
		</AbsoluteFill>
	);
};

export { clamp01 };
