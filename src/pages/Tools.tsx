import { CliReference } from '../components/tools/CliReference';
import { FailedItemsCard } from '../components/tools/FailedItemsCard';
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
			<FailedItemsCard onNavigate={ onNavigate } />
			<CliReference onNavigate={ onNavigate } />
		</div>
	);
}
