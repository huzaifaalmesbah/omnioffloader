import { Button, ToggleControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { Card } from '../components/ui/Card';
import { ProgressBar } from '../components/ui/ProgressBar';
import { useCore } from '../context/CoreContext';
import { useBringBack } from '../hooks/useBringBack';
import { formatBytes } from '../utils/formatBytes';

export function BringBackPage() {
	const { stats, settings } = useCore();
	const { preflight, state, busy, start, cancel, showProgress, dismiss } =
		useBringBack();
	const [ deleteCloud, setDeleteCloud ] = useState(
		settings?.bring_back_delete_cloud ?? false
	);

	const running = state?.status === 'running';
	const offloadedCount = preflight?.offloaded ?? stats?.offloaded ?? 0;
	const freeKnown = ( preflight?.free_bytes ?? 0 ) > 0;

	return (
		<div className="smowp-page">
			<Card
				title={ __( 'Bring Back', 'smart-media-offloader' ) }
				description={ __(
					'Restore offloaded media from the cloud back to your server. Downloads are atomic and verified — an attachment only switches back to local URLs once every file is safely on disk.',
					'smart-media-offloader'
				) }
			>
				{ ! stats?.configured && (
					<p className="smowp-muted">
						{ __(
							'Configure a cloud provider in Settings first.',
							'smart-media-offloader'
						) }
					</p>
				) }

				{ stats?.configured && ! running && (
					<>
						<p>
							{ sprintf(
								/* translators: %d: number of offloaded attachments. */
								__(
									'%d attachment(s) are offloaded and can be brought back.',
									'smart-media-offloader'
								),
								offloadedCount
							) }
						</p>

						{ preflight && offloadedCount > 0 && (
							<p className="smowp-muted">
								{ sprintf(
									/* translators: %s: formatted byte size. */
									__(
										'Estimated download: up to %s (files still on disk are skipped).',
										'smart-media-offloader'
									),
									formatBytes( preflight.estimated_bytes )
								) }
								{ freeKnown &&
									' · ' +
										sprintf(
											/* translators: %s: formatted byte size. */
											__(
												'Free disk space: %s',
												'smart-media-offloader'
											),
											formatBytes( preflight.free_bytes )
										) }
								{ ! freeKnown &&
									' · ' +
										__(
											'Free disk space could not be determined on this host.',
											'smart-media-offloader'
										) }
							</p>
						) }

						{ preflight && ! preflight.disk_ok && (
							<p className="smowp-warning">
								{ __(
									'Warning: free disk space may not be enough for a full restore. Free up space before starting.',
									'smart-media-offloader'
								) }
							</p>
						) }

						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Also delete files from the cloud after each verified restore',
								'smart-media-offloader'
							) }
							help={ __(
								'Off by default. Cloud objects are only removed after all files are verified on your server.',
								'smart-media-offloader'
							) }
							checked={ deleteCloud }
							onChange={ setDeleteCloud }
						/>

						<Button
							variant="primary"
							disabled={ busy || offloadedCount === 0 }
							isBusy={ busy }
							onClick={ () => void start( deleteCloud ) }
						>
							{ __(
								'Start Bring Back',
								'smart-media-offloader'
							) }
						</Button>
					</>
				) }

				{ showProgress && state && (
					<div className="smowp-bulk-progress">
						<ProgressBar
							value={ state.processed }
							max={ state.total }
						/>
						<p className="smowp-muted">
							{ sprintf(
								/* translators: 1: processed count, 2: total count, 3: error count. */
								__(
									'%1$d of %2$d processed — %3$d error(s)',
									'smart-media-offloader'
								),
								state.processed,
								state.total,
								state.errors
							) }
							{ state.status === 'done' &&
								' · ' +
									__( 'Finished.', 'smart-media-offloader' ) }
							{ state.status === 'cancelled' &&
								' · ' +
									__(
										'Cancelled.',
										'smart-media-offloader'
									) }
							{ state.delete_cloud &&
								' · ' +
									__(
										'Cloud copies are being deleted.',
										'smart-media-offloader'
									) }
						</p>

						{ running ? (
							<Button
								variant="secondary"
								isDestructive
								disabled={ busy }
								onClick={ () => void cancel() }
							>
								{ __( 'Cancel', 'smart-media-offloader' ) }
							</Button>
						) : (
							<Button variant="tertiary" onClick={ dismiss }>
								{ __( 'Dismiss', 'smart-media-offloader' ) }
							</Button>
						) }
					</div>
				) }
			</Card>

			<Card
				title={ __( 'Single attachments', 'smart-media-offloader' ) }
				description={ __(
					'You can also bring back one file at a time from the Media Library list — use the "Bring Back" row action on any offloaded attachment. Each attachment restores from the provider it was offloaded to, even if you switched providers since.',
					'smart-media-offloader'
				) }
			/>
		</div>
	);
}
