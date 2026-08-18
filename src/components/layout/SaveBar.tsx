import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { useCore } from '../../context/CoreContext';

export function SaveBar() {
	const { isDirty, isSaving, save } = useCore();

	return (
		<div className="smowp-savebar">
			<span className="smowp-savebar__status">
				{ isDirty
					? __( 'Unsaved changes', 'smart-media-offloader' )
					: __( 'All changes saved', 'smart-media-offloader' ) }
			</span>
			<Button
				variant="primary"
				className="smowp-savebar__button"
				disabled={ ! isDirty || isSaving }
				isBusy={ isSaving }
				onClick={ () => void save() }
			>
				{ isSaving
					? __( 'Saving…', 'smart-media-offloader' )
					: __( 'Save changes', 'smart-media-offloader' ) }
			</Button>
		</div>
	);
}
