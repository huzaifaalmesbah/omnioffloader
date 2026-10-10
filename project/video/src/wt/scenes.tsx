import React from 'react';
import { AbsoluteFill, useCurrentFrame, interpolate, Easing } from 'remotion';
import { C, SANS, MONO, useT, ramp, rise, useIn } from '../theme';
import { Background, Icon, Logo, Glass, Pill, Node, FlowLine, lerp } from '../components';
import { Screen, Rect } from './Screen';
import { Panel, Brand, wordTime, sceneDuration } from './Panel';

const W = ( id: string ) => ( word: string, nth = 1 ) => wordTime( id, word, nth );
const ADMIN = 'example.com/wp-admin/admin.php?page=omnioffloader&view=';

/** Fade a block in at `a` and out at `b` (narration seconds). */
const window01 = ( t: number, a: number, b: number ) => ramp( t, a, a + 0.5 ) * ( 1 - ramp( t, b, b + 0.5 ) );

/* ================================================================== */
export const WtIntro: React.FC = () => {
	const t = useT();
	const f = useCurrentFrame();
	const w = W( 'intro' );
	const logo = useIn( -0.35, 12 );
	const name = useIn( 0.15 );
	const sub = useIn( w( 'walkthrough' ) - 0.2 );
	const flow = ramp( t, w( 'moves' ), w( 'moves' ) + 0.6 );
	const up = ramp( t, w( 'walkthrough' ) - 0.4, w( 'walkthrough' ) + 0.6 );
	const chapters = [ 'Install', 'Connect a bucket', 'Serve from your CDN', 'Retention policies', 'Bulk Offload', 'WP-CLI', 'Media Library', 'Bring Back', 'Tools', 'Safe mode' ];
	const nodes = [
		{ icon: 'wp' as const, label: 'WordPress', at: w( 'moves' ) },
		{ icon: 'cloud' as const, label: 'Cloud storage', at: w( 'storage' ) },
		{ icon: 'globe' as const, label: 'Your CDN', at: w( 'CDN' ) },
		{ icon: 'cloudDown' as const, label: 'Bring Back', at: w( 'back' ) },
	];
	return (
		<AbsoluteFill>
			<Background />
			<div style={ { position: 'absolute', left: 0, right: 0, top: lerp( 150, 70, up ), display: 'flex', flexDirection: 'column', alignItems: 'center' } }>
				<div style={ { transform: `scale(${ logo * lerp( 1, 0.6, up ) })`, transformOrigin: 'top center', height: lerp( 200, 120, up ) } }>
					<Logo size={ 200 } />
				</div>
				<div style={ { fontFamily: SANS, fontWeight: 800, fontSize: lerp( 92, 64, up ), color: C.white, letterSpacing: -2, marginTop: 26, ...rise( name, 30 ) } }>OmniOffloader</div>
				<div style={ { fontFamily: SANS, fontSize: 34, color: C.sky, fontWeight: 700, marginTop: 6, ...rise( sub, 20 ) } }>The full walkthrough</div>
			</div>
			<div style={ { opacity: flow * ( 1 - up ) } }>
				{ nodes.map( ( n, i ) => (
					<Node key={ n.label } icon={ n.icon } label={ n.label } p={ ramp( t, n.at, n.at + 0.5 ) } x={ 330 + i * 420 } y={ 720 } color={ i === 3 ? '#16a34a' : C.blueLight } />
				) ) }
				{ [ 0, 1, 2 ].map( ( i ) => (
					<FlowLine key={ i } x1={ 330 + i * 420 + 80 } y1={ 695 } x2={ 330 + ( i + 1 ) * 420 - 80 } y2={ 695 } on={ ramp( t, nodes[ i + 1 ].at, nodes[ i + 1 ].at + 0.6 ) } color={ i === 2 ? C.greenSoft : C.sky } reverse={ i === 2 } />
				) ) }
			</div>
			<div style={ { position: 'absolute', left: 160, right: 160, top: 470, display: 'flex', flexWrap: 'wrap', gap: 18, justifyContent: 'center' } }>
				{ chapters.map( ( c, i ) => {
					const p = ramp( t, w( 'walkthrough' ) + 0.3 + i * 0.32, w( 'walkthrough' ) + 0.8 + i * 0.32 );
					return (
						<div key={ c } style={ { ...rise( p, 20 ) } }>
							<Glass style={ { padding: '16px 26px', borderRadius: 16, display: 'flex', alignItems: 'center', gap: 14, fontSize: 28, fontWeight: 700, color: C.white } }>
								<span style={ { color: C.sky, fontFamily: MONO, fontSize: 22 } }>{ String( i + 1 ).padStart( 2, '0' ) }</span> { c }
							</Glass>
						</div>
					);
				} ) }
			</div>
			<div style={ { position: 'absolute', left: 0, right: 0, top: 760, textAlign: 'center', fontFamily: SANS, color: C.muted, fontSize: 26, opacity: ramp( t, w( 'one', 2 ) - 0.2, w( 'one', 2 ) + 0.4 ), transform: `translateY(${ Math.sin( f / 30 ) * 3 }px)` } }>
				Every feature, one by one — with the matching WP-CLI command
			</div>
		</AbsoluteFill>
	);
};

/* ================================================================== */
export const WtWporg: React.FC = () => {
	const t = useT();
	const w = W( 'wporg' );
	return (
		<AbsoluteFill>
			<Background />
			<Brand t={ t } />
			<Screen
				t={ t }
				appear={ ramp( t, -0.4, 0.2 ) }
				url="wordpress.org/plugins/omnioffloader/"
				shots={ [ { src: 'wporg', at: 0 } ] }
				cam={ [ { at: 0, s: 1, cx: 720, cy: 450 }, { at: w( 'plugin', 2 ) - 0.3, s: 1, cx: 720, cy: 1330, dur: 1.4 } ] }
				marks={ [
					{ at: w( 'free' ), until: w( 'plugin', 2 ) - 0.4, rect: [ 140, 610, 1160, 90 ], label: 'OmniOffloader on WordPress.org', pos: 'bottom', spot: true },
					{ at: w( 'provider' ), until: w( 'documentation' ) - 0.2, rect: [ 140, 1322, 770, 292 ], label: 'Every supported provider', pos: 'right', spot: true },
					{ at: w( 'documentation' ), rect: [ 140, 1256, 770, 50 ], label: 'Full documentation', pos: 'top', spot: true },
				] }
			/>
			<Panel
				t={ t }
				step={ 1 }
				title="Find it on WordPress.org"
				bullets={ [
					{ at: w( 'free' ), text: 'Free in the plugin directory' },
					{ at: w( 'provider' ), text: 'Every provider and feature listed' },
					{ at: w( 'documentation' ), text: <>Docs and provider guides at <b>omnioffloader.vercel.app</b></> },
				] }
			/>
		</AbsoluteFill>
	);
};

