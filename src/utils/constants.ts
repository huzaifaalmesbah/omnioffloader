import type { JSX } from 'react';

import { __ } from '@wordpress/i18n';
import { cloud, cloudUpload, cog, download, home } from '@wordpress/icons';

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
		title: __( 'Dashboard', 'smart-media-offloader' ),
		subtitle: __( 'Overview & quick status', 'smart-media-offloader' ),
		icon: home,
	},
	{
		view: 'settings',
		title: __( 'Settings', 'smart-media-offloader' ),
		subtitle: __( 'Provider & policies', 'smart-media-offloader' ),
		icon: cog,
	},
	{
		view: 'offload',
		title: __( 'Offload', 'smart-media-offloader' ),
		subtitle: __( 'Move media to the cloud', 'smart-media-offloader' ),
		icon: cloudUpload,
	},
	{
		view: 'bring-back',
		title: __( 'Bring Back', 'smart-media-offloader' ),
		subtitle: __( 'Restore media to your server', 'smart-media-offloader' ),
		icon: download,
	},
];

export const DEFAULT_VIEW = 'dashboard';

export const PLUGIN_ICON = cloud;

export const PLUGIN_NAME = __(
	'Smart Media Offloader',
	'smart-media-offloader'
);

export function getRoute( view: string ): RouteMeta {
	return ROUTES.find( ( route ) => route.view === view ) ?? ROUTES[ 0 ];
}
