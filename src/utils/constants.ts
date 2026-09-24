import type { JSX } from 'react';

import { __ } from '@wordpress/i18n';
import {
	cloud,
	cloudUpload,
	cog,
	download,
	home,
	tool,
} from '@wordpress/icons';

import { RetentionPolicy } from '../types';

/**
 * Route metadata — the single source both Sidebar and Header render from.
 */
export interface RouteMeta {
	view: string;
	title: string;
	subtitle: string;
	icon: JSX.Element;
}

export const ROUTES: RouteMeta[] = [
	{
		view: 'dashboard',
		title: __( 'Dashboard', 'omnioffloader' ),
		subtitle: __( 'Overview & quick status', 'omnioffloader' ),
		icon: home,
	},
	{
		view: 'settings',
		title: __( 'Settings', 'omnioffloader' ),
		subtitle: __( 'Provider & policies', 'omnioffloader' ),
		icon: cog,
	},
	{
		view: 'offload',
		title: __( 'Offload', 'omnioffloader' ),
		subtitle: __( 'Move media to the cloud', 'omnioffloader' ),
		icon: cloudUpload,
	},
	{
		view: 'bring-back',
		title: __( 'Bring Back', 'omnioffloader' ),
		subtitle: __( 'Restore media to your server', 'omnioffloader' ),
		icon: download,
	},
	{
		view: 'tools',
		title: __( 'Tools', 'omnioffloader' ),
		subtitle: __( 'Retention updates & WP-CLI', 'omnioffloader' ),
		icon: tool,
	},
];

/**
 * Short retention policy names, for places the full Settings labels do not fit.
 */
export const RETENTION_LABELS: Record< RetentionPolicy, string > = {
	[ RetentionPolicy.RetainLocal ]: __(
		'Retain Local Files',
		'omnioffloader'
	),
	[ RetentionPolicy.SmartCleanup ]: __(
		'Smart Local Cleanup',
		'omnioffloader'
	),
	[ RetentionPolicy.FullMigration ]: __(
		'Full Cloud Migration',
		'omnioffloader'
	),
};

export const DEFAULT_VIEW = 'dashboard';

export const PLUGIN_ICON = cloud;

export const PLUGIN_NAME = __( 'OmniOffloader', 'omnioffloader' );

export function getRoute( view: string ): RouteMeta {
	return ROUTES.find( ( route ) => route.view === view ) ?? ROUTES[ 0 ];
}
