interface ProgressBarProps {
	value: number;
	max: number;
}

export function ProgressBar( { value, max }: ProgressBarProps ) {
	const percent =
		max > 0 ? Math.min( 100, Math.round( ( value / max ) * 100 ) ) : 0;

	return (
		<div
			className="smowp-progress"
			role="progressbar"
			aria-valuenow={ percent }
			aria-valuemin={ 0 }
			aria-valuemax={ 100 }
		>
			<div
				className="smowp-progress__fill"
				style={ { width: `${ percent }%` } }
			/>
			<span className="smowp-progress__label">{ percent }%</span>
		</div>
	);
}
