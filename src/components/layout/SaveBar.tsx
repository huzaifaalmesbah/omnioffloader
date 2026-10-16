import { Button } from '@wordpress/components';
import { Icon, check, error } from '@wordpress/icons';
import { __, _n, sprintf } from '@wordpress/i18n';

import { useCore } from '../../context/CoreContext';
import { folderLabel, uploadFolder } from '../../utils/folder';
import { useConfirm } from '../ui/ConfirmDialog';

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
	const askConfirm = useConfirm();

	const handleSave = async () => {
		const offloaded = stats?.offloaded ?? 0;
		const from = savedSettings ? uploadFolder( savedSettings ) : '';
		const to = settings ? uploadFolder( settings ) : '';

		// Offloaded media keeps its folder; make sure the split is intended.
		if (
			savedSettings &&
			offloaded > 0 &&
			from !== to &&
			! ( await askConfirm( {
				title: __( 'Change the bucket folder?', 'omnioffloader' ),
				message: sprintf(
					/* translators: 1: number of offloaded attachments, 2: current bucket folder, 3: new bucket folder. */
					_n(
						'%1$s offloaded attachment stays in %2$s and keeps working from there. Only media offloaded from now on goes to %3$s. After saving, move existing media with wp omnioffloader move --all (recommended, fastest) or from Tools → Move Media to the Current Folder.',
						'%1$s offloaded attachments stay in %2$s and keep working from there. Only media offloaded from now on goes to %3$s. After saving, move existing media with wp omnioffloader move --all (recommended, fastest) or from Tools → Move Media to the Current Folder.',
						offloaded,
						'omnioffloader'
					),
					offloaded.toLocaleString(),
					folderLabel( from ),
					folderLabel( to )
				),
				confirmLabel: __( 'Change folder and save', 'omnioffloader' ),
			} ) )
		) {
			return;
		}

		void save();
	};

	return (
		<div className="omnioffloader-savebar">
			<div className="omnioffloader-savebar__status">
				{ isDirty ? (
					<span className="omnioffloader-savebar__dirty-text">
						{ __( 'Unsaved changes', 'omnioffloader' ) }
					</span>
				) : (
					<span className="omnioffloader-savebar__clean-text">
						{ __( 'All changes saved', 'omnioffloader' ) }
					</span>
				) }
			</div>

			<div className="omnioffloader-savebar__actions">
				{ saveFeedback && (
					<div
						className={ `omnioffloader-savebar__feedback is-${ saveFeedback.status }` }
						role="status"
						aria-live="polite"
					>
						<span className="omnioffloader-savebar__feedback-icon">
							<Icon
								icon={
									saveFeedback.status === 'success'
										? check
										: error
								}
								size={ 18 }
							/>
						</span>
						<span className="omnioffloader-savebar__feedback-text">
							{ saveFeedback.message }
						</span>
					</div>
				) }

				<Button
					variant="primary"
					className="omnioffloader-savebar__button"
					disabled={ ! isDirty || isSaving }
					isBusy={ isSaving }
					onClick={ () => void handleSave() }
				>
					{ isSaving
						? __( 'Saving…', 'omnioffloader' )
						: __( 'Save changes', 'omnioffloader' ) }
				</Button>
			</div>
		</div>
	);
}
