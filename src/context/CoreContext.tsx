import {
	createContext,
	useCallback,
	useContext,
	useEffect,
	useMemo,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	fetchProviders,
	fetchSettings,
	fetchStats,
	saveSettings,
} from '../api/core';
import { saveCredentials } from '../api/provider';
import { errorMessage } from '../utils/errorHelper';
import type { MediaStats, ProviderInfo, Settings } from '../types';

export interface Notice {
	id: number;
	status: 'success' | 'error';
	message: string;
}

interface CoreContextValue {
	loading: boolean;
	settings: Settings | null;
	providers: ProviderInfo[];
	stats: MediaStats | null;
	notices: Notice[];
	isDirty: boolean;
	isSaving: boolean;
	updateSettings: ( partial: Partial< Settings > ) => void;
	updateCredentialValues: ( values: Record< string, string > ) => void;
	credentialDraft: Record< string, string >;
	save: () => Promise< void >;
	addNotice: ( status: Notice[ 'status' ], message: string ) => void;
	removeNotice: ( id: number ) => void;
	refreshStats: () => void;
}

const CoreContext = createContext< CoreContextValue | null >( null );

let noticeId = 0;

export function CoreProvider( { children }: { children: React.ReactNode } ) {
	const [ loading, setLoading ] = useState( true );
	const [ settings, setSettings ] = useState< Settings | null >( null );
	const [ providers, setProviders ] = useState< ProviderInfo[] >( [] );
	const [ stats, setStats ] = useState< MediaStats | null >( null );
	const [ notices, setNotices ] = useState< Notice[] >( [] );
	const [ settingsDirty, setSettingsDirty ] = useState( false );
	const [ credentialDraft, setCredentialDraft ] = useState<
		Record< string, string >
	>( {} );
	const [ isSaving, setIsSaving ] = useState( false );

	const addNotice = useCallback(
		( status: Notice[ 'status' ], message: string ) => {
			noticeId += 1;
			const id = noticeId;
			setNotices( ( current ) => [
				...current,
				{ id, status, message },
			] );
			window.setTimeout( () => {
				setNotices( ( current ) =>
					current.filter( ( notice ) => notice.id !== id )
				);
			}, 5000 );
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
				setProviders( loadedProviders );
				setStats( loadedStats );
			} )
			.catch( ( error: unknown ) => {
				addNotice( 'error', errorMessage( error ) );
			} )
			.finally( () => setLoading( false ) );
	}, [ addNotice ] );

	const updateSettings = useCallback( ( partial: Partial< Settings > ) => {
		setSettings( ( current ) =>
			current ? { ...current, ...partial } : current
		);
		setSettingsDirty( true );
	}, [] );

	const updateCredentialValues = useCallback(
		( values: Record< string, string > ) => {
			setCredentialDraft( ( current ) => ( { ...current, ...values } ) );
		},
		[]
	);

	const refreshStats = useCallback( () => {
		fetchStats()
			.then( setStats )
			.catch( () => {
				// Stats are non-critical; ignore refresh failures.
			} );
	}, [] );

	const isDirty = settingsDirty || Object.keys( credentialDraft ).length > 0;

	const save = useCallback( async () => {
		if ( ! settings ) {
			return;
		}

		setIsSaving( true );

		try {
			if ( settingsDirty ) {
				const saved = await saveSettings( settings );
				setSettings( saved );
				setSettingsDirty( false );
			}

			if (
				Object.keys( credentialDraft ).length > 0 &&
				settings.provider
			) {
				await saveCredentials( settings.provider, credentialDraft );
				setCredentialDraft( {} );
			}

			addNotice(
				'success',
				__( 'Changes saved.', 'omnioffloader' )
			);
			refreshStats();
		} catch ( error: unknown ) {
			addNotice( 'error', errorMessage( error ) );
		} finally {
			setIsSaving( false );
		}
	}, [ settings, settingsDirty, credentialDraft, addNotice, refreshStats ] );

	const value = useMemo(
		() => ( {
			loading,
			settings,
			providers,
			stats,
			notices,
			isDirty,
			isSaving,
			updateSettings,
			updateCredentialValues,
			credentialDraft,
			save,
			addNotice,
			removeNotice,
			refreshStats,
		} ),
		[
			loading,
			settings,
			providers,
			stats,
			notices,
			isDirty,
			isSaving,
			updateSettings,
			updateCredentialValues,
			credentialDraft,
			save,
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
