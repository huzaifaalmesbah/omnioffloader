import { loadFont as loadInter } from '@remotion/google-fonts/Inter';
import { loadFont as loadMono } from '@remotion/google-fonts/JetBrainsMono';
import { interpolate, spring, useCurrentFrame, useVideoConfig, Easing } from 'remotion';

export const { fontFamily: SANS } = loadInter( 'normal', { weights: [ '400', '500', '600', '700', '800' ], subsets: [ 'latin' ] } );
export const { fontFamily: MONO } = loadMono( 'normal', { weights: [ '400', '600' ], subsets: [ 'latin' ] } );

export const C = {
	bg0: '#050d1f',
	bg1: '#0a1a3a',
	blue: '#1a5fd6',
	blueLight: '#3b86ee',
	blueDeep: '#0e3f9e',
	accent: '#135dbe',
	sky: '#7cb4ff',
	green: '#22c55e',
	greenSoft: '#4ade80',
	red: '#ef4444',
	amber: '#f59e0b',
	white: '#ffffff',
	text: '#e8f0ff',
	muted: '#93a8cc',
	card: 'rgba(255,255,255,0.06)',
	cardBorder: 'rgba(255,255,255,0.12)',
};

/** Seconds of silence before the narration starts in each scene. */
export const LEAD = 0.45;
/** Seconds held after the narration ends. */
export const TAIL = 0.75;
export const FPS = 30;
export const TRANSITION = 16;

/** Current time in seconds relative to the scene's narration start. */
export const useT = () => {
	const frame = useCurrentFrame();
	const { fps } = useVideoConfig();
	return frame / fps - LEAD;
};

/** Spring 0→1 starting at second `at` (narration time). */
export const useIn = ( at: number, damping = 18, mass = 0.9 ) => {
	const frame = useCurrentFrame();
	const { fps } = useVideoConfig();
	return spring( { frame: frame - Math.round( ( at + LEAD ) * fps ), fps, config: { damping, mass } } );
};

/** Eased 0→1 progress between two narration times. */
export const ramp = ( t: number, a: number, b: number, ease = Easing.inOut( Easing.cubic ) ) =>
	interpolate( t, [ a, b ], [ 0, 1 ], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: ease } );

export const rise = ( p: number, dist = 40 ) => ( {
	opacity: Math.min( 1, p * 1.4 ),
	transform: `translateY(${ ( 1 - p ) * dist }px)`,
} );
