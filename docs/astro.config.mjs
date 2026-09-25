// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

// Public address of the docs site — used for the sitemap and canonical URLs.
// Set DOCS_SITE_URL in Vercel once the custom domain is connected.
const site = process.env.DOCS_SITE_URL || 'https://omnioffloader.vercel.app';

export default defineConfig( {
	site,
	redirects: {
		'/features/staging-and-dev-copies': '/features/safe-mode',
	},
	integrations: [
		starlight( {
			title: 'OmniOffloader',
			description:
				'Offload WordPress media to Amazon S3, Cloudflare R2, Wasabi or any S3-compatible storage, serve it from a CDN, and restore it anytime with Bring Back.',
			logo: { src: './src/assets/logo.svg' },
			favicon: '/favicon.svg',
			customCss: [ './src/styles/custom.css' ],
			social: [
				{
					icon: 'download',
					label: 'WordPress.org',
					href: 'https://wordpress.org/plugins/omnioffloader/',
				},
			],
			// The plugin repository is private: no "Edit this page" links.
			lastUpdated: false,
			sidebar: [
				{ label: 'Getting Started', items: [ { autogenerate: { directory: 'getting-started' } } ] },
				{ label: 'Storage Providers', items: [ { autogenerate: { directory: 'providers' } } ] },
				{ label: 'Features', items: [ { autogenerate: { directory: 'features' } } ] },
				{ label: 'WP-CLI', items: [ { autogenerate: { directory: 'wp-cli' } } ] },
				{ label: 'Developers', items: [ { autogenerate: { directory: 'developers' } } ] },
				{ label: 'Help', items: [ { autogenerate: { directory: 'help' } } ] },
				{ label: 'Changelog', link: '/changelog/' },
			],
		} ),
	],
} );
