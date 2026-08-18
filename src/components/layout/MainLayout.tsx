import { SnackbarList } from '@wordpress/components';

import { useCore } from '../../context/CoreContext';
import { Header } from './Header';
import { SaveBar } from './SaveBar';
import { Sidebar } from './Sidebar';

interface MainLayoutProps {
	activeView: string;
	onNavigate: ( view: string ) => void;
	children: React.ReactNode;
}

export function MainLayout( {
	activeView,
	onNavigate,
	children,
}: MainLayoutProps ) {
	const { notices, removeNotice } = useCore();

	return (
		<div className="smowp-app">
			<Sidebar activeView={ activeView } onNavigate={ onNavigate } />

			<div className="smowp-main">
				<Header activeView={ activeView } />

				<main className="smowp-content">{ children }</main>

				<SaveBar />
			</div>

			<SnackbarList
				className="smowp-snackbars"
				notices={ notices.map( ( notice ) => ( {
					id: String( notice.id ),
					content: notice.message,
					status: notice.status,
				} ) ) }
				onRemove={ ( id ) => removeNotice( Number( id ) ) }
			/>
		</div>
	);
}
