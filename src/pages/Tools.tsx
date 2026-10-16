import { CliReference } from '../components/tools/CliReference';
import { MoveFolderCard } from '../components/tools/MoveFolderCard';
import { RetentionSyncCard } from '../components/tools/RetentionSyncCard';

interface ToolsProps {
	onNavigate: ( view: string ) => void;
}

export function ToolsPage( { onNavigate }: ToolsProps ) {
	return (
		<div className="omnioffloader-page">
			<RetentionSyncCard />
			<MoveFolderCard />
			<CliReference onNavigate={ onNavigate } />
		</div>
	);
}
