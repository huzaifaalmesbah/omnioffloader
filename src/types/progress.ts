/**
 * Mirrors BulkOffloadProcessor::get_state() and OffloadController payloads.
 */

import type { RetentionPolicy } from './settings';

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
	/** 'skipped': excluded by a filter — neither a success nor an error. */
	status: 'success' | 'error' | 'processing' | 'skipped';
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
 * Mirrors Helpers::hosting_quota(). Sizes in bytes.
 */
export interface HostingQuota {
	limit: number;
	used: number;
	remaining: number;
	/** Unix time cPanel last refreshed the figure. */
	updated_at: number;
}

/**
 * Mirrors Helpers::get_restore_preflight().
 */
export interface RestorePreflight {
	offloaded: number;
	/** Files the retention policy removed from the server, from metadata. */
	estimated_bytes: number;
	/** Attachments with files to download but no recorded size (pre-WP 6.0 uploads). */
	unsized: number;
	/** The hosting plan's storage quota, when readable (cPanel); null otherwise. */
	quota: HostingQuota | null;
	/** 0 means unknown (disk_free_space disabled on the host). */
	free_bytes: number;
	disk_ok: boolean;
}

/**
 * Mirrors BulkRetentionProcessor::get_state().
 */
export interface RetentionBulkState extends BulkState {
	/** Target policy, fixed when the run started. */
	policy: RetentionPolicy;
	/** Local bytes deleted so far. */
	freed_bytes: number;
	/** Files uploaded because the bucket was missing them. */
	repaired: number;
}

export interface StartBulkRetentionResponse {
	queued: number;
	state: RetentionBulkState;
}

/**
 * Mirrors Helpers::get_retention_preflight().
 */
export interface RetentionPreflight {
	/** The saved policy the run will apply. */
	policy: RetentionPolicy;
	/** Offloaded attachments following a different policy. */
	eligible: number;
	/** Of those, attachments that will lose local files. */
	to_clean: number;
	/** Of those, attachments that will get local files back. */
	to_download: number;
	/** Estimated local bytes freed (upper bound). */
	freed_bytes: number;
	/** Estimated bytes downloaded (upper bound). */
	download_bytes: number;
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

/**
 * Mirrors BulkMoveProcessor::get_state().
 */
export interface MoveBulkState extends BulkState {
	/** Folder media moves into, fixed when the run started ('' = bucket root). */
	folder: string;
}

export interface StartBulkMoveResponse {
	queued: number;
	state: MoveBulkState;
}

/**
 * Mirrors MoveController::preflight_response().
 */
export interface MovePreflight {
	/** The current folder ('' = bucket root). */
	folder: string;
	/** Offloaded attachments stored outside it. */
	elsewhere: number;
}

/** A bulk job type; WP-CLI runs one of these at a time. */
export type JobType = 'offload' | 'restore' | 'retention' | 'move';

/**
 * Mirrors CoreController::get_cli_job_response(): the WP-CLI job running
 * now. Counts are 0 until the run first reports.
 */
export interface CliJob {
	running: boolean;
	job: JobType | '';
	processed: number;
	total: number;
	failed: number;
	/** Unix time the run started; 0 when unknown. */
	started: number;
	/** Unix time the run last reported progress; 0 when unknown. */
	updated: number;
	/** Attachments per minute since the start; 0 until a minute in. */
	per_minute: number;
	/** Estimated seconds left; 0 until a minute in, or when done. */
	seconds_left: number;
	/** Files transferred at the same time; 0 when unknown. */
	files_at_once: number;
	/** Started without a terminal (nohup, cron). */
	background: boolean;
	pid: number;
	/** Log file of a background run; '' when none. */
	log: string;
	/** `wp omnioffloader stop` was asked: it finishes the files in progress. */
	stopping: boolean;
	/** No progress reported for over 2 minutes: likely a large file. */
	quiet: boolean;
}