/* ================================================================== */
export const WtInstall: React.FC = () => {
	const t = useT();
	const w = W( 'install' );
	const typeAt = w( 'search' ) + 0.2;
	const clickInstall = w( 'install', 2 ) + 0.2;
	const clickActivate = w( 'Activate' ) + 0.25;
	return (
		<AbsoluteFill>
			<Background />
			<Brand t={ t } />
			<Screen
				t={ t }
				appear={ ramp( t, -0.4, 0.2 ) }
				url="example.com/wp-admin/plugin-install.php"
				shots={ [
					{ src: 'add-plugins', at: 0 },
					{ src: 'add-plugins-installing', at: clickInstall },
					{ src: 'add-plugins-activate', at: clickInstall + 1.5 },
				] }
				covers={ [ { rect: [ 170, 190, 1270, 360 ], until: typeAt + 1.2, color: '#f0f0f1' } ] }
				typing={ [ { at: typeAt, dur: 1.0, rect: 'add-plugins:search', text: 'OmniOffloader' } ] }
				cam={ [ { at: 0, s: 1 }, { at: typeAt + 1.3, s: 1.55, rect: 'add-plugins:card' } ] }
				marks={ [
					{ at: w( 'Plugins' ), until: typeAt - 0.3, rect: 'add-plugins:menuPlugins', label: 'Plugins → Add New Plugin', pos: 'right', pad: 4 },
					{ at: typeAt - 0.3, until: typeAt + 1.3, rect: 'add-plugins:search', pad: 6 },
				] }
				clicks={ [
					{ at: clickInstall, rect: 'add-plugins:button' },
					{ at: clickActivate, rect: 'add-plugins:button' },
				] }
			/>
			<Panel
				t={ t }
				step={ 2 }
				title="Install and activate"
				bullets={ [
					{ at: w( 'Plugins' ), text: <>Plugins → <b>Add New Plugin</b></> },
					{ at: w( 'search' ), text: <>Search for <b>OmniOffloader</b></> },
					{ at: w( 'Click' ), text: <><b>Install Now</b>, then <b>Activate</b></> },
				] }
				cli={ [ { at: w( 'terminal' ), lines: [ 'wp plugin install omnioffloader --activate' ] } ] }
			/>
		</AbsoluteFill>
	);
};

/* ================================================================== */
export const WtDashboard: React.FC = () => {
	const t = useT();
	const w = W( 'dashboard' );
	const navItem = ( i: number ): Rect => [ 174, 129 + i * 57, 251, 54 ];
	return (
		<AbsoluteFill>
			<Background />
			<Brand t={ t } />
			<Screen
				t={ t }
				appear={ ramp( t, -0.4, 0.2 ) }
				url={ `${ ADMIN }dashboard` }
				shots={ [ { src: 'dashboard-setup', at: 0 } ] }
				marks={ [
					{ at: w( 'menu' ), until: w( 'five' ), rect: 'dashboard-setup:menu', label: 'In your admin menu', pos: 'right', pad: 4 },
					...[ 'Dashboard', 'Settings', 'Offload', 'Bring', 'Tools' ].map( ( word, i ) => ( { at: w( word ), until: w( word ) + 1.1, rect: navItem( i ), pad: 2 } ) ),
					{ at: w( 'Nothing' ), rect: 'dashboard-setup:hero', label: 'Nothing uploads until you connect a provider', pos: 'bottom' as const, spot: true },
				] }
			/>
			<Panel
				t={ t }
				step={ 3 }
				title="Five admin screens"
				bullets={ [
					{ at: w( 'Dashboard' ), text: <><b>Dashboard</b>: overview and status</> },
					{ at: w( 'Settings' ), text: <><b>Settings</b>: provider and policies</> },
					{ at: w( 'Offload' ), text: <><b>Offload</b>: move media to the cloud</> },
					{ at: w( 'Bring' ), text: <><b>Bring Back</b>: restore to your server</> },
					{ at: w( 'Tools' ), text: <><b>Tools</b>: retention, move and WP-CLI</> },
				] }
			/>
		</AbsoluteFill>
	);
};

/* ================================================================== */
export const WtConnect: React.FC = () => {
	const t = useT();
	const w = W( 'connect' );
	const toCdn = w( 'bucket' ) + 0.6;
	const clickTest = w( 'Test' ) + 0.25;
	return (
		<AbsoluteFill>
			<Background />
			<Brand t={ t } />
			<Screen
				t={ t }
				appear={ ramp( t, -0.4, 0.2 ) }
				url={ `${ ADMIN }settings` }
				shots={ [ { src: 'settings', at: 0 }, { src: 'settings-cdn', at: toCdn } ] }
				covers={ [ { rect: [ 648, 396, 110, 34 ], until: clickTest + 0.7 } ] }
				cam={ [ { at: 0, s: 1 }, { at: w( 'provider' ), s: 1.35, rect: 'settings:providers' }, { at: w( 'Enter' ) - 0.3, s: 1.3, rect: [ 497, 600, 886, 200 ] }, { at: toCdn, s: 1.3, rect: [ 497, 300, 886, 160 ] }, { at: w( 'wp-config' ) - 0.5, s: 1 } ] }
				marks={ [
					{ at: w( 'provider' ), until: w( 'Enter' ) - 0.4, rect: 'settings:providers', label: 'Choose your provider', pos: 'top', spot: true },
					{ at: w( 'Enter' ), until: toCdn - 0.2, rect: [ 497, 520, 886, 330 ], label: 'Access key, secret, endpoint', pos: 'top', spot: true },
					{ at: toCdn, until: clickTest - 0.3, rect: [ 497, 214, 886, 70 ], label: 'Bucket name', pos: 'top' },
					{ at: clickTest + 0.6, until: w( 'wp-config' ) - 0.6, rect: [ 497, 388, 270, 46 ], label: 'Connected', pos: 'bottom', spot: true },
				] }
				clicks={ [ { at: clickTest, rect: [ 497, 394, 140, 38 ] } ] }
			/>
			<Panel
				t={ t }
				step={ 4 }
				title="Connect your bucket"
				bullets={ [
					{ at: w( 'Amazon' ), text: 'Amazon S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2, Wasabi or any S3-compatible storage' },
					{ at: w( 'Enter' ), text: 'Access key, secret and bucket name' },
					{ at: w( 'Connected' ), text: <><b>Test Connection</b> → Connected</> },
				] }
				cli={ [
					{
						at: w( 'wp-config' ) - 0.6,
						title: 'wp-config.php (optional)',
						prompt: false,
						lines: [ '# Keys stay out of the database', "define( 'OMNIOFFLOADER_CLOUDFLARE_R2_KEY', '…' );", "define( 'OMNIOFFLOADER_CLOUDFLARE_R2_SECRET', '…' );" ],
					},
				] }
			/>
		</AbsoluteFill>
	);
};

