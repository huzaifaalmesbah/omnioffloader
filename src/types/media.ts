/**
 * Mirrors CoreController::get_stats_response().
 */

export interface MediaStats {
	total: number;
	offloaded: number;
	local: number;
	configured: boolean;
	provider: string;
	secondary_configured?: boolean;
	secondary_active?: boolean;
	secondary_provider?: string;
}
