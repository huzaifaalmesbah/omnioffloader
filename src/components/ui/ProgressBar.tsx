import { __, sprintf } from '@wordpress/i18n';

interface ProgressBarProps {
	value: number;
	max: number;
	label?: string;
	showPercentage?: boolean;
	className?: string;
}

export function ProgressBar( {
	value,
	max,
	label,
	showPercentage = true,
	className,
}: ProgressBarProps ) {
	const percent =
		max > 0 ? Math.min( 100, Math.round( ( value / max ) * 100 ) ) : 0;

	return (
		<div
			className={
				'omnioffloader-progress-wrapper' +
				( className ? ` ${ className }` : '' )
			}
		>
			{ ( label || showPercentage ) && (
				<div className="omnioffloader-progress-meta">
					{ label && (
						<span className="omnioffloader-progress-title">
							{ label }
						</span>
					) }
					{ showPercentage && (
						<span className="omnioffloader-progress-percent">
							{ percent }%
						</span>
					) }
				</div>
			) }
			<div
				className="omnioffloader-progress"
				role="progressbar"
				aria-label={ label || __( 'Progress', 'omnioffloader' ) }
				aria-valuenow={ percent }
				aria-valuemin={ 0 }
				aria-valuemax={ 100 }
				aria-valuetext={ sprintf(
					/* translators: 1: processed count, 2: total count. */
					__( '%1$d of %2$d', 'omnioffloader' ),
					value,
					max
				) }
			>
				<div
					className="omnioffloader-progress__fill"
					style={ { width: `${ percent }%` } }
				/>
			</div>
		</div>
	);
}