/* ================================================================== */
const Rewrite: React.FC<{ t: number; at: number; w: ( word: string, nth?: number ) => number }> = ( { t, at, w } ) => {
	const p = ramp( t, at, at + 0.6 );
	const swap = ramp( t, w( 'rewritten' ), w( 'rewritten' ) + 0.8 );
	const host = swap < 0.5 ? 'example.com/wp-content/uploads' : 'media.example.com/wp-media';
	const hc = swap < 0.5 ? C.amber : C.greenSoft;
	const H = ( { children }: { children: React.ReactNode } ) => <span style={ { color: hc, background: `${ hc }22`, borderRadius: 6, padding: '0 6px' } }>{ children }</span>;
	const chips: [ string, string ][] = [ [ 'Images', 'images' ], [ 'srcset', 'srcset' ], [ 'Galleries', 'galleries' ], [ 'Video', 'video' ], [ 'Audio', 'audio' ] ];
	return (
		<div style={ { position: 'absolute', left: 50, top: 96, width: 1220, height: 806, opacity: p, transform: `translateY(${ ( 1 - p ) * 30 }px)` } }>
			<Glass style={ { position: 'absolute', inset: 0, padding: '46px 52px', background: 'rgba(8,14,30,0.86)' } }>
				<div style={ { display: 'flex', alignItems: 'center', gap: 14, fontSize: 30, fontWeight: 700, color: C.white } }>
					<Icon name="refresh" size={ 34 } color={ C.sky } /> Rewritten as each page renders
				</div>
				<div style={ { marginTop: 34, fontFamily: MONO, fontSize: 25, lineHeight: 1.85, color: '#c7d6f5', background: 'rgba(0,0,0,0.35)', borderRadius: 16, padding: '28px 32px', border: '1px solid rgba(255,255,255,0.08)' } }>
					<div>
						<span style={ { color: '#7dd3fc' } }>&lt;img</span> <span style={ { color: '#c4b5fd' } }>src</span>=&quot;https://<H>{ host }</H>/2026/10/sunset.jpg&quot;
					</div>
					<div style={ { paddingLeft: 56 } }>
						<span style={ { color: '#c4b5fd' } }>srcset</span>=&quot;https://<H>{ host }</H>/2026/10/sunset-300x200.jpg 300w,
					</div>
					<div style={ { paddingLeft: 220 } }>
						https://<H>{ host }</H>/2026/10/sunset-1024x683.jpg 1024w&quot; <span style={ { color: '#7dd3fc' } }>/&gt;</span>
					</div>
				</div>
				<div style={ { display: 'flex', gap: 16, marginTop: 34, flexWrap: 'wrap' } }>
					{ chips.map( ( [ label, word ] ) => {
						const cp = ramp( t, w( word ), w( word ) + 0.4 );
						return (
							<div key={ label } style={ rise( cp, 16 ) }>
								<Pill color={ C.sky } bg="rgba(124,180,255,0.1)" size={ 24 }>
									<Icon name="check" size={ 24 } color={ C.sky } stroke={ 2.4 } /> { label }
								</Pill>
							</div>
						);
					} ) }
				</div>
				<div style={ { marginTop: 40, ...rise( ramp( t, w( 'Nothing' ), w( 'Nothing' ) + 0.5 ), 20 ) } }>
					<Pill size={ 28 }>
						<Icon name="db" size={ 30 } color={ C.greenSoft } /> Database untouched: no search-replace
					</Pill>
				</div>
			</Glass>
		</div>
	);
};

export const WtCdn: React.FC = () => {
	const t = useT();
	const w = W( 'cdn' );
	const rew = w( 'Once' ) - 0.2;
	return (
		<AbsoluteFill>
			<Background />
			<Brand t={ t } />
			<div style={ { opacity: 1 - ramp( t, rew, rew + 0.5 ) } }>
				<Screen
					t={ t }
					appear={ ramp( t, -0.4, 0.2 ) }
					url={ `${ ADMIN }settings` }
					shots={ [ { src: 'settings-cdn', at: 0 } ] }
					cam={ [ { at: 0, s: 1 }, { at: w( 'custom' ) - 0.3, s: 1.6, rect: [ 497, 270, 886, 110 ] } ] }
					marks={ [
						{ at: w( 'custom' ), until: w( 'Cloudflare' ) - 0.3, rect: [ 497, 276, 886, 72 ], label: 'Custom Domain (CDN URL)', pos: 'top', spot: true },
						{ at: w( 'Cloudflare' ), rect: [ 497, 352, 760, 26 ], label: 'R2: connect a domain or turn on r2.dev', pos: 'bottom', spot: true },
					] }
				/>
			</div>
			<Rewrite t={ t } at={ rew } w={ w } />
			<Panel
				t={ t }
				step={ 5 }
				title="Serve media from your CDN"
				bullets={ [
					{ at: w( 'custom' ), text: <>Custom domain, e.g. <b>media.yourdomain.com</b></> },
					{ at: w( 'Most' ), text: 'The bucket must be publicly readable' },
					{ at: w( 'Cloudflare' ), text: 'Cloudflare R2: connect a custom domain or turn on r2.dev' },
					{ at: w( 'Once' ), text: 'URLs rewritten on the fly, srcset included' },
					{ at: w( 'Nothing' ), text: 'No database changes, ever' },
				] }
			/>
		</AbsoluteFill>
	);
};

