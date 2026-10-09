import React from 'react';
import { AbsoluteFill, Audio, Sequence, staticFile, interpolate, useVideoConfig } from 'remotion';
import { TransitionSeries, linearTiming } from '@remotion/transitions';
import { fade } from '@remotion/transitions/fade';
import timing from './timing.json';
import { Captions } from './Captions';
import { FPS, LEAD, TAIL, TRANSITION } from './theme';
import { Hook, Intro, Providers, AutoOffload, Bulk } from './scenes1';
import { Retention, BringBack, Safety, Cli, Outro } from './scenes2';

const SCENES: Record<string, React.FC> = {
	hook: Hook,
	intro: Intro,
	providers: Providers,
	auto: AutoOffload,
	bulk: Bulk,
	retention: Retention,
	bringback: BringBack,
	safety: Safety,
	cli: Cli,
	outro: Outro,
};

/** Extra seconds held on the last scene after the narration. */
const OUTRO_HOLD = 2.6;

export const sceneFrames = timing.map( ( s, i ) => Math.round( ( LEAD + s.duration + TAIL + ( i === timing.length - 1 ? OUTRO_HOLD : 0 ) ) * FPS ) );
export const totalFrames = sceneFrames.reduce( ( a, b ) => a + b, 0 ) - TRANSITION * ( timing.length - 1 );

export const Video: React.FC = () => {
	const { durationInFrames } = useVideoConfig();
	return (
		<AbsoluteFill style={ { background: '#050d1f' } }>
			<TransitionSeries>
				{ timing.flatMap( ( s, i ) => {
					const Scene = SCENES[ s.id ];
					const items = [
						<TransitionSeries.Sequence key={ s.id } durationInFrames={ sceneFrames[ i ] }>
							<Scene />
							<Sequence from={ Math.round( LEAD * FPS ) }>
								<Audio src={ staticFile( `voice/${ s.id }.mp3` ) } />
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
				src={ staticFile( 'music.wav' ) }
				volume={ ( f ) =>
					interpolate( f, [ 0, 10, 60, durationInFrames - 150, durationInFrames - 70, durationInFrames - 1 ], [ 0, 0.42, 0.13, 0.13, 0.32, 0 ], {
						extrapolateLeft: 'clamp',
						extrapolateRight: 'clamp',
					} )
				}
			/>
		</AbsoluteFill>
	);
};
