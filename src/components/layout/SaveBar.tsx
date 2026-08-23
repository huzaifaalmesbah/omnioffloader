import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { useCore } from '../../context/CoreContext';

export function SaveBar() {
	const { isDirty, isSaving, save } = useCore();

	return (
		<div className="smowp-savebar">
			<span className="smowp-savebar__status">
				{ isDirty
					? __( 'Unsaved changes', 'omnioffloader' )
					: __( 'All changes saved', 'omnioffloader' ) }
			</span>
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
	);
}
