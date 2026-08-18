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
	const { stats, settings, providers } = useCore();

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
								{ __(
									'Connected & Active',
									'smart-media-offloader'
								) }
							</span>
						) : (
							<span className="smowp-hero__setup-badge">
								{ __(
									'Setup Required',
									'smart-media-offloader'
								) }
							</span>
						) }
					</div>
					<h2>
						{ stats?.configured && providerName
							? sprintf(
									/* translators: %s: cloud provider name. */
									__(
										'Connected to %s',
										'smart-media-offloader'
									),
									providerName
							  )
							: __(
									'Not configured yet',
									'smart-media-offloader'
							  ) }
					</h2>
					<p>
						{ stats?.configured
							? __(
									'New uploads are offloaded automatically and served from your custom domain.',
									'smart-media-offloader'
							  )
							: __(
									'Add your Cloudflare R2 credentials to get started.',
									'smart-media-offloader'
							  ) }
					</p>
					{ ! stats?.configured && (
						<Button
							variant="primary"
							onClick={ () => onNavigate( 'settings' ) }
						>
							{ __( 'Open Settings', 'smart-media-offloader' ) }
						</Button>
					) }
				</div>
			</div>

			<div className="smowp-stats-grid">
				<StatCard
					icon={ image }
					value={ String( stats?.total ?? '—' ) }
					label={ __( 'Total media items', 'smart-media-offloader' ) }
					variant="default"
				/>
				<StatCard
					icon={ cloudUpload }
					value={ String( stats?.offloaded ?? '—' ) }
					label={ __(
						'Offloaded to cloud',
						'smart-media-offloader'
					) }
					variant="success"
					badge={ total > 0 ? `${ offloadedPct }%` : undefined }
					onClick={ () => onNavigate( 'offload' ) }
				/>
				<StatCard
					icon={ upload }
					value={ String( stats?.local ?? '—' ) }
					label={ __( 'Local only', 'smart-media-offloader' ) }
					variant="local"
					badge={
						local === 0 && total > 0
							? __( 'All Synced', 'smart-media-offloader' )
							: undefined
					}
					onClick={ () => onNavigate( 'offload' ) }
				/>
			</div>

			<Card
				title={ __( 'Quick Actions', 'smart-media-offloader' ) }
				description={ __(
					'Manage your media library and cloud storage settings.',
					'smart-media-offloader'
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
								{ __(
									'Bulk Offload',
									'smart-media-offloader'
								) }
							</strong>
							<p>
								{ local > 0
									? sprintf(
											/* translators: %d: local files. */
											__(
												'%d local files ready to offload',
												'smart-media-offloader'
											),
											local
									  )
									: __(
											'All media is currently offloaded',
											'smart-media-offloader'
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
									'smart-media-offloader'
								) }
							</strong>
							<p>
								{ offloaded > 0
									? sprintf(
											/* translators: %d: offloaded files. */
											__(
												'%d offloaded files in cloud',
												'smart-media-offloader'
											),
											offloaded
									  )
									: __(
											'No offloaded files to restore',
											'smart-media-offloader'
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
								{ __(
									'Provider Settings',
									'smart-media-offloader'
								) }
							</strong>
							<p>
								{ providerName
									? sprintf(
											/* translators: %s: provider name. */
											__(
												'Manage %s & credentials',
												'smart-media-offloader'
											),
											providerName
									  )
									: __(
											'Configure cloud bucket credentials',
											'smart-media-offloader'
									  ) }
							</p>
						</div>
						<span className="smowp-quick-action-card__arrow">
							{ '→' }
						</span>
					</div>
				</div>
			</Card>

			<Card
				title={ __( 'Roadmap', 'smart-media-offloader' ) }
				description={ __(
					'This is a pre-release. Live now: automatic offload on upload with retention policies, bulk offload, and Bring Back — restoring cloud files to your server. Coming next: more S3-compatible providers, added one per release.',
					'smart-media-offloader'
				) }
			/>
		</div>
	);
}
