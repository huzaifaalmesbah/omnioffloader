import {
	createContext,
	useCallback,
	useContext,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	fetchProviders,
	fetchSettings,
	fetchStats,
	saveSettings,
} from '../api/core';
import {
	fetchCredentials,
	saveCredentials,
	testConnection,
} from '../api/provider';
import { errorMessage } from '../utils/errorHelper';
import type {
	CredentialField,
	MediaStats,
	ProviderInfo,
	Settings,
} from '../types';

export interface SaveFeedback {
	status: 'success' | 'error';
	message: string;
}

export interface Notice {
	id: number;
	status: 'success' | 'error';
	message: string;
}

interface CoreContextValue {
	loading: boolean;
	/** Draft settings, as edited on the Settings page. */
	settings: Settings | null;
	/** Settings as last saved on the server — what the plugin actually uses. */
	savedSettings: Settings | null;
	providers: ProviderInfo[];
	stats: MediaStats | null;
	notices: Notice[];
	saveFeedback: SaveFeedback | null;
	isDirty: boolean;
	isSaving: boolean;
	updateSettings: ( partial: Partial< Settings > ) => void;
	updateCredentialValues: ( values: Record< string, string > ) => void;
	credentialDraft: Record< string, string >;
	credentialsFields: Record< string, CredentialField[] >;
	loadProviderCredentials: (
		provider: string
	) => Promise< CredentialField[] >;
	setProviderFields: ( provider: string, fields: CredentialField[] ) => void;
	save: () => Promise< void >;
	clearSaveFeedback: () => void;
	addNotice: ( status: Notice[ 'status' ], message: string ) => void;
	removeNotice: ( id: number ) => void;
	refreshStats: ( force?: boolean ) => void;
}

const CoreContext = createContext< CoreContextValue | null >( null );

let noticeId = 0;

