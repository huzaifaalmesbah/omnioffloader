import React from 'react';
import { Composition, registerRoot } from 'remotion';
import { Video, totalFrames } from './Video';
import { FPS } from './theme';

const Root: React.FC = () => (
	<Composition id="Overview" component={ Video } durationInFrames={ totalFrames } fps={ FPS } width={ 1920 } height={ 1080 } />
);

registerRoot( Root );
