import React from 'react';
import { AbsoluteFill, useCurrentFrame, interpolate, Easing } from 'remotion';
import { C, SANS, MONO, useT, useIn, ramp, rise } from './theme';
import { Background, Icon, Logo, SceneTitle, Glass, Pill, BrowserFrame, FileTile, FlowLine, Node, clamp01, lerp, fmt } from './components';

/* ================================================================== */
/* 1. Hook — the server is filling up.                                 */
/* ================================================================== */
const ServerUnit: React.FC<{ w: number; h: number; blink: number; warn?: boolean }> = ( { w, h, blink, warn } ) => (
	<div style={ { width: w, height: h, borderRadius: 18, background: 'linear-gradient(180deg,#1c2b4d,#101b33)', border: '1px solid rgba(255,255,255,0.14)', display: 'flex', alignItems: 'center', padding: '0 28px', gap: 14, boxShadow: '0 20px 50px rgba(0,0,0,0.4)' } }>
		{ [ 0, 1, 2 ].map( ( i ) => (
			<div key={ i } style={ { width: 14, height: 14, borderRadius: 7, background: warn && i === 2 ? C.red : C.greenSoft, opacity: 0.35 + 0.65 * ( ( blink + i ) % 3 === 0 ? 1 : 0.3 ), boxShadow: `0 0 12px ${ warn && i === 2 ? C.red : C.greenSoft }` } } />
		) ) }
		<div style={ { flex: 1, marginLeft: 20, display: 'flex', flexDirection: 'column', gap: 9 } }>
			{ [ 0, 1, 2 ].map( ( i ) => (
				<div key={ i } style={ { height: 5, borderRadius: 3, background: 'rgba(255,255,255,0.12)' } } />
			) ) }
		</div>
	</div>
);

export const Hook: React.FC = () => {
	const t = useT();
	const f = useCurrentFrame();
	const fill = lerp( 0.41, 0.97, ramp( t, 0.3, 8.0, Easing.inOut( Easing.quad ) ) );
	const barColor = fill < 0.7 ? C.blueLight : fill < 0.86 ? C.amber : C.red;
	const sub = useIn( 3.5 );
	const serverIn = useIn( -0.2 );
	const shake = fill > 0.9 ? Math.sin( f * 1.7 ) * 2.5 : 0;
	const chips = [
		{ text: 'IMG_4021.jpg · 6.2 MB', x: 980, y: 300, at: 3.7 },
		{ text: 'promo-video.mp4 · 480 MB', x: 1500, y: 250, at: 4.4 },
		{ text: 'hero-1024x683.webp', x: 1450, y: 880, at: 5.0 },
		{ text: 'banner-scaled.jpg · 2.1 MB', x: 700, y: 640, at: 5.4 },
	];
	return (
		<AbsoluteFill>
			<Background />
			<SceneTitle eyebrow="The problem" title={ <>Your media library<br />is heavy.</> } width={ 760 } />
			<div style={ { position: 'absolute', left: 110, top: 400, width: 680, fontFamily: SANS, fontSize: 34, lineHeight: 1.45, color: C.muted, ...rise( sub ) } }>
				Images, thumbnails and video —<br />all sitting on <span style={ { color: C.white, fontWeight: 700 } }>expensive hosting disk</span>.
			</div>
			{ /* Falling files */ }
			{ Array.from( { length: 22 } ).map( ( _, i ) => {
				const start = -0.2 + i * 0.36;
				const k = clamp01( ( t - start ) / 1.1 );
				if ( k <= 0 || k >= 1 ) {
					return null;
				}
				const x = 1190 + ( ( i * 97 ) % 330 );
				const y = interpolate( k, [ 0, 1 ], [ -140, 430 ], { easing: Easing.in( Easing.quad ) } );
				return <FileTile key={ i } size={ 74 } hue={ ( i * 47 ) % 360 } style={ { position: 'absolute', left: x, top: y, opacity: k > 0.82 ? ( 1 - k ) / 0.18 : 1, transform: `rotate(${ ( i % 2 ? 1 : -1 ) * 12 * ( 1 - k ) }deg) scale(${ 1 - 0.3 * k })` } } />;
			} ) }
			{ /* Server stack */ }
			<div style={ { position: 'absolute', left: 1130, top: 420, transform: `translate(${ shake }px, ${ ( 1 - serverIn ) * 80 }px)`, opacity: serverIn } }>
				{ [ 0, 1, 2 ].map( ( i ) => (
					<div key={ i } style={ { marginBottom: 14 } }>
						<ServerUnit w={ 520 } h={ 100 } blink={ Math.floor( f / 6 ) + i } warn={ fill > 0.86 } />
					</div>
				) ) }
				<div style={ { marginTop: 22, fontFamily: SANS, color: C.text, fontSize: 24, display: 'flex', justifyContent: 'space-between' } }>
					<span style={ { fontWeight: 600 } }>Disk usage</span>
					<span style={ { fontWeight: 800, color: barColor, fontVariantNumeric: 'tabular-nums' } }>{ Math.round( fill * 100 ) }%</span>
				</div>
				<div style={ { marginTop: 10, width: 520, height: 16, borderRadius: 8, background: 'rgba(255,255,255,0.1)', overflow: 'hidden' } }>
					<div style={ { width: `${ fill * 100 }%`, height: '100%', borderRadius: 8, background: `linear-gradient(90deg, ${ C.blueLight }, ${ barColor })`, boxShadow: `0 0 20px ${ barColor }` } } />
				</div>
			</div>
			{ chips.map( ( c ) => {
				const p = useIn( c.at );
				return (
					<div key={ c.text } style={ { position: 'absolute', left: c.x, top: c.y + Math.sin( f / 25 + c.x ) * 6, ...rise( p, 20 ) } }>
						<Glass style={ { padding: '12px 20px', borderRadius: 14, fontFamily: MONO, fontSize: 20, display: 'flex', alignItems: 'center', gap: 10 } }>
							<Icon name="file" size={ 22 } color={ C.sky } />
							{ c.text }
						</Glass>
					</div>
				);
			} ) }
		</AbsoluteFill>
	);
};

