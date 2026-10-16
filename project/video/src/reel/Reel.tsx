import React from 'react';
import { AbsoluteFill, Audio, Sequence, staticFile } from 'remotion';
import { FPS, LEAD, useT } from './timing';
import { Background, Headline, Sfx, FONT, VZ_TOP, VZ_H, VZ_SCALE } from './kit';
import { SCENES, CUES } from './scenes';

/** 1080×1920 Facebook reel: "Optimize করেছেন, তাও slow?" */
export const Reel: React.FC = () => {
	const t = useT();
	return (
		<AbsoluteFill style={ { fontFamily: FONT } }>
			<Background t={ t } />
			<Headline t={ t } />
			<div style={ { position: 'absolute', left: 0, top: VZ_TOP, width: 1080, height: VZ_H, transform: `scale(${ VZ_SCALE })`, transformOrigin: '50% 0' } }>
				{ SCENES.map( ( Scene, i ) => <Scene key={ i } t={ t } /> ) }
			</div>
			<Sequence from={ Math.round( LEAD * FPS ) } layout="none">
				<Audio src={ staticFile( 'voice-reel/voice.wav' ) } />
			</Sequence>
			{ CUES.map( ( c, i ) => <Sfx key={ i } { ...c } /> ) }
		</AbsoluteFill>
	);
};
