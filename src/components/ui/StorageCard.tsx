import { __, _n, sprintf } from '@wordpress/i18n';

import type { RetentionPolicy, StorageStats } from '../../types';
import { RETENTION_LABELS } from '../../utils/constants';
import { formatBytes } from '../../utils/formatBytes';
import { Card } from './Card';

interface StorageCardProps {
	storage: StorageStats;
}

/**
 * Where the offloaded media's bytes are: in the cloud, still on this server,
 * and how much space the retention policy freed — overall, per policy and
 * per provider/bucket. Totals come from the running counters, so the card
 * costs no library scan.
 */
export function StorageCard( { storage }: StorageCardProps ) {
	const policies = storage.policies.filter( ( p ) => p.count > 0 );
	const items = policies.reduce( ( sum, p ) => sum + p.count, 0 );

	if ( items === 0 ) {
		return null;
	}

	return (
		<Card
			title={ __( 'Storage', 'omnioffloader' ) }
			docs="features/dashboard/#storage"
			description={ __(
				'How much of your offloaded media is in the cloud, how much is still on this server, and the space your retention policy freed.',
				'omnioffloader'
			) }
		>
			<div className="omnioffloader-media-summary">
				<div className="omnioffloader-media-chip omnioffloader-media-chip--offloaded">
					<span className="omnioffloader-media-chip__label">
						{ __( 'In the cloud', 'omnioffloader' ) }
					</span>
					<strong className="omnioffloader-media-chip__value">
						{ formatBytes( storage.cloud_bytes ) }
					</strong>
				</div>
				<div className="omnioffloader-media-chip omnioffloader-media-chip--local">
					<span className="omnioffloader-media-chip__label">
						{ __( 'Still on this server', 'omnioffloader' ) }
					</span>
					<strong className="omnioffloader-media-chip__value">
						{ formatBytes( storage.server_bytes ) }
					</strong>
				</div>
				<div className="omnioffloader-media-chip">
					<span className="omnioffloader-media-chip__label">
						{ __( 'Space freed', 'omnioffloader' ) }
					</span>
					<strong className="omnioffloader-media-chip__value">
						{ formatBytes( storage.freed_bytes ) }
					</strong>
				</div>
			</div>

			<div className="omnioffloader-storage-scroll">
				<table className="omnioffloader-storage-table">
					<caption className="screen-reader-text">
						{ __(
							'Offloaded media by retention policy',
							'omnioffloader'
						) }
					</caption>
					<thead>
						<tr>
							<th scope="col">
								{ __( 'Retention policy', 'omnioffloader' ) }
							</th>
							<th scope="col">
								{ __( 'Items', 'omnioffloader' ) }
							</th>
							<th scope="col">
								{ __( 'In the cloud', 'omnioffloader' ) }
							</th>
							<th scope="col">
								{ __( 'On this server', 'omnioffloader' ) }
							</th>
							<th scope="col">
								{ __( 'Freed', 'omnioffloader' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ policies.map( ( p ) => (
							<tr key={ p.policy }>
								<th scope="row">
									{
										RETENTION_LABELS[
											p.policy as RetentionPolicy
										]
									}
								</th>
								<td>{ p.count.toLocaleString() }</td>
								<td>{ formatBytes( p.cloud_bytes ) }</td>
								<td>{ formatBytes( p.server_bytes ) }</td>
								<td>{ formatBytes( p.freed_bytes ) }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>

			{ storage.locations.length > 0 && (
				<div className="omnioffloader-storage-scroll">
					<table className="omnioffloader-storage-table">
						<caption className="screen-reader-text">
							{ __(
								'Offloaded media by provider and bucket',
								'omnioffloader'
							) }
						</caption>
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Provider', 'omnioffloader' ) }
								</th>
								<th scope="col">
									{ __( 'Bucket', 'omnioffloader' ) }
								</th>
								<th scope="col">
									{ __( 'Items', 'omnioffloader' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ storage.locations.map( ( location ) => (
								<tr
									key={ `${ location.provider }|${ location.bucket }` }
								>
									<th scope="row">
										{ location.name || location.provider }
									</th>
									<td>{ location.bucket || '—' }</td>
									<td>{ location.count.toLocaleString() }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			{ storage.unsized > 0 && (
				<p className="omnioffloader-storage-note">
					{ sprintf(
						/* translators: %d: number of media items. */
						_n(
							'%d item has no recorded file size (uploaded before WordPress 6.0), so the real totals are a little higher.',
							'%d items have no recorded file size (uploaded before WordPress 6.0), so the real totals are a little higher.',
							storage.unsized,
							'omnioffloader'
						),
						storage.unsized
					) }
				</p>
			) }
		</Card>
	);
}
