import React from 'react';
import { AbsoluteFill, Audio, Sequence, staticFile, interpolate, useVideoConfig } from 'remotion';
import { TransitionSeries, linearTiming } from '@remotion/transitions';
import { fade } from '@remotion/transitions/fade';
import { BN_T } from './kit';
import { BN_SCENES } from './scenes';
import { FPS, LEAD, TAIL, TRANSITION } from '../theme';

/** Extra seconds held on the last scene after the narration. */
const OUTRO_HOLD = 3.0;
/** Background music under the voice. */
const MUSIC = false;
/** Scenes that enter with a whoosh: only the big turns, not every cut. */
const WHOOSH_IN = [ '05-bucket', '10-result' ];

export const bnFrames = BN_T.map( ( s, i ) => Math.round( ( LEAD + s.duration + TAIL + ( i === BN_T.length - 1 ? OUTRO_HOLD : 0 ) ) * FPS ) );
export const bnTotal = bnFrames.reduce( ( a, b ) => a + b, 0 ) - TRANSITION * ( BN_T.length - 1 );

export const BnVideo: React.FC = () => {
	const { durationInFrames } = useVideoConfig();
	return (
		<AbsoluteFill style={ { background: '#050d1f' } }>
			<TransitionSeries>
				{ BN_T.flatMap( ( s, i ) => {
					const Scene = BN_SCENES[ s.id ];
					const items = [
						<TransitionSeries.Sequence key={ s.id } durationInFrames={ bnFrames[ i ] }>
							<Scene />
							{ WHOOSH_IN.includes( s.id ) ? (
								<Sequence from={ 0 } durationInFrames={ 30 } layout="none">
									<Audio src={ staticFile( 'sfx/whoosh.wav' ) } volume={ 0.3 } />
								</Sequence>
							) : null }
							<Sequence from={ Math.round( LEAD * FPS ) }>
								<Audio src={ staticFile( `voice-bn/${ s.id }.mp3` ) } />
							</Sequence>
						</TransitionSeries.Sequence>,
					];
					if ( i < BN_T.length - 1 ) {
						items.push( <TransitionSeries.Transition key={ `${ s.id }-t` } presentation={ fade() } timing={ linearTiming( { durationInFrames: TRANSITION } ) } /> );
					}
					return items;
				} ) }
			</TransitionSeries>
			{ /* Background music off; set MUSIC = true to bring it back. */ }
			{ MUSIC ? <Audio
				src={ staticFile( 'music-bn.wav' ) }
				volume={ ( f ) =>
					interpolate( f, [ 0, 10, 60, durationInFrames - 150, durationInFrames - 75, durationInFrames - 1 ], [ 0, 0.14, 0.03, 0.03, 0.1, 0 ], {
						extrapolateLeft: 'clamp',
						extrapolateRight: 'clamp',
					} )
				}
			/> : null }
		</AbsoluteFill>
	);
};