/* ================================================================== */
const Toggle: React.FC<{ on: number; x: number; y: number }> = ( { on, x, y } ) => (
	<div style={ { position: 'absolute', left: x, top: y, width: 36, height: 20, borderRadius: 10, background: on > 0.5 ? '#135dbe' : '#fff', border: `1.5px solid ${ on > 0.5 ? '#135dbe' : '#1e1e1e' }`, boxSizing: 'border-box' } }>
		<div style={ { position: 'absolute', top: 2.5, left: 3 + on * 15, width: 12, height: 12, borderRadius: 6, background: on > 0.5 ? '#fff' : '#1e1e1e' } } />
	</div>
);

export const WtServe: React.FC = () => {
	const t = useT();
	const w = W( 'serve' );
	const off = w( 'off' ) + 0.2;
	const back = w( 'back', 2 ) + 0.1;
	const on = 1 - ramp( t, off, off + 0.25 ) + ramp( t, back, back + 0.25 );
	const serving = t > off && t < back ? 'Loading from your server · cloud is a backup' : 'Loading from your CDN';
	return (
		<AbsoluteFill>
			<Background />
			<Brand t={ t } />
			<Screen
				t={ t }
				appear={ ramp( t, -0.4, 0.2 ) }
				url={ `${ ADMIN }settings` }
				shots={ [ { src: 'settings-retention', at: 0 } ] }
				cam={ [ { at: 0, s: 1 }, { at: w( 'Retain' ) - 0.3, s: 1.45, rect: [ 497, 190, 886, 260 ] } ] }
				layer={ t > off - 0.3 ? <><div style={ { position: 'absolute', left: 494, top: 388, width: 44, height: 26, background: '#fff' } } /><Toggle on={ on } x={ 497 } y={ 391 } /></> : null }
				marks={ [
					{ at: w( 'Retain' ), until: w( 'turn' ) - 0.2, rect: [ 497, 196, 886, 42 ], label: 'Retain Local Files', pos: 'top' },
					{ at: w( 'turn' ), rect: [ 497, 382, 886, 66 ], label: serving, pos: 'bottom', spot: true },
				] }
			/>
			<Panel
				t={ t }
				step={ 6 }
				title="Offload now, serve later"
				bullets={ [
					{ at: w( 'Retain' ), text: <>Works with <b>Retain Local Files</b></> },
					{ at: w( 'backup' ), text: 'Turn Serve Media from Cloud off: media is offloaded as a backup and loads from your server' },
					{ at: w( 'ready', 2 ), text: 'Turn it on when your CDN is ready: every page switches at once' },
					{ at: w( 'clear' ), text: 'Then clear your page cache', icon: 'bolt' },
				] }
			/>
		</AbsoluteFill>
	);
};

/* ================================================================== */
export const WtRetention: React.FC = () => {
	const t = useT();
	const w = W( 'retention' );
	const rows: Rect[] = [ [ 497, 196, 886, 42 ], [ 497, 251, 886, 42 ], [ 497, 306, 886, 42 ] ];
	return (
		<AbsoluteFill>
			<Background />
			<Brand t={ t } />
			<Screen
				t={ t }
				appear={ ramp( t, -0.4, 0.2 ) }
				url={ `${ ADMIN }settings` }
				shots={ [ { src: 'settings-retention', at: 0 } ] }
				cam={ [ { at: 0, s: 1 }, { at: w( 'policy' ) - 0.2, s: 1.45, rect: [ 497, 180, 886, 200 ] } ] }
				marks={ [
					{ at: w( 'Retain' ), until: w( 'Smart' ) - 0.3, rect: rows[ 0 ], spot: true },
					{ at: w( 'Smart' ), until: w( 'Full' ) - 0.3, rect: rows[ 1 ], spot: true },
					{ at: w( 'Full' ), until: w( 'never' ) - 0.3, rect: rows[ 2 ], spot: true },
				] }
			/>
			<Panel
				t={ t }
				step={ 7 }
				title="Choose a retention policy"
				bullets={ [
					{ at: w( 'Retain' ), text: <><b>Retain Local Files</b>: keep everything. Safest start</> },
					{ at: w( 'Smart' ), text: <><b>Smart Local Cleanup</b>: delete thumbnails, keep originals</> },
					{ at: w( 'Full' ), text: <><b>Full Cloud Migration</b>: remove every local copy</> },
					{ at: w( 'never' ), text: 'Nothing is deleted before its upload is confirmed in the bucket', icon: 'shield' },
				] }
			/>
		</AbsoluteFill>
	);
};

/* ================================================================== */
export const WtBehavior: React.FC = () => {
	const t = useT();
	const w = W( 'behavior' );
	const items: [ string, Rect, string ][] = [
		[ 'Background', [ 497, 166, 886, 48 ], 'Background Cloud Offload' ],
		[ 'Transfer', [ 497, 232, 886, 112 ], 'Transfer Speed' ],
		[ 'Mirror', [ 497, 363, 886, 90 ], 'Mirror Delete' ],
		[ 'prefix', [ 497, 556, 886, 130 ], 'Custom Path Prefix' ],
		[ 'Versioning', [ 497, 477, 886, 52 ], 'Object Versioning' ],
	];
	const marks = items.map( ( [ word, rect, label ], i ) => ( { at: w( word ), until: i < items.length - 1 ? w( items[ i + 1 ][ 0 ] ) - 0.3 : w( 'Save' ) - 0.3, rect, label, pos: 'top' as const, spot: true } ) );
	return (
		<AbsoluteFill>
			<Background />
			<Brand t={ t } />
			<Screen
				t={ t }
				appear={ ramp( t, -0.4, 0.2 ) }
				url={ `${ ADMIN }settings` }
				shots={ [ { src: 'settings-behavior', at: 0 } ] }
				cam={ [
					{ at: 0, s: 1 },
					...items.map( ( [ word, rect ] ) => ( { at: w( word ) - 0.3, s: 1.3, rect } ) ),
					{ at: w( 'Save' ) - 0.4, s: 1 },
				] }
				marks={ [ ...marks, { at: w( 'Save' ), rect: [ 1288, 846, 142, 44 ], label: 'Save changes', pos: 'top', spot: true } ] }
			/>
			<Panel
				t={ t }
				step={ 8 }
				title="Behavior settings"
				bullets={ [
					{ at: w( 'Background' ), text: <><b>Background Cloud Offload</b>: uploads stay fast</> },
					{ at: w( 'Transfer' ), text: <><b>Transfer Speed</b>: Low 1 · Balanced 3 · Fast 6 · Custom up to 10 files at once</> },
					{ at: w( 'Mirror' ), text: <><b>Mirror Delete</b>: deleting media removes the cloud copy</> },
					{ at: w( 'prefix' ), text: <><b>Path Prefix</b>: your own folder in the bucket</> },
					{ at: w( 'Versioning' ), text: <><b>Object Versioning</b>: no stale CDN files</> },
				] }
			/>
		</AbsoluteFill>
	);
};

