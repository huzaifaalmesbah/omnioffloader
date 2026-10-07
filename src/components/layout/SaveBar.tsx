import { Button } from '@wordpress/components';
import { Icon, check, error } from '@wordpress/icons';
import { __, _n, sprintf } from '@wordpress/i18n';

import { useCore } from '../../context/CoreContext';
import { folderLabel, uploadFolder } from '../../utils/folder';

export function SaveBar() {
	const {
		isDirty,
		isSaving,
		saveFeedback,
		save,
		settings,
		savedSettings,
		stats,
	} = useCore();

	const handleSave = () => {
		const offloaded = stats?.offloaded ?? 0;
		const from = savedSettings ? uploadFolder( savedSettings ) : '';
		const to = settings ? uploadFolder( settings ) : '';

		// Offloaded media keeps its folder; make sure the split is intended.
		if (
			savedSettings &&
			offloaded > 0 &&
			from !== to &&
			// eslint-disable-next-line no-alert
			! window.confirm(
				sprintf(
					/* translators: 1: number of offloaded attachments, 2: current bucket folder, 3: new bucket folder. */
					_n(
						'Change the bucket folder anyway? %1$s offloaded attachment stays in %2$s; only media offloaded from now on goes to %3$s.',
						'Change the bucket folder anyway? %1$s offloaded attachments stay in %2$s; only media offloaded from now on goes to %3$s.',
						offloaded,
						'omnioffloader'
					),
					offloaded.toLocaleString(),
					folderLabel( from ),
					folderLabel( to )
				)
			)
		) {
			return;
		}

		void save();
	};

	return (
		<div className="smowp-savebar">
			<div className="smowp-savebar__status">
				{ isDirty ? (
					<span className="smowp-savebar__dirty-text">
						{ __( 'Unsaved changes', 'omnioffloader' ) }
					</span>
				) : (
					<span className="smowp-savebar__clean-text">
						{ __( 'All changes saved', 'omnioffloader' ) }
					</span>
				) }
			</div>

			<div className="smowp-savebar__actions">
				{ saveFeedback && (
					<div
						className={ `smowp-savebar__feedback is-${ saveFeedback.status }` }
						role="status"
						aria-live="polite"
					>
						<span className="smowp-savebar__feedback-icon">
							<Icon
								icon={
									saveFeedback.status === 'success'
										? check
										: error
								}
								size={ 18 }
							/>
						</span>
						<span className="smowp-savebar__feedback-text">
							{ saveFeedback.message }
						</span>
					</div>
				) }

				<Button
					variant="primary"
					className="smowp-savebar__button"
					disabled={ ! isDirty || isSaving }
					isBusy={ isSaving }
					onClick={ handleSave }
				>
					{ isSaving
						? __( 'Saving…', 'omnioffloader' )
						: __( 'Save changes', 'omnioffloader' ) }
				</Button>
			</div>
		</div>
	);
}
