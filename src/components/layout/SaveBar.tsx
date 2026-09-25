import { Button } from '@wordpress/components';
import { Icon, check, error } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

import { useCore } from '../../context/CoreContext';

export function SaveBar() {
	const { isDirty, isSaving, saveFeedback, save } = useCore();

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
					onClick={ () => void save() }
				>
					{ isSaving
						? __( 'Saving…', 'omnioffloader' )
						: __( 'Save changes', 'omnioffloader' ) }
				</Button>
			</div>
		</div>
	);
}