/* ================================================================== */
/* 2. Intro — logo reveal, then server → bucket → CDN → visitors.      */
/* ================================================================== */
export const Intro: React.FC = () => {
	const t = useT();
	const logoIn = useIn( -0.35, 12 );
	const move = ramp( t, 1.5, 2.4 );
	const nameIn = useIn( 0.1 );
	const n1 = useIn( 2.2 );
	const n2 = useIn( 3.3 );
	const n3 = useIn( 5.9 );
	const n4 = useIn( 6.9 );
	const disk = lerp( 96, 22, ramp( t, 8.1, 9.8 ) );
	const badge = useIn( 9.5 );
	const ring = ( t + 0.4 ) % 1.6;
	const Y = 600;
	const xs = [ 300, 770, 1240, 1650 ];
	return (
		<AbsoluteFill>
			<Background />
			{ /* Logo + name, centred then docked at the top */ }
			<div style={ { position: 'absolute', left: 0, right: 0, top: lerp( 300, 70, move ), display: 'flex', flexDirection: 'column', alignItems: 'center' } }>
				<div style={ { position: 'relative', transform: `scale(${ lerp( 1, 0.42, move ) * logoIn })`, transformOrigin: 'top center', height: lerp( 240, 100, move ) } }>
					<Logo size={ 240 } />
					{ move < 1 ? (
						<div style={ { position: 'absolute', inset: -20 - ring * 60, borderRadius: '30%', border: `3px solid rgba(124,180,255,${ ( 1 - ring / 1.6 ) * 0.6 * ( 1 - move ) })` } } />
					) : null }
				</div>
				<div style={ { marginTop: lerp( 46, 14, move ), fontFamily: SANS, fontWeight: 800, fontSize: lerp( 96, 54, move ), color: C.white, letterSpacing: -2, ...rise( nameIn, 30 ) } }>OmniOffloader</div>
				<div style={ { fontFamily: SANS, fontSize: 30, color: C.muted, marginTop: 10, opacity: nameIn * ( 1 - move ) } }>Multi-cloud media offload for WordPress</div>
			</div>
			{ /* Flow diagram */ }
			<FlowLine x1={ xs[ 0 ] + 80 } y1={ Y - 25 } x2={ xs[ 1 ] - 80 } y2={ Y - 25 } on={ n2 } />
			<FlowLine x1={ xs[ 1 ] + 80 } y1={ Y - 25 } x2={ xs[ 2 ] - 80 } y2={ Y - 25 } on={ n3 } />
			<FlowLine x1={ xs[ 2 ] + 80 } y1={ Y - 25 } x2={ xs[ 3 ] - 80 } y2={ Y - 25 } on={ n4 } color={ C.greenSoft } />
			<Node icon="wp" label="Your WordPress" sub="media library" p={ n1 } x={ xs[ 0 ] } y={ Y }>
				<div style={ { marginTop: 16, opacity: ramp( t, 7.6, 8.1 ) } }>
					<div style={ { width: 180, margin: '0 auto', height: 12, borderRadius: 6, background: 'rgba(255,255,255,0.12)', overflow: 'hidden' } }>
						<div style={ { width: `${ disk }%`, height: '100%', background: disk > 60 ? C.red : C.greenSoft, borderRadius: 6 } } />
					</div>
					<div style={ { color: disk > 60 ? C.red : C.greenSoft, fontSize: 20, marginTop: 8, fontWeight: 700 } }>Disk { Math.round( disk ) }%</div>
				</div>
			</Node>
			<Node icon="cloud" label="Cloud storage" sub="your bucket" p={ n2 } x={ xs[ 1 ] } y={ Y } />
			<Node icon="globe" label="Your CDN" sub="cdn.example.com" p={ n3 } x={ xs[ 2 ] } y={ Y } />
			<Node icon="users" label="Visitors" sub="fast, from the edge" p={ n4 } x={ xs[ 3 ] } y={ Y } color="#16a34a" />
			<div style={ { position: 'absolute', left: 0, right: 0, top: 830, display: 'flex', justifyContent: 'center', ...rise( badge, 20 ) } }>
				<Pill size={ 28 }>
					<Icon name="check" size={ 28 } color={ C.greenSoft } stroke={ 2.6 } /> Not a single URL in your content changes
				</Pill>
			</div>
		</AbsoluteFill>
	);
};