/* ================================================================== */
export const WtAuto: React.FC = () => {
	const t = useT();
	const w = W( 'auto' );
	const done = w( 'Offloaded', 2 );
	return (
		<AbsoluteFill>
			<Background />
			<Brand t={ t } />
			<Screen
				t={ t }
				appear={ ramp( t, -0.4, 0.2 ) }
				url="example.com/wp-admin/upload.php?mode=list"
				shots={ [ { src: 'media', at: 0 }, { src: 'media-busy', at: w( 'Queued' ) - 0.3 }, { src: 'media-offloaded', at: done } ] }
				cam={ [ { at: 0, s: 1 }, { at: w( 'Queued' ) - 0.6, s: 1.5, rect: [ 183, 225, 900, 160 ] } ] }
				marks={ [
					{ at: w( 'original' ), until: w( 'Queued' ) - 0.5, rect: 'media:row1', label: 'A new upload', pos: 'bottom' },
					{ at: w( 'Queued' ), until: done - 0.1, rect: [ 846, 262, 60, 60 ], label: 'Queued…', pos: 'top', spot: true },
					{ at: done, rect: [ 846, 262, 60, 60 ], label: 'Offloaded', pos: 'top', spot: true },
				] }
			/>
			<Panel
				t={ t }
				step={ 9 }
				title="Automatic offload"
				bullets={ [
					{ at: w( 'automatically' ), text: 'Every new upload, in the background', icon: 'cloud' },
					{ at: w( 'original' ), text: 'The original' },
					{ at: w( 'thumbnail' ), text: 'Every thumbnail size' },
					{ at: w( 'scaled' ), text: <>The <b>-scaled</b> version</> },
					{ at: w( 'WebP' ), text: 'WebP and AVIF sources' },
				] }
			/>
		</AbsoluteFill>
	);
};

/* ================================================================== */
export const WtBulk: React.FC = () => {
	const t = useT();
	const w = W( 'bulk' );
	const clickStart = w( 'Start' ) + 0.3;
	return (
		<AbsoluteFill>
			<Background />
			<Brand t={ t } />
			<Screen
				t={ t }
				appear={ ramp( t, -0.4, 0.2 ) }
				url={ `${ ADMIN }offload` }
				shots={ [
					{ src: 'offload', at: 0 },
					{ src: 'offload-run-1', at: clickStart + 0.25 },
					{ src: 'offload-run-2', at: w( 'progress' ) + 0.4 },
					{ src: 'offload-run-3', at: w( 'background' ) },
					{ src: 'offload-run-4', at: w( 'Cancel' ) },
				] }
				cam={ [ { at: 0, s: 1 }, { at: w( 'total' ) - 0.3, s: 1.3, rect: [ 497, 225, 886, 180 ] }, { at: w( 'Choose' ) - 0.3, s: 1.3, rect: [ 497, 470, 886, 180 ] }, { at: clickStart + 0.2, s: 1.15, rect: [ 497, 600, 886, 260 ] } ] }
				marks={ [
					{ at: w( 'total' ), until: w( 'Choose' ) - 0.3, rect: 'offload:summary', label: 'Total · Offloaded · Local', pos: 'top', spot: true },
					{ at: w( 'Choose' ), until: clickStart - 0.6, rect: 'offload:scope', label: 'All Local Media, or a custom amount', pos: 'top', spot: true },
					{ at: w( 'progress' ), until: w( 'activity' ) - 0.2, rect: [ 497, 552, 886, 150 ], label: 'Live progress', pos: 'top', spot: true },
					{ at: w( 'activity' ), until: w( 'Cancel' ) - 0.3, rect: [ 506, 646, 868, 254 ], label: 'Activity log', pos: 'top', spot: true },
					{ at: w( 'Cancel' ), rect: [ 1290, 683, 72, 34 ], label: 'Cancel anytime', pos: 'left', spot: true },
				] }
				clicks={ [ { at: clickStart, rect: 'offload:start' } ] }
			/>
			<Panel
				t={ t }
				step={ 10 }
				title="Bulk Offload your library"
				bullets={ [
					{ at: w( 'total' ), text: 'See total, offloaded and local media' },
					{ at: w( 'custom' ), text: 'Try a small batch first: Custom Amount → 10' },
					{ at: w( 'progress' ), text: 'Live progress bar and activity log' },
					{ at: w( 'background' ), text: 'Runs in the background. Leave the page anytime' },
				] }
				cli={ [ { at: w( 'Start' ), lines: [ { text: 'wp omnioffloader offload --all --dry-run', at: w( 'Start' ) }, { text: 'wp omnioffloader offload --all --limit=500', at: w( 'Start' ) + 0.7 } ] } ] }
			/>
		</AbsoluteFill>
	);
};

/* ================================================================== */
const TermLine: React.FC<{ t: number; at: number; cmd?: string; out?: React.ReactNode; color?: string }> = ( { t, at, cmd, out, color } ) => {
	if ( t < at ) {
		return null;
	}
	if ( cmd ) {
		const n = Math.min( cmd.length, Math.floor( ( t - at ) * 55 ) );
		return (
			<div>
				<span style={ { color: C.greenSoft } }>~/site $ </span>
				{ cmd.slice( 0, n ) }
			</div>
		);
	}
	return <div style={ { color: color ?? '#8fa3c7' } }>{ out }</div>;
};

