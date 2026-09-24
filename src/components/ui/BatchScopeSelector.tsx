import { __, _n, sprintf } from '@wordpress/i18n';

import { radioKeyDown, radioTabIndex } from '../../utils/radioGroup';

export type ScopeMode = 'all' | 'custom';

interface BatchScopeSelectorProps {
	mode: ScopeMode;
	onModeChange: ( mode: ScopeMode ) => void;
	customCount: number;
	onCustomCountChange: ( count: number ) => void;
	totalEligible: number;
	disabled?: boolean;
	allTitle: string;
	allDescription: string;
	customTitle?: string;
	customDescription?: string;
}

const PRESETS = [ 5, 10, 25, 50, 100, 250, 500 ];

const SCOPE_MODES: readonly ScopeMode[] = [ 'all', 'custom' ];

export function BatchScopeSelector( {
	mode,
	onModeChange,
	customCount,
	onCustomCountChange,
	totalEligible,
	disabled = false,
	allTitle,
	allDescription,
	customTitle = __( 'Custom Amount', 'omnioffloader' ),
	customDescription = __(
		'Process a specific number of attachments instead of the entire library.',
		'omnioffloader'
	),
}: BatchScopeSelectorProps ) {
	if ( totalEligible <= 0 ) {
		return null;
	}

	const presetsToShow = PRESETS.filter( ( p ) => p < totalEligible );

	const handleNumberChange = ( valStr: string ) => {
		if ( '' === valStr ) {
			onCustomCountChange( 0 );
			return;
		}
		const parsed = parseInt( valStr, 10 );
		if ( isNaN( parsed ) ) {
			return;
		}
		const clamped = Math.max( 1, Math.min( parsed, totalEligible ) );
		onCustomCountChange( clamped );
	};

	return (
		<div className="smowp-batch-scope">
			<h4 className="smowp-batch-scope__title">
				{ __( 'Processing Quantity', 'omnioffloader' ) }
			</h4>

			<div
				className="smowp-batch-scope__grid"
				role="radiogroup"
				aria-label={ __(
					'Processing Quantity Options',
					'omnioffloader'
				) }
			>
				{ /* Option 1: All Media */ }
				<div
					role="radio"
					aria-checked={ mode === 'all' }
					tabIndex={ radioTabIndex(
						SCOPE_MODES,
						'all',
						mode,
						disabled
					) }
					className={
						'smowp-option-card' +
						( mode === 'all' ? ' is-selected' : '' ) +
						( disabled ? ' is-disabled' : '' )
					}
					onClick={ () => {
						if ( ! disabled ) {
							onModeChange( 'all' );
						}
					} }
					onKeyDown={ ( e ) => {
						if ( ! disabled ) {
							radioKeyDown( e, SCOPE_MODES, 'all', onModeChange );
						}
					} }
				>
					<div className="smowp-option-card__radio">
						<span className="smowp-radio-circle">
							{ mode === 'all' && (
								<span className="smowp-radio-dot" />
							) }
						</span>
					</div>
					<div className="smowp-option-card__content">
						<div className="smowp-option-card__head">
							<strong>{ allTitle }</strong>
							<span className="smowp-badge smowp-badge--neutral">
								{ sprintf(
									/* translators: %d: total eligible attachments */
									_n(
										'%d item',
										'%d items',
										totalEligible,
										'omnioffloader'
									),
									totalEligible
								) }
							</span>
						</div>
						<p>{ allDescription }</p>
					</div>
				</div>

				{ /* Option 2: Custom Amount */ }
				<div
					role="radio"
					aria-checked={ mode === 'custom' }
					tabIndex={ radioTabIndex(
						SCOPE_MODES,
						'custom',
						mode,
						disabled
					) }
					className={
						'smowp-option-card' +
						( mode === 'custom' ? ' is-selected' : '' ) +
						( disabled ? ' is-disabled' : '' )
					}
					onClick={ () => {
						if ( ! disabled ) {
							onModeChange( 'custom' );
						}
					} }
					onKeyDown={ ( e ) => {
						if ( ! disabled ) {
							radioKeyDown(
								e,
								SCOPE_MODES,
								'custom',
								onModeChange
							);
						}
					} }
				>
					<div className="smowp-option-card__radio">
						<span className="smowp-radio-circle">
							{ mode === 'custom' && (
								<span className="smowp-radio-dot" />
							) }
						</span>
					</div>
					<div className="smowp-option-card__content">
						<div className="smowp-option-card__head">
							<strong>{ customTitle }</strong>
							<span className="smowp-badge smowp-badge--neutral">
								{ sprintf(
									/* translators: %d: custom count */
									_n(
										'%d item',
										'%d items',
										customCount > 0
											? customCount
											: totalEligible,
										'omnioffloader'
									),
									customCount > 0
										? customCount
										: totalEligible
								) }
							</span>
						</div>
						<p>{ customDescription }</p>
					</div>
				</div>
			</div>

			{ /* Custom Count Stepper & Presets */ }
			{ mode === 'custom' && (
				<div className="smowp-batch-scope__custom-controls">
					<div className="smowp-custom-count-inline">
						<label
							className="smowp-custom-count-label"
							htmlFor="smowp-custom-count-input"
						>
							{ __( 'Batch Size:', 'omnioffloader' ) }
						</label>

						<div className="smowp-custom-input-group">
							<input
								id="smowp-custom-count-input"
								type="number"
								min={ 1 }
								max={ totalEligible }
								step={ 1 }
								value={ customCount === 0 ? '' : customCount }
								disabled={ disabled }
								className="smowp-custom-count-input"
								onChange={ ( e ) =>
									handleNumberChange( e.target.value )
								}
							/>
							<span className="smowp-custom-count-unit">
								{ sprintf(
									/* translators: %d: total eligible attachments */
									__( 'of %d available', 'omnioffloader' ),
									totalEligible
								) }
							</span>
						</div>

						{ presetsToShow.length > 0 && (
							<div className="smowp-custom-presets">
								<span className="smowp-custom-presets__label">
									{ __( 'Presets:', 'omnioffloader' ) }
								</span>
								<div className="smowp-custom-presets__chips">
									{ presetsToShow.map( ( preset ) => (
										<button
											key={ preset }
											type="button"
											disabled={ disabled }
											className={
												'smowp-preset-chip' +
												( customCount === preset
													? ' is-active'
													: '' )
											}
											onClick={ () =>
												onCustomCountChange( preset )
											}
										>
											{ preset }
										</button>
									) ) }
									<button
										type="button"
										disabled={ disabled }
										className={
											'smowp-preset-chip smowp-preset-chip--all' +
											( customCount === totalEligible
												? ' is-active'
												: '' )
										}
										onClick={ () =>
											onCustomCountChange( totalEligible )
										}
									>
										{ __( 'All', 'omnioffloader' ) }
									</button>
								</div>
							</div>
						) }
					</div>
				</div>
			) }
		</div>
	);
}
