import { Button, Icon } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { cloudUpload, image, upload } from '@wordpress/icons';

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

	return (
		<div className="smowp-page">
			<div className="smowp-hero smowp-card">
				<span className="smowp-hero__icon">
					<Icon icon={ PLUGIN_ICON } size={ 36 } />
				</span>
				<div className="smowp-hero__text">
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
				/>
				<StatCard
					icon={ cloudUpload }
					value={ String( stats?.offloaded ?? '—' ) }
					label={ __(
						'Offloaded to cloud',
						'smart-media-offloader'
					) }
				/>
				<StatCard
					icon={ upload }
					value={ String( stats?.local ?? '—' ) }
					label={ __( 'Local only', 'smart-media-offloader' ) }
				/>
			</div>

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
