# OmniOffloader videos

Three videos (1920×1080), built with [Remotion](https://www.remotion.dev/):

| Video | Length | Published |
| --- | --- | --- |
| Full walkthrough: WordPress.org page, install, every feature on the real admin screens, with the matching WP-CLI commands | 6:05 | https://www.youtube.com/watch?v=H68OvxS8DBM |
| Short animated overview | 2:19 | https://www.youtube.com/watch?v=xzcemowK79Q |
| Bangla tutorial: Cloudflare R2 + CDN in five steps (`BanglaR2`) | 5:03 | not yet |

The rendered MP4s are **not committed** (`*.mp4` is git-ignored — they are large and live on YouTube). Render them locally with `pnpm render` (→ `omnioffloader-overview.mp4`) and `pnpm render:wt` (→ `omnioffloader-walkthrough.mp4`) and `pnpm render:bn` (→ `omnioffloader-r2-bangla.mp4`).

Like everything in `project/`, this folder never reaches users: `.distignore` (WordPress.org deploy), `.gitattributes` `export-ignore` (`git archive`) and the allowlist in `scripts/bundle.js` all leave it out. Do **not** attach the video to a GitHub Release — publishing a release runs the WordPress.org deploy workflow.

## Layout

| Path | What it is |
| --- | --- |
| `script.json` | The narration, one entry per scene (`id` matches a scene in `src/Video.tsx`) |
| `src/timing.json` | Generated: clip length + sentence timings per scene, used for scene length and captions |
| `public/voice/*.mp3` | Generated narration clips (committed, so a re-render needs no network) |
| `public/music.wav` | Generated background music (git-ignored; `pnpm render` rebuilds it) |
| `public/shots/` | Admin screenshots used in the video (copies from `docs/public/images/screenshots/`) |
| `src/scenes1.tsx`, `src/scenes2.tsx` | The ten scenes; cue times are in seconds of narration |
| `src/components.tsx`, `src/theme.ts` | Shared visuals, brand colours, fonts and timing helpers |

## Walkthrough (`Walkthrough` composition)

| Path | What it is |
| --- | --- |
| `walkthrough.json` | The narration, one entry per scene |
| `scripts/voice-wt.py` | Voices it and writes `src/wt-timing.json` with **word-level timings** |
| `scripts/capture.js` | Captures the real screens from the local install into `public/wt/` and element positions into `src/wt-meta.json` |
| `src/wt/` | Scenes (`scenes.tsx`), the browser frame with camera/highlights/cursor (`Screen.tsx`), side panel (`Panel.tsx`) |

Every animation cue is a spoken word (`wordTime( scene, word )`), so editing the narration and re-running `pnpm voice:wt` keeps the visuals in sync; if a cue word is removed the build fails and names it.

`capture.js` needs the local site (`wp71.test`, logged in as user 1 through WP-CLI). It never starts a real job: progress, preflights and Test Connection are faked REST responses, Media Library states are edited in the page, and the real keys, endpoint, bucket and site address are replaced with example values before each screenshot. Check the PNGs in `public/wt/` for anything private before committing new captures.

```bash
pnpm capture:wt   # re-take the screens (optional)
pnpm voice:wt     # after editing walkthrough.json
pnpm render:wt    # → omnioffloader-walkthrough.mp4
```

## Bangla tutorial (`BanglaR2` composition)

Narrated by the owner's own voice (recorded in ElevenLabs), no captions, no music, with click / pop / whoosh sound effects.

| Path | What it is |
| --- | --- |
| `bn-script.json` | The Bangla narration, one entry per scene |
| `voice-bn/NN-id.mp3` | The recorded voice, one file per scene (committed; `pnpm prep:bn` copies them to the git-ignored `public/voice-bn/` before a render) |
| `scripts/whisper-bn.py` | Transcribes the clips locally (mlx-whisper, `.venv`) → `src/bn-whisper.json` |
| `scripts/sentences-bn.py` | Sentence start times from the audio's pauses + Whisper → `src/bn-timing.json` |
| `scripts/sfx.py` | Synthesises `public/sfx/{click,whoosh,pop}.wav` |
| `src/bn/` | Scenes, the simplified Cloudflare dashboard illustration, thumbnails (`BanglaThumb`, `BanglaThumbA`, `BanglaThumbB`) |

Animation cues are tied to spoken words with `W( scene, sentence, 'word' )` (the word's share of its sentence); a cue word missing from `bn-script.json` throws. Whisper's Bangla spelling is too rough for word-level matching, so timing within a sentence is an estimate — check in `pnpm studio` before rendering.

The Cloudflare screens are a drawn illustration, not screenshots (no real account data). Setup needs `python3 -m venv .venv && .venv/bin/pip install mlx-whisper` (Apple silicon).

```bash
pnpm voice:bn     # after replacing voice-bn/*.mp3 or editing bn-script.json
pnpm render:bn    # → omnioffloader-r2-bangla.mp4
pnpm thumbs:bn    # → thumbnail-a.png / -b.png / -c.png (YouTube A/B test)
```

## Workflow (overview)

Requires Python 3 with `edge-tts` (`pip install edge-tts`) for the voice. Rendering downloads Remotion's headless Chrome on first run; ffmpeg ships with Remotion.

```bash
cd project/video
pnpm install
pnpm studio    # live preview in the browser
pnpm render    # music + final MP4 → omnioffloader-overview.mp4
```

Changing the words: edit `script.json`, then `pnpm voice`. This regenerates the clips and `src/timing.json`; scene lengths and captions follow automatically. Animation cues inside a scene are hard-coded seconds, so re-check them against the new word timings (`python3 scripts/words.py` prints when each word is spoken).

The voice is Microsoft's `en-US-AndrewMultilingualNeural` through `edge-tts`, which sends the script text to Microsoft's online TTS service. The music is synthesised locally by `scripts/music.py` (original, no licence needed).

Never use a screenshot that shows real credentials — `provider-settings.png` is left out for that reason, and the providers scene uses a mock form.

Remotion is free for individuals and companies of up to three people; larger companies need a [company licence](https://www.remotion.dev/license).
