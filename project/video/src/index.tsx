import React from 'react';
import { Composition, registerRoot } from 'remotion';
import { Video, totalFrames } from './Video';
import { Walkthrough, wtTotal } from './wt/Walkthrough';
import { BnVideo, bnTotal } from './bn/BnVideo';
import { Thumbnail, ThumbA, ThumbB } from './bn/Thumbnail';
import { FPS } from './theme';

const Root: React.FC = () => (
	<>
		<Composition id="Overview" component={ Video } durationInFrames={ totalFrames } fps={ FPS } width={ 1920 } height={ 1080 } />
		<Composition id="Walkthrough" component={ Walkthrough } durationInFrames={ wtTotal } fps={ FPS } width={ 1920 } height={ 1080 } />
		<Composition id="BanglaR2" component={ BnVideo } durationInFrames={ bnTotal } fps={ FPS } width={ 1920 } height={ 1080 } />
		<Composition id="BanglaThumb" component={ Thumbnail } durationInFrames={ 1 } fps={ FPS } width={ 1280 } height={ 720 } />
		<Composition id="BanglaThumbA" component={ ThumbA } durationInFrames={ 1 } fps={ FPS } width={ 1280 } height={ 720 } />
		<Composition id="BanglaThumbB" component={ ThumbB } durationInFrames={ 1 } fps={ FPS } width={ 1280 } height={ 720 } />
	</>
);

registerRoot( Root );
