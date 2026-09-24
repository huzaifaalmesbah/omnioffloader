import { CliReference } from '../components/tools/CliReference';
import { RetentionSyncCard } from '../components/tools/RetentionSyncCard';

interface ToolsProps {
	onNavigate: ( view: string ) => void;
}

export function ToolsPage( { onNavigate }: ToolsProps ) {
	return (
		<div className="smowp-page">
			<RetentionSyncCard />
			<CliReference onNavigate={ onNavigate } />
		</div>
	);
}