export const WtBulkCli: React.FC = () => {
	const t = useT();
	const w = W( 'bulkcli' );
	const start = w( 'recommend' );
	const watch = w( 'watch' );
	const status = w( 'status' );
	const admin = w( 'While' ) - 0.2;
	const stop = w( 'Stop' );
	const termOp = 1 - ramp( t, admin, admin + 0.5 ) + ramp( t, stop - 0.4, stop + 0.1 );
	const pct = Math.round( lerp( 3, 6, ramp( t, watch, status ) ) );
	return (
		<AbsoluteFill>
			<Background />
			<Brand t={ t } />
			<div style={ { opacity: ramp( t, admin, admin + 0.5 ) * ( 1 - ramp( t, stop - 0.4, stop + 0.1 ) ) } }>
				<Screen
					t={ t }
					url={ `${ ADMIN }offload` }
					shots={ [ { src: 'cli-job', at: 0 } ] }
					cam={ [ { at: 0, s: 1.3, rect: 'cli-job:notice' } ] }
					marks={ [ { at: admin + 0.4, rect: 'cli-job:notice', label: 'The admin screens show the WP-CLI job', pos: 'bottom', spot: true } ] }
				/>
			</div>
			<div style={ { position: 'absolute', left: 50, top: 96, width: 1220, height: 806, borderRadius: 16, overflow: 'hidden', opacity: termOp * ramp( t, -0.4, 0.2 ), boxShadow: '0 40px 100px rgba(0,0,0,0.6), 0 0 0 1px rgba(255,255,255,0.12)' } }>
				<div style={ { height: 44, background: '#1b2335', display: 'flex', alignItems: 'center', padding: '0 18px', gap: 9 } }>
					{ [ '#ff5f57', '#febc2e', '#28c840' ].map( ( c ) => (
						<div key={ c } style={ { width: 13, height: 13, borderRadius: 7, background: c } } />
					) ) }
					<div style={ { flex: 1, textAlign: 'center', fontFamily: SANS, fontSize: 16, color: '#8892a8', marginRight: 60 } }>ssh — example.com</div>
				</div>
				<div style={ { background: 'rgba(8,12,22,0.97)', height: 762, padding: '26px 30px', fontFamily: MONO, fontSize: 21, lineHeight: 1.65, color: '#d6e2ff', whiteSpace: 'pre-wrap' } }>
					<TermLine t={ t } at={ start - 0.2 } cmd="nohup wp omnioffloader offload --all --concurrency=64 > ~/offload.log 2>&1 &" />
					<TermLine t={ t } at={ start + 1.4 } out="[1] 11030" />
					<TermLine t={ t } at={ watch - 0.1 } cmd="tail -f ~/offload.log" />
					<TermLine t={ t } at={ watch + 0.4 } out="Uploading up to 64 files at once." />
					<TermLine t={ t } at={ watch + 0.7 } out={ `Offloading  ${ String( pct ).padStart( 2, ' ' ) }% [${ '='.repeat( pct ) }>${ ' '.repeat( 30 - pct ) }] 25:03 / 7:13:20` } color={ C.text } />
					<TermLine t={ t } at={ status - 0.3 } cmd="wp omnioffloader status" />
					{ t > status + 0.3 ? (
						<div style={ { color: '#b8c7e6' } }>
							{ [
								'Running now: WP-CLI offload (in the background, process 11030)',
								'  Progress:   3,420 of 59,177 (5.8%), 2 failed',
								'  Speed:      137 attachments per minute',
								'  Time left:  about 6 hours 48 mins',
								'  Transfers:  64 files at once',
								'  Stop:       wp omnioffloader stop',
							].map( ( l, i ) => (
								<div key={ i } style={ { opacity: ramp( t, status + 0.3 + i * 0.12, status + 0.5 + i * 0.12 ), color: i === 3 || i === 2 ? C.white : undefined } }>{ l }</div>
							) ) }
						</div>
					) : null }
					<TermLine t={ t } at={ stop } cmd="wp omnioffloader stop" />
					<TermLine t={ t } at={ stop + 1.1 } out="Asked the offload run (process 11030, 3,420 of 59,177 done) to stop after the file it is transferring." />
					<TermLine t={ t } at={ stop + 1.9 } out={ <><span style={ { color: C.greenSoft } }>Success:</span> Stopped. Run the same command again to continue where it left off.</> } color={ C.text } />
				</div>
			</div>
			<Panel
				t={ t }
				step={ 11 }
				title="Large library? Use WP-CLI"
				tip={ { at: w( 'five' ), title: 'Recommended for 5,000+ items', text: 'No browser tab, no web time limits.' } }
				bullets={ [
					{ at: w( 'sixty-four' ), text: <><b>--concurrency</b>: up to 64 files at once</>, icon: 'bolt' },
					{ at: w( 'background' ), text: <>Runs in the background with <b>nohup</b> and a log</> },
					{ at: w( 'status' ), text: <><b>status</b>: speed and time left</> },
					{ at: w( 'While' ), text: 'Progress shows in the admin too' },
					{ at: stop, text: <><b>stop</b>, then run again to continue</> },
				] }
			/>
		</AbsoluteFill>
	);
};

