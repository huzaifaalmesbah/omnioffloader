import { Button, Icon, Modal } from '@wordpress/components';
import {
	createContext,
	useCallback,
	useContext,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { caution, info } from '@wordpress/icons';

export interface ConfirmOptions {
	title: string;
	message: React.ReactNode;
	/** Label of the button that goes ahead, e.g. "Move media". */
	confirmLabel: string;
	cancelLabel?: string;
	/** Red confirm button, for actions that delete or overwrite. */
	isDestructive?: boolean;
}

type Confirm = ( options: ConfirmOptions ) => Promise< boolean >;

const ConfirmContext = createContext< Confirm | null >( null );

/**
 * Ask the user to confirm an action in the plugin's own dialog, instead of
 * the browser's confirm(). Resolves true when they go ahead.
 *
 * @return confirm() function.
 */
export function useConfirm(): Confirm {
	const confirm = useContext( ConfirmContext );

	if ( ! confirm ) {
		throw new Error( 'useConfirm() must be used inside ConfirmProvider.' );
	}

	return confirm;
}

/**
 * Renders the one confirmation dialog the whole app shares.
 *
 * @param props          Props.
 * @param props.children App.
 */
export function ConfirmProvider( { children }: { children: React.ReactNode } ) {
	const [ options, setOptions ] = useState< ConfirmOptions | null >( null );
	const resolver = useRef< ( ( answer: boolean ) => void ) | null >( null );

	const confirm = useCallback< Confirm >(
		( next ) =>
			new Promise< boolean >( ( resolve ) => {
				// A dialog still open counts as cancelled.
				resolver.current?.( false );
				resolver.current = resolve;
				setOptions( next );
			} ),
		[]
	);

	const answer = ( value: boolean ) => {
		resolver.current?.( value );
		resolver.current = null;
		setOptions( null );
	};

	return (
		<ConfirmContext.Provider value={ confirm }>
			{ children }
			{ options && (
				<Modal
					title={ options.title }
					icon={
						<span className="omnioffloader-confirm__icon">
							<Icon
								icon={ options.isDestructive ? caution : info }
								size={ 22 }
							/>
						</span>
					}
					onRequestClose={ () => answer( false ) }
					className={
						'omnioffloader-confirm' +
						( options.isDestructive ? ' is-destructive' : '' )
					}
					overlayClassName="omnioffloader-confirm-overlay"
				>
					<div className="omnioffloader-confirm__message">
						{ options.message }
					</div>
					<div className="omnioffloader-confirm__actions">
						<Button
							className="omnioffloader-confirm__cancel"
							onClick={ () => answer( false ) }
						>
							{ options.cancelLabel ??
								__( 'Cancel', 'omnioffloader' ) }
						</Button>
						<Button
							className="omnioffloader-confirm__ok"
							onClick={ () => answer( true ) }
						>
							{ options.confirmLabel }
						</Button>
					</div>
				</Modal>
			) }
		</ConfirmContext.Provider>
	);
}
