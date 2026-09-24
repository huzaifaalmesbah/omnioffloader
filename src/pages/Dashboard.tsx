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

	return (
		<div className="smowp-page">
			<div className="smowp-hero smowp-card">
				<span className="smowp-hero__icon">
					<Icon icon={ PLUGIN_ICON } size={ 36 } />
				</span>
				<div className="smowp-hero__text">
					<div className="smowp-hero__badge-wrap">
						{ stats?.configured ? (
							<span className="smowp-hero__live-badge">
								<span className="smowp-badge-pulse" />
								{ __( 'Connected & Active', 'omnioffloader' ) }
							</span>
						) : (
							<span className="smowp-hero__setup-badge">
								{ __( 'Setup Required', 'omnioffloader' ) }
							</span>
						) }
					</div>
					<h2>
						{ stats?.configured && providerName
							? sprintf(
									/* translators: %s: cloud provider name. */
									__( 'Connected to %s', 'omnioffloader' ),
									providerName
								)
							: __( 'Not configured yet', 'omnioffloader' ) }
					</h2>
					<p>
						{ stats?.configured
							? __(
									'New uploads are offloaded automatically and served from your custom domain.',
									'omnioffloader'
								)
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

			<div className="smowp-stats-grid">
				<StatCard
					icon={ image }
					value={ String( stats?.total ?? '—' ) }
					label={ __( 'Total media items', 'omnioffloader' ) }
					variant="default"
				/>
				<StatCard
					icon={ cloudUpload }
					value={ String( stats?.offloaded ?? '—' ) }
					label={ __( 'Offloaded to cloud', 'omnioffloader' ) }
					variant="success"
					badge={ total > 0 ? `${ offloadedPct }%` : undefined }
					onClick={ () => onNavigate( 'offload' ) }
				/>
				<StatCard
					icon={ upload }
					value={ String( stats?.local ?? '—' ) }
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

			<Card
				title={ __( 'Quick Actions', 'omnioffloader' ) }
				description={ __(
					'Manage your media library and cloud storage settings.',
					'omnioffloader'
				) }
			>
				<div className="smowp-quick-actions-grid">
					<div
						className="smowp-quick-action-card"
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
						<div className="smowp-quick-action-card__icon smowp-quick-action-card__icon--offload">
							<Icon icon={ cloudUpload } size={ 22 } />
						</div>
						<div className="smowp-quick-action-card__info">
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
						<span className="smowp-quick-action-card__arrow">
							{ '→' }
						</span>
					</div>

					<div
						className="smowp-quick-action-card"
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
						<div className="smowp-quick-action-card__icon smowp-quick-action-card__icon--restore">
							<Icon icon={ download } size={ 22 } />
						</div>
						<div className="smowp-quick-action-card__info">
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
						<span className="smowp-quick-action-card__arrow">
							{ '→' }
						</span>
					</div>

					<div
						className="smowp-quick-action-card"
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
						<div className="smowp-quick-action-card__icon smowp-quick-action-card__icon--settings">
							<Icon icon={ settingsIcon } size={ 22 } />
						</div>
						<div className="smowp-quick-action-card__info">
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
						<span className="smowp-quick-action-card__arrow">
							{ '→' }
						</span>
					</div>
				</div>
			</Card>
		</div>
	);
}