/* ================================================================== */
export const WtMedia: React.FC = () => {
	const t = useT();
	const w = W( 'media' );
	const edit = w( 'edit' ) - 0.2;
	const bulkAt = w( 'bulk' );
	const dd = window01( t, bulkAt, edit - 0.4 );
	const dropdown = (
		<div style={ { position: 'absolute', left: 182, top: 215, width: 252, background: '#fff', border: '1px solid #8c8f94', borderRadius: 4, boxShadow: '0 8px 24px rgba(0,0,0,0.2)', fontFamily: '-apple-system, "Segoe UI", Roboto, sans-serif', fontSize: 14, opacity: dd } }>
			{ [ 'Bulk actions', 'Delete permanently', 'Offload to Cloud', 'Bring Back to Server' ].map( ( o, i ) => (
				<div key={ o } style={ { padding: '7px 10px', background: i === 2 ? '#2271b1' : 'transparent', color: i === 2 ? '#fff' : '#1e1e1e' } }>{ o }</div>
			) ) }
		</div>
	);
	return (
		<AbsoluteFill>
			<Background />
			<Brand t={ t } />
			<Screen
				t={ t }
				appear={ ramp( t, -0.4, 0.2 ) }
				url={ t < edit ? 'example.com/wp-admin/upload.php?mode=list' : 'example.com/wp-admin/post.php?action=edit' }
				shots={ [ { src: 'media', at: 0 }, { src: 'attachment-edit', at: edit } ] }
				layer={ t < edit ? dropdown : null }
				cam={ [ { at: 0, s: 1 }, { at: w( 'Offload', 3 ) - 0.3, s: 1.5, rect: [ 183, 180, 760, 200 ] }, { at: edit, s: 1.25, rect: 'attachment-edit:box' } ] }
				marks={ [
					{ at: w( 'status' ), until: w( 'filter' ) - 0.2, rect: [ 846, 222, 104, 640 ], label: 'Offload status', pos: 'top', spot: true },
					{ at: w( 'filter' ), until: w( 'Offload', 3 ) - 0.3, rect: 'media:filter', label: 'Offloaded / Local only', pos: 'bottom', spot: true },
					{ at: w( 'Offload', 3 ), until: bulkAt - 0.2, rect: 'media:action1', label: 'Offload now · Bring Back', pos: 'right', spot: true, pad: 5 },
					{ at: bulkAt, until: edit - 0.3, rect: 'media:bulk', label: 'Up to 1,000 at once', pos: 'right', pad: 5 },
					{ at: edit + 0.3, rect: 'attachment-edit:box', label: 'The OmniOffloader box', pos: 'top', spot: true },
				] }
			/>
			<Panel
				t={ t }
				step={ 12 }
				title="Media Library"
				bullets={ [
					{ at: w( 'status' ), text: 'Live offload status on every file' },
					{ at: w( 'filter' ), text: 'Filter: Offloaded or Local only' },
					{ at: w( 'Offload', 3 ), text: <>Row actions: <b>Offload now</b>, <b>Bring Back</b></> },
					{ at: bulkAt, text: 'Bulk actions for up to 1,000 files' },
					{ at: edit, text: 'Edit screen: provider, folder, local copies, one-click buttons' },
				] }
				cli={ [ { at: w( 'thousand' ) + 0.3, lines: [ 'wp omnioffloader offload --ids=12,15,42' ] } ] }
			/>
		</AbsoluteFill>
	);
};

/* ================================================================== */
export const WtBringBack: React.FC = () => {
	const t = useT();
	const w = W( 'bringback' );
	const choose = w( 'Choose' ) - 0.3;
	const clickStart = w( 'Start' ) + 0.3;
	return (
		<AbsoluteFill>
			<Background />
			<Brand t={ t } />
			<Screen
				t={ t }
				appear={ ramp( t, -0.4, 0.2 ) }
				url={ `${ ADMIN }bring-back` }
				shots={ [ { src: 'bringback', at: 0 }, { src: 'bringback-options', at: choose }, { src: 'bringback-run', at: clickStart + 0.25 } ] }
				cam={ [
					{ at: 0, s: 1 },
					{ at: w( 'preflight' ) - 0.3, s: 1.45, rect: 'bringback:preflight' },
					{ at: choose, s: 1.2, rect: [ 497, 300, 886, 300 ] },
					{ at: clickStart + 0.2, s: 1.15, rect: [ 497, 300, 886, 400 ] },
				] }
				marks={ [
					{ at: w( 'preflight' ), until: choose - 0.2, rect: [ 497, 468, 886, 36 ], label: 'Estimated download · free disk space', pos: 'bottom', spot: true },
					{ at: w( 'Choose' ), until: w( 'Keep' ) - 0.3, rect: 'bringback-options:scope', label: 'All media, or a custom amount', pos: 'top', spot: true },
					{ at: w( 'Keep' ), until: clickStart - 0.6, rect: 'bringback-options:options', label: 'Keep Cloud Copies (recommended)', pos: 'top', spot: true },
					{ at: w( 'follow' ), until: w( 'cleanup' ), rect: [ 497, 296, 886, 400 ], label: 'Verified downloads, live progress', pos: 'top', spot: true },
				] }
				clicks={ [ { at: clickStart, rect: 'bringback-options:start' } ] }
			/>
			<Panel
				t={ t }
				step={ 13 }
				title="Bring Back"
				bullets={ [
					{ at: w( 'preflight' ), text: 'Preflight: download size and free space' },
					{ at: w( 'Keep' ), text: <><b>Keep Cloud Copies</b> is recommended</> },
					{ at: w( 'verified' ), text: 'Cloud delete only after every file is verified', icon: 'shield' },
				] }
				tip={ { at: w( 'cleanup' ), title: 'Using a cleanup policy?', text: 'Run Bring Back before you deactivate the plugin.' } }
				cli={ [ { at: w( 'restore' ), title: 'WP-CLI · large libraries', lines: [ { text: 'wp omnioffloader restore --all --dry-run', at: w( 'restore' ) }, { text: 'wp omnioffloader restore --all', at: w( 'restore' ) + 0.7 } ] } ] }
			/>
		</AbsoluteFill>
	);
};

/* ================================================================== */
export const WtTools: React.FC = () => {
	const t = useT();
	const w = W( 'tools' );
	const move = w( 'Changed' ) - 0.2;
	return (
		<AbsoluteFill>
			<Background />
			<Brand t={ t } />
			<Screen
				t={ t }
				appear={ ramp( t, -0.4, 0.2 ) }
				url={ `${ ADMIN }tools` }
				shots={ [ { src: 'tools', at: 0 }, { src: 'tools-move', at: move } ] }
				cam={ [ { at: 0, s: 1 }, { at: w( 'retention' ) - 0.3, s: 1.25, rect: 'tools:retention' }, { at: move, s: 1.15, rect: 'tools-move:move' } ] }
				marks={ [
					{ at: w( 'retention' ), until: w( 'checked' ) - 0.3, rect: 'tools:retention', label: 'Apply Retention Policy to Existing Media', pos: 'top', spot: true },
					{ at: w( 'checked' ), until: move - 0.2, rect: 'tools:retentionFacts', label: 'Checked in the cloud, by size, first', pos: 'bottom', spot: true },
					{ at: w( 'Move' ), rect: 'tools-move:move', label: 'Move Media to the Current Folder', pos: 'top', spot: true },
				] }
			/>
			<Panel
				t={ t }
				step={ 14 }
				title="Tools"
				bullets={ [
					{ at: w( 'retention' ), text: 'Apply a new policy to media you already offloaded' },
					{ at: w( 'checked' ), text: 'Every file checked in the cloud before a local delete', icon: 'shield' },
					{ at: w( 'Move' ), text: 'Move older media into the current folder, inside the bucket' },
				] }
				cli={ [
					{
						at: w( 'retention' ),
						lines: [ { text: 'wp omnioffloader retention --dry-run', at: w( 'retention' ) }, { text: 'wp omnioffloader retention', at: w( 'retention' ) + 0.6 }, { text: 'wp omnioffloader move --all', at: w( 'Move' ) } ],
					},
				] }
			/>
		</AbsoluteFill>
	);
};