export function CoreProvider( { children }: { children: React.ReactNode } ) {
	const [ loading, setLoading ] = useState( true );
	const [ settings, setSettings ] = useState< Settings | null >( null );
	const [ savedSettings, setSavedSettings ] = useState< Settings | null >(
		null
	);
	const [ providers, setProviders ] = useState< ProviderInfo[] >( [] );
	const [ stats, setStats ] = useState< MediaStats | null >( null );
	const [ notices, setNotices ] = useState< Notice[] >( [] );
	const [ settingsDirty, setSettingsDirty ] = useState( false );
	const [ credentialDraft, setCredentialDraft ] = useState<
		Record< string, string >
	>( {} );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ credentialsFields, setCredentialsFields ] = useState<
		Record< string, CredentialField[] >
	>( {} );
	const [ saveFeedback, setSaveFeedback ] = useState< SaveFeedback | null >(
		null
	);
	const feedbackTimeoutRef = useRef< number | null >( null );

	const clearSaveFeedback = useCallback( () => {
		if ( feedbackTimeoutRef.current ) {
			clearTimeout( feedbackTimeoutRef.current );
			feedbackTimeoutRef.current = null;
		}
		setSaveFeedback( null );
	}, [] );

	const setProviderFields = useCallback(
		( provider: string, fields: CredentialField[] ) => {
			setCredentialsFields( ( current ) => ( {
				...current,
				[ provider ]: fields,
			} ) );
		},
		[]
	);

	const loadProviderCredentials = useCallback(
		async ( provider: string ): Promise< CredentialField[] > => {
			const res = await fetchCredentials( provider );
			setProviderFields( provider, res.fields );
			return res.fields;
		},
		[ setProviderFields ]
	);

	const addNotice = useCallback(
		( status: Notice[ 'status' ], message: string ) => {
			noticeId += 1;
			const id = noticeId;
			setNotices( ( current ) => [
				...current,
				{ id, status, message },
			] );
			// Errors stay until dismissed; they may need reading or acting on.
			if ( status === 'success' ) {
				window.setTimeout( () => {
					setNotices( ( current ) =>
						current.filter( ( notice ) => notice.id !== id )
					);
				}, 5000 );
			}
		},
		[]
	);

	const removeNotice = useCallback( ( id: number ) => {
		setNotices( ( current ) =>
			current.filter( ( notice ) => notice.id !== id )
		);
	}, [] );

	useEffect( () => {
		Promise.all( [ fetchSettings(), fetchProviders(), fetchStats() ] )
			.then( ( [ loadedSettings, loadedProviders, loadedStats ] ) => {
				setSettings( loadedSettings );
				setSavedSettings( loadedSettings );
				setProviders( loadedProviders );
				setStats( loadedStats );
			} )
			.catch( ( error: unknown ) => {
				addNotice( 'error', errorMessage( error ) );
			} )
			.finally( () => setLoading( false ) );
	}, [ addNotice ] );

	const updateSettings = useCallback(
		( partial: Partial< Settings > ) => {
			setSettings( ( current ) => {
				if ( ! current ) {
					return current;
				}
				if (
					partial.provider &&
					partial.provider !== current.provider
				) {
					setCredentialDraft( {} );
				}
				return { ...current, ...partial };
			} );
			setSettingsDirty( true );
			clearSaveFeedback();
		},
		[ clearSaveFeedback ]
	);

	const updateCredentialValues = useCallback(
		( values: Record< string, string > ) => {
			setCredentialDraft( ( current ) => ( { ...current, ...values } ) );
			clearSaveFeedback();
		},
		[ clearSaveFeedback ]
	);

	const refreshStats = useCallback( ( force = true ) => {
		fetchStats( force )
			.then( setStats )
			.catch( () => {
				// Stats are non-critical; ignore refresh failures.
			} );
	}, [] );

	const isDirty = settingsDirty || Object.keys( credentialDraft ).length > 0;

	// Warn before leaving the page with unsaved changes.
	useEffect( () => {
		if ( ! isDirty ) {
			return;
		}

		const warn = ( event: BeforeUnloadEvent ) => {
			event.preventDefault();
			// Some browsers still require returnValue to be set.
			event.returnValue = '';
		};

		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ isDirty ] );

	const save = useCallback( async () => {
		if ( ! settings ) {
			return;
		}

		setIsSaving( true );
		clearSaveFeedback();

		// Credentials and settings are two requests; say which part was
		// saved when only the first one went through.
		let credentialsSaved = false;

		try {
			if (
				Object.keys( credentialDraft ).length > 0 &&
				settings.provider
			) {
				const testRes = await testConnection(
					settings.provider,
					credentialDraft
				);
				if ( ! testRes.connected ) {
					throw new Error(
						testRes.message ||
							__(
								'Could not connect to storage provider. Changes not saved.',
								'omnioffloader'
							)
					);
				}

				const response = await saveCredentials(
					settings.provider,
					credentialDraft
				);
				setProviderFields( settings.provider, response.fields );
				setCredentialDraft( {} );
				credentialsSaved = true;
			}

			if ( settingsDirty ) {
				const saved = await saveSettings( settings );
				setSettings( saved );
				setSavedSettings( saved );
				setSettingsDirty( false );
			}

			setSaveFeedback( {
				status: 'success',
				message: __( 'Changes saved.', 'omnioffloader' ),
			} );

			feedbackTimeoutRef.current = window.setTimeout( () => {
				setSaveFeedback( null );
			}, 4000 );

			refreshStats();
		} catch ( error: unknown ) {
			// Kept until the next edit or save: a message that disappears on
			// its own is easy to miss.
			setSaveFeedback( {
				status: 'error',
				message:
					credentialsSaved && settingsDirty
						? sprintf(
								/* translators: %s: why the settings were not saved. */
								__(
									'Your storage provider details were saved, but your other settings were not: %s',
									'omnioffloader'
								),
								errorMessage( error )
							)
						: errorMessage( error ),
			} );
		} finally {
			setIsSaving( false );
		}
	}, [
		settings,
		settingsDirty,
		credentialDraft,
		setProviderFields,
		clearSaveFeedback,
		refreshStats,
	] );

	const value = useMemo(
		() => ( {
			loading,
			settings,
			savedSettings,
			providers,
			stats,
			notices,
			saveFeedback,
			isDirty,
			isSaving,
			updateSettings,
			updateCredentialValues,
			credentialDraft,
			credentialsFields,
			loadProviderCredentials,
			setProviderFields,
			save,
			clearSaveFeedback,
			addNotice,
			removeNotice,
			refreshStats,
		} ),
		[
			loading,
			settings,
			savedSettings,
			providers,
			stats,
			notices,
			saveFeedback,
			isDirty,
			isSaving,
			updateSettings,
			updateCredentialValues,
			credentialDraft,
			credentialsFields,
			loadProviderCredentials,
			setProviderFields,
			save,
			clearSaveFeedback,
			addNotice,
			removeNotice,
			refreshStats,
		]
	);

	return (
		<CoreContext.Provider value={ value }>
			{ children }
		</CoreContext.Provider>
	);
}

export function useCore(): CoreContextValue {
	const context = useContext( CoreContext );

	if ( ! context ) {
		throw new Error( 'useCore must be used inside <CoreProvider>.' );
	}

	return context;
}
