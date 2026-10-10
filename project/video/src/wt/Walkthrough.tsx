import React from 'react';
import { AbsoluteFill, Audio, Sequence, staticFile, interpolate, useVideoConfig } from 'remotion';
import { TransitionSeries, linearTiming } from '@remotion/transitions';
import { fade } from '@remotion/transitions/fade';
import timing from '../wt-timing.json';
import { Captions } from '../Captions';
import { FPS, LEAD, TAIL, TRANSITION } from '../theme';
import * as S from './scenes';

const SCENES: Record<string, React.FC> = {
	intro: S.WtIntro,
	wporg: S.WtWporg,
	install: S.WtInstall,
	dashboard: S.WtDashboard,
	connect: S.WtConnect,
	cdn: S.WtCdn,
	serve: S.WtServe,
	retention: S.WtRetention,
	behavior: S.WtBehavior,
	auto: S.WtAuto,
	bulk: S.WtBulk,
	bulkcli: S.WtBulkCli,
	media: S.WtMedia,
	bringback: S.WtBringBack,
	tools: S.WtTools,
	safe: S.WtSafe,
	cliref: S.WtCliRef,
	outro: S.WtOutro,
};

/** Extra seconds held on the last scene after the narration. */
const OUTRO_HOLD = 3.2;

export const wtFrames = timing.map( ( s, i ) => Math.round( ( LEAD + s.duration + TAIL + ( i === timing.length - 1 ? OUTRO_HOLD : 0 ) ) * FPS ) );
export const wtTotal = wtFrames.reduce( ( a, b ) => a + b, 0 ) - TRANSITION * ( timing.length - 1 );

export const Walkthrough: React.FC = () => {
	const { durationInFrames } = useVideoConfig();
	return (
		<AbsoluteFill style={ { background: '#050d1f' } }>
			<TransitionSeries>
				{ timing.flatMap( ( s, i ) => {
					const Scene = SCENES[ s.id ];
					const items = [
						<TransitionSeries.Sequence key={ s.id } durationInFrames={ wtFrames[ i ] }>
							<Scene />
							<Sequence from={ Math.round( LEAD * FPS ) }>
								<Audio src={ staticFile( `voice-wt/${ s.id }.mp3` ) } />
							</Sequence>
							<Captions cues={ s.cues } />
						</TransitionSeries.Sequence>,
					];
					if ( i < timing.length - 1 ) {
						items.push( <TransitionSeries.Transition key={ `${ s.id }-t` } presentation={ fade() } timing={ linearTiming( { durationInFrames: TRANSITION } ) } /> );
					}
					return items;
				} ) }
			</TransitionSeries>
			<Audio
				src={ staticFile( 'music-wt.wav' ) }
				volume={ ( f ) =>
					interpolate( f, [ 0, 10, 60, durationInFrames - 160, durationInFrames - 80, durationInFrames - 1 ], [ 0, 0.38, 0.1, 0.1, 0.3, 0 ], {
						extrapolateLeft: 'clamp',
						extrapolateRight: 'clamp',
					} )
				}
			/>
		</AbsoluteFill>
	);
};