/* ================================================================== */
export const WtSafe: React.FC = () => {
	const t = useT();
	const w = W( 'safe' );
	const shared = w( 'Several' ) - 0.2;
	return (
		<AbsoluteFill>
			<Background />
			<Brand t={ t } />
			<Screen
				t={ t }
				appear={ ramp( t, -0.4, 0.2 ) }
				url={ t < shared ? 'staging.example.com/wp-admin/admin.php?page=omnioffloader' : `${ ADMIN }settings` }
				shots={ [ { src: 'safe-mode', at: 0 }, { src: 'settings-behavior', at: shared } ] }
				cam={ [ { at: 0, s: 1 }, { at: w( 'Safe' ) - 0.3, s: 1.3, rect: 'safe-mode:banner' }, { at: shared, s: 1.4, rect: [ 497, 556, 886, 130 ] } ] }
				marks={ [
					{ at: w( 'Safe' ), until: shared - 0.2, rect: 'safe-mode:banner', label: 'Safe mode: this is a copy', pos: 'bottom', spot: true },
					{ at: shared + 0.3, rect: [ 497, 556, 886, 130 ], label: 'Each site gets its own Path Prefix', pos: 'top', spot: true },
				] }
			/>
			<Panel
				t={ t }
				step={ 15 }
				title="Safe mode and shared buckets"
				bullets={ [
					{ at: w( 'detects' ), text: 'Staging, dev and local copies are detected automatically', icon: 'shield' },
					{ at: w( 'blocks' ), text: 'No uploads or deletes on a copy' },
					{ at: w( 'loading' ), text: 'Media keeps loading from the cloud' },
					{ at: shared, text: 'Several sites, one bucket, each in its own folder' },
				] }
			/>
		</AbsoluteFill>
	);
};

/* ================================================================== */
export const WtCliRef: React.FC = () => {
	const t = useT();
	const w = W( 'cliref' );
	const cmds: [ string, string ][] = [
		[ 'offload', 'wp omnioffloader offload --all' ],
		[ 'restore', 'wp omnioffloader restore --all' ],
		[ 'retention', 'wp omnioffloader retention' ],
		[ 'move', 'wp omnioffloader move --all' ],
		[ 'status', 'wp omnioffloader status' ],
		[ 'stop', 'wp omnioffloader stop' ],
	];
	return (
		<AbsoluteFill>
			<Background />
			<Brand t={ t } />
			<Screen
				t={ t }
				appear={ ramp( t, -0.4, 0.2 ) }
				url={ `${ ADMIN }tools` }
				shots={ [ { src: 'tools-cli', at: 0 }, { src: 'tools-cmds', at: w( 'offload' ) + 1.2 } ] }
				cam={ [ { at: 0, s: 1 }, { at: w( 'reference' ), s: 1.2, rect: 'tools-cli:cliBackground' }, { at: w( 'offload' ) + 1.2, s: 1.2, rect: 'tools-cmds:cliFirst' } ] }
				marks={ [
					{ at: w( 'reference' ), until: w( 'offload' ) + 1.0, rect: 'tools-cli:cliBackground', label: 'Long jobs: start, watch, stop', pos: 'bottom', spot: true },
					{ at: w( 'offload' ) + 1.4, rect: 'tools-cmds:cliFirst', label: 'Every command, with copy buttons', pos: 'bottom', spot: true },
				] }
			/>
			<Panel
				t={ t }
				step={ 16 }
				title="WP-CLI reference"
				bullets={ [ { at: w( 'dry' ), text: <>Try any change with <b>--dry-run</b> first</> } ] }
				cli={ [ { at: w( 'offload' ) - 0.3, title: 'wp omnioffloader', lines: cmds.map( ( [ word, text ] ) => ( { text, at: w( word ) - 0.2 } ) ) } ] }
			/>
		</AbsoluteFill>
	);
};

/* ================================================================== */
export const WtOutro: React.FC = () => {
	const t = useT();
	const f = useCurrentFrame();
	const w = W( 'outro' );
	const logo = useIn( -0.3, 11 );
	const end = sceneDuration( 'outro' ) + 1.6;
	const out = interpolate( t, [ end, end + 1 ], [ 1, 0 ], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: Easing.in( Easing.quad ) } );
	return (
		<AbsoluteFill>
			<Background />
			<AbsoluteFill style={ { display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', fontFamily: SANS, opacity: out, paddingBottom: 70 } }>
				<div style={ { transform: `scale(${ logo }) translateY(${ Math.sin( f / 20 ) * 6 }px)` } }>
					<Logo size={ 200 } />
				</div>
				<div style={ { fontSize: 100, fontWeight: 800, color: C.white, letterSpacing: -3, marginTop: 30, ...rise( useIn( 0 ), 30 ) } }>OmniOffloader</div>
				<div style={ { fontSize: 36, fontWeight: 600, color: C.text, marginTop: 12, ...rise( useIn( w( 'Install' ) ), 20 ) } }>Free on WordPress.org</div>
				<div style={ { display: 'flex', gap: 22, marginTop: 40 } }>
					<div style={ rise( useIn( w( 'Install' ) + 0.4 ), 20 ) }>
						<Glass style={ { padding: '16px 26px', borderRadius: 14, fontFamily: MONO, fontSize: 24, color: C.text } }>
							<span style={ { color: C.greenSoft } }>$ </span>wp plugin install omnioffloader --activate
						</Glass>
					</div>
				</div>
				<div style={ { marginTop: 30, ...rise( useIn( w( 'guide' ) ), 20 ) } }>
					<Pill color={ C.white } bg="rgba(26,95,214,0.5)" size={ 30 } style={ { padding: '16px 34px', border: '1px solid rgba(124,180,255,0.5)' } }>
						<Icon name="globe" size={ 30 } color={ C.white } /> omnioffloader.vercel.app
					</Pill>
				</div>
			</AbsoluteFill>
		</AbsoluteFill>
	);
};
