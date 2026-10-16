import { Button, Icon } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import {
	cloudUpload,
	download,
	image,
	settings as settingsIcon,
	upload,
} from '@wordpress/icons';

import { Card } from '../components/ui/Card';
import { StatCard } from '../components/ui/StatCard';
import { StorageCard } from '../components/ui/StorageCard';
import { useCore } from '../context/CoreContext';
import { PLUGIN_ICON } from '../utils/constants';

interface DashboardProps {
	onNavigate: ( view: string ) => void;
}

export function DashboardPage( { onNavigate }: DashboardProps ) {
	// Saved settings: an unsaved provider pick must not show as active.
	const { stats, savedSettings: settings, providers } = useCore();

	const providerName = providers.find(
		( provider ) => provider.key === settings?.provider
	)?.name;

	const total = stats?.total ?? 0;
	const offloaded = stats?.offloaded ?? 0;
	const local = stats?.local ?? 0;

	const offloadedPct =
		total > 0 ? Math.round( ( offloaded / total ) * 100 ) : 0;

	// "Configured" means credentials are saved; the connection itself is
	// checked by Test Connection, so the hero does not claim it.
	const safeMode = !! stats?.safe_mode?.active;
	let summary: string = __(
		'New uploads are offloaded automatically and served from your cloud storage or CDN.',
		'omnioffloader'
	);

	if ( safeMode ) {
		summary = __(
			'Safe mode is on: nothing is uploaded to or deleted from your cloud storage. Media already offloaded still loads from it.',
			'omnioffloader'
		);
	} else if ( settings && ! settings.serve_from_cloud ) {
		summary = __(
			'New uploads are offloaded automatically. Your site still loads media from this server, because Serve Media from Cloud is off.',
			'omnioffloader'
		);
	}

	return (
		<div className="omnioffloader-page">
			<div className="omnioffloader-hero omnioffloader-card">
				<span className="omnioffloader-hero__icon">
					<Icon icon={ PLUGIN_ICON } size={ 36 } />
				</span>
				<div className="omnioffloader-hero__text">
					<div className="omnioffloader-hero__badge-wrap">
						{ stats?.configured && safeMode && (
							<span className="omnioffloader-hero__setup-badge">
								{ __( 'Safe mode', 'omnioffloader' ) }
							</span>
						) }
						{ stats?.configured && ! safeMode && (
							<span className="omnioffloader-hero__live-badge">
								<span className="omnioffloader-ui-badge-pulse" />
								{ __( 'Active', 'omnioffloader' ) }
							</span>
						) }
						{ ! stats?.configured && (
							<span className="omnioffloader-hero__setup-badge">
								{ __( 'Setup Required', 'omnioffloader' ) }
							</span>
						) }
					</div>
					<h2>
						{ stats?.configured && providerName
							? sprintf(
									/* translators: %s: cloud provider name. */
									__( 'Using %s', 'omnioffloader' ),
									providerName
								)
							: __( 'Not configured yet', 'omnioffloader' ) }
					</h2>
					<p>
						{ stats?.configured
							? summary
							: __(
									'Add your cloud storage credentials to get started.',
									'omnioffloader'
								) }
					</p>
					{ ! stats?.configured && (
						<Button
							variant="primary"
							onClick={ () => onNavigate( 'settings' ) }
						>
							{ __( 'Open Settings', 'omnioffloader' ) }
						</Button>
					) }
				</div>
			</div>

			<div className="omnioffloader-stats-grid">
				<StatCard
					icon={ image }
					value={ stats ? stats.total.toLocaleString() : '—' }
					label={ __( 'Total media items', 'omnioffloader' ) }
					variant="default"
				/>
				<StatCard
					icon={ cloudUpload }
					value={ stats ? stats.offloaded.toLocaleString() : '—' }
					label={ __( 'Offloaded to cloud', 'omnioffloader' ) }
					variant="success"
					badge={ total > 0 ? `${ offloadedPct }%` : undefined }
					onClick={ () => onNavigate( 'offload' ) }
				/>
				<StatCard
					icon={ upload }
					value={ stats ? stats.local.toLocaleString() : '—' }
					label={ __( 'Local only', 'omnioffloader' ) }
					variant="local"
					badge={
						local === 0 && total > 0
							? __( 'All Synced', 'omnioffloader' )
							: undefined
					}
					onClick={ () => onNavigate( 'offload' ) }
				/>
			</div>

			{ stats?.configured && stats.storage && (
				<StorageCard storage={ stats.storage } />
			) }

			<Card
				title={ __( 'Quick Actions', 'omnioffloader' ) }
				docs="getting-started/quick-start/"
				description={ __(
					'Manage your media library and cloud storage settings.',
					'omnioffloader'
				) }
			>
				<div className="omnioffloader-quick-actions-grid">
					<div
						className="omnioffloader-quick-action-card"
						role="button"
						tabIndex={ 0 }
						onClick={ () => onNavigate( 'offload' ) }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' || e.key === ' ' ) {
								e.preventDefault();
								onNavigate( 'offload' );
							}
						} }
					>
						<div className="omnioffloader-quick-action-card__icon omnioffloader-quick-action-card__icon--offload">
							<Icon icon={ cloudUpload } size={ 22 } />
						</div>
						<div className="omnioffloader-quick-action-card__info">
							<strong>
								{ __( 'Bulk Offload', 'omnioffloader' ) }
							</strong>
							<p>
								{ local > 0
									? sprintf(
											/* translators: %d: local files. */
											__(
												'%d local files ready to offload',
												'omnioffloader'
											),
											local
										)
									: __(
											'All media is currently offloaded',
											'omnioffloader'
										) }
							</p>
						</div>
						<span className="omnioffloader-quick-action-card__arrow">
							{ '→' }
						</span>
					</div>

					<div
						className="omnioffloader-quick-action-card"
						role="button"
						tabIndex={ 0 }
						onClick={ () => onNavigate( 'bring-back' ) }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' || e.key === ' ' ) {
								e.preventDefault();
								onNavigate( 'bring-back' );
							}
						} }
					>
						<div className="omnioffloader-quick-action-card__icon omnioffloader-quick-action-card__icon--restore">
							<Icon icon={ download } size={ 22 } />
						</div>
						<div className="omnioffloader-quick-action-card__info">
							<strong>
								{ __(
									'Bring Back (Restore)',
									'omnioffloader'
								) }
							</strong>
							<p>
								{ offloaded > 0
									? sprintf(
											/* translators: %d: offloaded files. */
											__(
												'%d offloaded files in cloud',
												'omnioffloader'
											),
											offloaded
										)
									: __(
											'No offloaded files to restore',
											'omnioffloader'
										) }
							</p>
						</div>
						<span className="omnioffloader-quick-action-card__arrow">
							{ '→' }
						</span>
					</div>

					<div
						className="omnioffloader-quick-action-card"
						role="button"
						tabIndex={ 0 }
						onClick={ () => onNavigate( 'settings' ) }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' || e.key === ' ' ) {
								e.preventDefault();
								onNavigate( 'settings' );
							}
						} }
					>
						<div className="omnioffloader-quick-action-card__icon omnioffloader-quick-action-card__icon--settings">
							<Icon icon={ settingsIcon } size={ 22 } />
						</div>
						<div className="omnioffloader-quick-action-card__info">
							<strong>
								{ __( 'Provider Settings', 'omnioffloader' ) }
							</strong>
							<p>
								{ providerName
									? sprintf(
											/* translators: %s: provider name. */
											__(
												'Manage %s & credentials',
												'omnioffloader'
											),
											providerName
										)
									: __(
											'Configure cloud bucket credentials',
											'omnioffloader'
										) }
							</p>
						</div>
						<span className="omnioffloader-quick-action-card__arrow">
							{ '→' }
						</span>
					</div>
				</div>
			</Card>
		</div>
	);
}
