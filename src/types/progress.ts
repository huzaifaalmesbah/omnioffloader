/**
 * Mirrors BulkOffloadProcessor::get_state() and OffloadController payloads.
 */

export type BulkStatus =
	| 'idle'
	| 'running'
	| 'done'
	| 'cancelled'
	/** Stopped early by the consecutive-failure circuit breaker. */
	| 'failed';

export interface ProcessedMediaItem {
	id: number;
	title: string;
	status: 'success' | 'error' | 'processing';
	timestamp: number;
	thumb?: string;
	error?: string;
}

export interface BulkState {
	status: BulkStatus;
	total: number;
	processed: number;
	errors: number;
	updated_at: number;
	/** Why the run stopped early; empty otherwise. */
	message?: string;
	/** Attachment in flight, 0 when idle. */
	current?: number;
	/** Current item details in flight. */
	current_item?: ProcessedMediaItem | null;
	recent?: ProcessedMediaItem[];
}

export interface StartBulkResponse {
	queued: number;
	state: BulkState;
}

/**
 * Mirrors BulkRestoreProcessor::get_state().
 */
export interface RestoreBulkState extends BulkState {
	delete_cloud: boolean;
}

export interface StartBulkRestoreResponse {
	queued: number;
	state: RestoreBulkState;
}

/**
 * Mirrors Helpers::get_restore_preflight().
 */
export interface RestorePreflight {
	offloaded: number;
	estimated_bytes: number;
	/** 0 means unknown (disk_free_space disabled on the host). */
	free_bytes: number;
	disk_ok: boolean;
}

export interface AttachmentErrorEntry {
	time: number;
	message: string;
}

export interface AttachmentErrors {
	id: number;
	title: string;
	errors: AttachmentErrorEntry[];
}