/* ================================================================== */
/* 3. Providers — pick a bucket, test the connection.                  */
/* ================================================================== */
const PROVIDERS = [
	{ name: 'Amazon S3', mono: 'S3', color: '#ff9900', at: 0.5 },
	{ name: 'Cloudflare R2', mono: 'R2', color: '#f38020', at: 2.0 },
	{ name: 'DigitalOcean Spaces', mono: 'DO', color: '#0080ff', at: 3.9 },
	{ name: 'Backblaze B2', mono: 'B2', color: '#e21e29', at: 5.4 },
	{ name: 'Wasabi', mono: 'W', color: '#3cba54', at: 6.7 },
	{ name: 'Any S3-compatible', mono: 'S3+', color: '#8b5cf6', at: 8.0, sub: 'MinIO · Storj · OVHcloud · Linode…' },
];

export const Providers: React.FC = () => {
	const t = useT();
	const f = useCurrentFrame();
	const active = t >= 10.3 ? 1 : PROVIDERS.reduce( ( a, p, i ) => ( t >= p.at ? i : a ), 0 );
	const panel = useIn( 0.2 );
	const typing = ramp( t, 10.6, 11.7, Easing.linear );
	const clickAt = 12.1;
	const testing = t >= clickAt && t < 12.8;
	const ok = useIn( 12.8, 12 );
	// cursor path
	const cur = ramp( t, 11.2, clickAt - 0.05 );
	const press = t > clickAt - 0.05 && t < clickAt + 0.15 ? 0.88 : 1;
	return (
		<AbsoluteFill>
			<Background />
			<SceneTitle eyebrow="Storage providers" title="Bring your own bucket" />
			<div style={ { position: 'absolute', left: 110, top: 290, display: 'grid', gridTemplateColumns: '410px 410px', gap: 22 } }>
				{ PROVIDERS.map( ( p, i ) => {
					const pin = useIn( p.at );
					const on = i === active;
					return (
						<Glass key={ p.name } glow={ on } style={ { height: 150, padding: '0 26px', display: 'flex', alignItems: 'center', gap: 22, ...rise( pin, 30 ), transform: `${ rise( pin, 30 ).transform } scale(${ on ? 1.03 : 1 })` } }>
							<div style={ { width: 74, height: 74, borderRadius: 20, background: `linear-gradient(150deg, ${ p.color }, ${ p.color }aa)`, display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff', fontWeight: 800, fontSize: p.mono.length > 2 ? 22 : 28, boxShadow: `0 10px 30px ${ p.color }55` } }>{ p.mono }</div>
							<div>
								<div style={ { fontSize: 28, fontWeight: 700, color: C.white } }>{ p.name }</div>
								<div style={ { fontSize: 18, color: C.muted, marginTop: 4 } }>{ p.sub ?? 'S3-compatible object storage' }</div>
							</div>
						</Glass>
					);
				} ) }
			</div>
			{ /* Settings panel */ }
			<Glass style={ { position: 'absolute', left: 1020, top: 290, width: 790, padding: 40, ...rise( panel, 40 ) } }>
				<div style={ { display: 'flex', alignItems: 'center', gap: 14, fontSize: 26, fontWeight: 700, color: C.white, marginBottom: 28 } }>
					<Icon name="cloud" size={ 32 } color={ C.sky } /> Cloud Provider
				</div>
				{ [
					[ 'Storage provider', PROVIDERS[ active ].name, 1 ],
					[ 'Access key ID', '•'.repeat( Math.round( typing * 22 ) ), typing ],
					[ 'Secret access key', '•'.repeat( Math.round( clamp01( typing * 1.3 - 0.3 ) * 28 ) ), typing ],
					[ 'Custom domain (CDN URL)', 'https://cdn.example.com'.slice( 0, Math.round( clamp01( typing * 1.6 - 0.6 ) * 23 ) ), typing ],
				].map( ( [ label, value ] ) => (
					<div key={ label as string } style={ { marginBottom: 18 } }>
						<div style={ { fontSize: 16, letterSpacing: 1.5, color: C.muted, textTransform: 'uppercase', fontWeight: 600, marginBottom: 8 } }>{ label }</div>
						<div style={ { height: 54, borderRadius: 12, background: 'rgba(0,0,0,0.25)', border: '1px solid rgba(255,255,255,0.14)', display: 'flex', alignItems: 'center', padding: '0 18px', fontSize: 22, color: C.white, fontFamily: label === 'Storage provider' ? SANS : MONO } }>{ value as string }</div>
					</div>
				) ) }
				<div style={ { display: 'flex', alignItems: 'center', gap: 20, marginTop: 26 } }>
					<div style={ { padding: '16px 30px', borderRadius: 12, background: C.accent, color: '#fff', fontWeight: 700, fontSize: 22, transform: `scale(${ press })`, display: 'flex', alignItems: 'center', gap: 12 } }>
						{ testing ? <div style={ { width: 20, height: 20, borderRadius: 10, border: '3px solid rgba(255,255,255,0.3)', borderTopColor: '#fff', transform: `rotate(${ f * 18 }deg)` } } /> : null }
						Test Connection
					</div>
					<div style={ { opacity: ok, transform: `scale(${ 0.7 + 0.3 * ok })` } }>
						<Pill size={ 22 }>
							<Icon name="check" size={ 24 } color={ C.greenSoft } stroke={ 2.6 } /> Connected
						</Pill>
					</div>
				</div>
			</Glass>
			{ /* Cursor */ }
			<svg width={ 40 } height={ 40 } viewBox="0 0 24 24" style={ { position: 'absolute', left: lerp( 1650, 1180, cur ), top: lerp( 980, 805, cur ), opacity: ramp( t, 11.0, 11.3 ) * ( 1 - ramp( t, 13.3, 13.7 ) ), transform: `scale(${ press })`, filter: 'drop-shadow(0 4px 8px rgba(0,0,0,.5))' } }>
				<path d="M4 2 20 12l-7 1.5L9.5 21 4 2Z" fill="#fff" stroke="#111" strokeWidth={ 1.2 } />
			</svg>
		</AbsoluteFill>
	);
};

/* ================================================================== */
/* 4. Automatic offload + URL rewriting.                               */
/* ================================================================== */
const VARIANTS: Array<[ string, string, number ]> = [
	[ 'sunset.jpg', 'original', 5.0 ],
	[ 'sunset-150x150.jpg', 'thumbnail', 6.2 ],
	[ 'sunset-300x200.jpg', 'medium', 6.5 ],
	[ 'sunset-1024x683.jpg', 'large', 6.8 ],
	[ 'sunset-scaled.jpg', 'scaled', 7.4 ],
	[ 'sunset.webp', 'WebP', 8.5 ],
	[ 'sunset.avif', 'AVIF', 9.7 ],
];

export const AutoOffload: React.FC = () => {
	const t = useT();
	const phaseB = ramp( t, 10.5, 11.2 );
	const tile = useIn( 0.0 );
	const uploaded = ramp( t, 0.3, 1.6 );
	const fly = ramp( t, 1.9, 3.1, Easing.inOut( Easing.cubic ) );
	const listIn = useIn( 4.6 );
	const codeIn = useIn( 11.3 );
	const swap = ramp( t, 13.4, 14.3 );
	const dbIn = useIn( 18.0 );
	const host = swap < 0.5 ? 'example.com/wp-content/uploads' : 'cdn.example.com';
	const hostColor = swap < 0.5 ? C.amber : C.greenSoft;
	return (
		<AbsoluteFill>
			<Background />
			<SceneTitle eyebrow="Automatic offload" title="Every upload, straight to the cloud" width={ 1700 } />
			{ /* Phase A + B */ }
			<div style={ { opacity: 1 - phaseB, transform: `translateY(${ -phaseB * 60 }px)` } }>
				<Glass style={ { position: 'absolute', left: 110, top: 300, width: 560, padding: 34, ...rise( tile, 40 ) } }>
					<div style={ { position: 'relative', height: 300 } }>
						<FileTile size={ 300 } hue={ 20 } style={ { position: 'absolute', left: 96, top: 0, transform: `translate(${ fly * 520 }px, ${ -fly * 40 }px) scale(${ 1 - fly * 0.7 })`, opacity: 1 - fly * 0.9 } } />
						<FileTile size={ 300 } hue={ 20 } style={ { position: 'absolute', left: 96, top: 0, opacity: fly > 0.5 ? 1 : 0 } } />
					</div>
					<div style={ { fontFamily: MONO, fontSize: 24, color: C.white, marginTop: 24 } }>sunset.jpg</div>
					<div style={ { marginTop: 14, height: 10, borderRadius: 5, background: 'rgba(255,255,255,0.12)', overflow: 'hidden' } }>
						<div style={ { width: `${ uploaded * 100 }%`, height: '100%', background: C.blueLight } } />
					</div>
					<div style={ { marginTop: 12, fontSize: 20, color: fly > 0.95 ? C.greenSoft : C.muted, fontWeight: 600 } }>{ fly > 0.95 ? '✓ Offloaded in the background' : uploaded < 1 ? 'Uploading to WordPress…' : 'Sending to cloud…' }</div>
				</Glass>
				<Glass style={ { position: 'absolute', left: 760, top: 300, width: 1050, padding: '30px 38px', opacity: 0.35 + 0.65 * listIn } }>
					<div style={ { display: 'flex', alignItems: 'center', gap: 14, fontSize: 26, fontWeight: 700, color: C.white, marginBottom: 14 } }>
						<Icon name="cloudUp" size={ 34 } color={ C.sky } /> In your bucket
					</div>
					{ VARIANTS.map( ( [ file, kind, at ], i ) => {
						const p = useIn( at );
						return (
							<div key={ file } style={ { display: 'flex', alignItems: 'center', gap: 18, height: 62, borderTop: i ? '1px solid rgba(255,255,255,0.07)' : 'none', ...rise( p, 16 ) } }>
								<FileTile size={ 40 } hue={ 20 } />
								<div style={ { fontFamily: MONO, fontSize: 24, color: C.white, flex: 1 } }>{ file }</div>
								<div style={ { fontSize: 18, color: C.sky, background: 'rgba(124,180,255,0.12)', padding: '4px 14px', borderRadius: 999, fontWeight: 600 } }>{ kind }</div>
								<Icon name="check" size={ 28 } color={ C.greenSoft } stroke={ 2.6 } />
							</div>
						);
					} ) }
				</Glass>
			</div>
			{ /* Phase C: URL rewrite */ }
			<div style={ { position: 'absolute', left: 110, top: 340, width: 1700, ...rise( codeIn, 50 ) } }>
				<div style={ { display: 'flex', gap: 16, marginBottom: 22, fontFamily: SANS, fontSize: 26, color: C.muted, alignItems: 'center' } }>
					<Icon name="refresh" size={ 30 } color={ C.sky } /> Rewritten as the page renders — images, galleries, video, audio and <span style={ { fontFamily: MONO, color: C.white } }>srcset</span>
				</div>
				<Glass style={ { padding: '40px 46px', background: 'rgba(3,9,24,0.75)', fontFamily: MONO, fontSize: 28, lineHeight: 1.75, color: '#c7d6f5' } }>
					<div>
						<span style={ { color: '#7dd3fc' } }>&lt;img</span> <span style={ { color: '#c4b5fd' } }>src</span>=&quot;https://<span style={ { color: hostColor, background: `${ hostColor }22`, borderRadius: 6, padding: '0 6px' } }>{ host }</span>/2026/10/sunset.jpg&quot;
					</div>
					<div style={ { paddingLeft: 60 } }>
						<span style={ { color: '#c4b5fd' } }>srcset</span>=&quot;https://<span style={ { color: hostColor, background: `${ hostColor }22`, borderRadius: 6, padding: '0 6px' } }>{ host }</span>/2026/10/sunset-300x200.jpg 300w,
					</div>
					<div style={ { paddingLeft: 240 } }>
						https://<span style={ { color: hostColor, background: `${ hostColor }22`, borderRadius: 6, padding: '0 6px' } }>{ host }</span>/2026/10/sunset-1024x683.jpg 1024w&quot; <span style={ { color: '#7dd3fc' } }>/&gt;</span>
					</div>
				</Glass>
				<div style={ { display: 'flex', gap: 24, marginTop: 34 } }>
					{ [ 'Block editor', 'Classic editor', 'Page builders', 'Galleries', 'Video & audio' ].map( ( s, i ) => {
						const p = useIn( 15.5 + i * 0.25 );
						return (
							<div key={ s } style={ rise( p, 20 ) }>
								<Pill color={ C.sky } bg="rgba(124,180,255,0.1)" size={ 22 }>
									<Icon name="check" size={ 22 } color={ C.sky } stroke={ 2.4 } /> { s }
								</Pill>
							</div>
						);
					} ) }
				</div>
				<div style={ { marginTop: 34, ...rise( dbIn, 20 ) } }>
					<Pill size={ 28 }>
						<Icon name="db" size={ 30 } color={ C.greenSoft } /> Database untouched — 0 rows search-replaced
					</Pill>
				</div>
			</div>
		</AbsoluteFill>
	);
};

/* ================================================================== */
/* 5. Bulk offload + transfer speed.                                   */
/* ================================================================== */
export const Bulk: React.FC = () => {
	const t = useT();
	const f = useCurrentFrame();
	const shot = useIn( -0.1 );
	const card = useIn( 1.7 );
	const prog = ramp( t, 2.1, 15.5, Easing.inOut( Easing.sin ) );
	const total = 1240;
	const speedIn = useIn( 9.5 );
	const level = t < 12.6 ? 0 : t < 13.8 ? 1 : 2;
	const lanes = [ 1, 3, 6 ][ level ];
	const logs = [ 'beach-house.jpg', 'team-photo.png', 'product-07.webp', 'hero-banner.jpg', 'podcast-ep12.mp3', 'logo-dark.svg' ];
	const logStart = Math.floor( Math.max( 0, t - 2.4 ) / 0.9 );
	return (
		<AbsoluteFill>
			<Background />
			<SceneTitle eyebrow="Bulk offload" title="Move your whole library" />
			<div style={ { position: 'absolute', left: 110, top: 290, ...rise( shot, 50 ) } }>
				<BrowserFrame src="shots/bulk-offload.png" width={ 1000 } height={ 640 } imgWidth={ 1150 } offsetX={ 128 } zoom={ 1 } originY={ 60 } offsetY={ 42 } url="example.com/wp-admin/admin.php?page=omnioffloader&view=offload" />
			</div>
			<Glass style={ { position: 'absolute', left: 1170, top: 290, width: 640, padding: 32, ...rise( card, 40 ) } }>
				<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } }>
					<div style={ { fontSize: 26, fontWeight: 700, color: C.white, display: 'flex', gap: 12, alignItems: 'center' } }>
						<Icon name="cloudUp" size={ 32 } color={ C.sky } /> Bulk Offload
					</div>
					<div style={ { fontSize: 18, padding: '6px 14px', borderRadius: 8, border: '1px solid rgba(255,255,255,0.25)', color: C.muted } }>Cancel</div>
				</div>
				<div style={ { display: 'flex', justifyContent: 'space-between', marginTop: 24, fontSize: 22, color: C.muted, fontVariantNumeric: 'tabular-nums' } }>
					<span>{ fmt( prog * total ) } of { fmt( total ) } attachments</span>
					<span style={ { color: C.white, fontWeight: 700 } }>{ Math.round( prog * 100 ) }%</span>
				</div>
				<div style={ { marginTop: 12, height: 16, borderRadius: 8, background: 'rgba(255,255,255,0.1)', overflow: 'hidden' } }>
					<div style={ { width: `${ prog * 100 }%`, height: '100%', borderRadius: 8, background: `linear-gradient(90deg, ${ C.blue }, ${ C.blueLight })`, backgroundSize: '40px 40px', boxShadow: `0 0 18px ${ C.blueLight }` } } />
				</div>
				<div style={ { marginTop: 20, fontFamily: MONO, fontSize: 18, lineHeight: 1.8, color: C.muted, height: 98, overflow: 'hidden' } }>
					{ [ 0, 1, 2 ].map( ( k ) => {
						const name = logs[ ( logStart + k ) % logs.length ];
						return (
							<div key={ k } style={ { opacity: 1 - k * 0.3 } }>
								<span style={ { color: C.greenSoft } }>✓</span> { name } offloaded
							</div>
						);
					} ) }
				</div>
			</Glass>
			<Glass style={ { position: 'absolute', left: 1170, top: 640, width: 640, padding: 32, ...rise( speedIn, 40 ) } }>
				<div style={ { fontSize: 24, fontWeight: 700, color: C.white, display: 'flex', gap: 12, alignItems: 'center' } }>
					<Icon name="bolt" size={ 28 } color={ C.amber } /> Transfer speed
				</div>
				<div style={ { display: 'flex', marginTop: 20, background: 'rgba(0,0,0,0.25)', borderRadius: 12, padding: 5, position: 'relative' } }>
					<div style={ { position: 'absolute', top: 5, bottom: 5, left: 5 + level * 192, width: 188, borderRadius: 9, background: C.accent, transition: 'none' } } />
					{ [ 'Low · 1', 'Balanced · 3', 'Fast · 6' ].map( ( s, i ) => (
						<div key={ s } style={ { position: 'relative', width: 192, textAlign: 'center', padding: '10px 0', fontSize: 20, fontWeight: 600, color: i === level ? '#fff' : C.muted } }>{ s }</div>
					) ) }
				</div>
				<div style={ { position: 'relative', height: 64, marginTop: 18 } }>
					{ Array.from( { length: lanes } ).map( ( _, i ) => (
						<div key={ i } style={ { position: 'absolute', left: 0, right: 0, top: ( i + 0.5 ) * ( 64 / lanes ) - 2, height: 4, borderRadius: 2, background: 'rgba(124,180,255,0.12)' } }>
							{ [ 0, 1 ].map( ( d ) => (
								<div key={ d } style={ { position: 'absolute', top: -4, width: 26, height: 12, borderRadius: 6, background: C.sky, boxShadow: `0 0 10px ${ C.sky }`, left: `${ ( ( f * 1.6 + i * 37 + d * 50 ) % 94 ) }%` } } />
							) ) }
						</div>
					) ) }
				</div>
			</Glass>
		</AbsoluteFill>
	);
};
