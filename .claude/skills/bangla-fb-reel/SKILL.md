---
name: bangla-fb-reel
description: Plan, script and animate short Bangla (Banglish) Facebook Reels in the animated-explainer style — voice-over, light 9:16 motion graphics, a short headline per spoken phrase, flat characters and UI cards (Remotion in project/video). Script shape: hook question, everyday analogy, personal product proof, pain-question list, takeaway, comment CTA. Use when the user asks for a Facebook/Instagram reel, short video script, or video ideas for OmniOffloader (or any product) in Bangla.
---

# Bangla Facebook Reel script

Style reference: Md Mahedi Hasan's business reels (PixelFly founder) — vertical 9:16 (720×1280), **fully animated motion graphics with the creator's voice-over** (no face on camera), 2:20–2:35 long, and the **full script is pasted as the post caption**. The video works because it sounds like a person thinking out loud, not an ad. See "Visual style" and "Production" below for the animation.

## Structure (in this order)

1. **Hook (line 1–2, ≤ 5 seconds).** A situation the viewer recognises, then a turn.
   - Contrarian: "সবাই X নিয়ে excited। কিন্তু একটু থামুন।"
   - Gap question: "সফল মানুষের গল্প তো অনেক শুনেছেন। কিন্তু … শেষ কবে শুনেছেন?"
   - Pain + promise: "আপনার WordPress সাইট optimize করেছেন, তাও slow?"
2. **Challenge the obvious belief** — one line: "আমার তা মনে হয় না।" / "সমস্যাটা আসলে অন্য জায়গায়।"
3. **Ask the viewer directly** — "তাহলে একটা প্রশ্ন করি। … বলেন তো?" (keeps them watching for the answer).
4. **The answer in 2–3 short lines.**
5. **Everyday Bangladeshi analogy** — "একটা উদাহরণ দিই।" (biryani recipe free but we still buy biryani; গুদাম vs দোকান; বাসার ফ্রিজ vs কোল্ড স্টোরেজ). One analogy only.
6. **Personal proof, soft product mention** — "আমি নিজে [product] বানাতে গিয়েও ঠিক এটাই দেখেছি।" Product appears ~60% in, as the speaker's own experience, never as the opening.
7. **Rapid pain questions** — 3 short "কে দেখবে? / কী হবে?" lines that stack the problem.
8. **How the product removes it** — 2–3 lines, concrete, no jargon dump.
9. **Takeaway** — "মূল কথা হলো, …" one or two lines.
10. **CTA** — an opinion question for comments ("আপনার কী মনে হয়? কমেন্টে জানান, আমি সত্যিই শুনতে চাই।"), then follow/share line.

## Language rules

- One sentence per line, mostly 4–10 words. Each line = one breath / one on-screen caption.
- Conversational Bangla (বলেন তো, ঠিক আছে, সত্যি বলতে). Tech words stay in English Latin script: WordPress, CDN, server, hosting, backup, Cloudflare R2.
- Talk to "আপনি". No hype words (সেরা, অবিশ্বাস্য, ১০০% গ্যারান্টি), no fake numbers. Every claim must be true for the product.
- Total 150–230 words (≈ 60–90 s spoken) for a tight reel; the reference reels run ~350 words / 2:30 — fine when every line gets its own visual beat.

## Output format

1. **Title / on-screen hook text** (≤ 8 words, for the first frame and thumbnail).
2. **Beat table** — one row per spoken line: spoken text · on-screen headline (coloured word marked) · visual/animation (from the vocabulary below; real plugin screenshots in a BrowserFrame where they help).
3. **Caption** — the same script, plus 3–5 hashtags at the end.
4. Approximate duration.

## OmniOffloader facts to stay truthful

- Moves Media Library files to cloud storage (Cloudflare R2, Amazon S3, Backblaze B2, DigitalOcean Spaces, Wasabi, any S3-compatible) and serves them from the bucket/CDN URL. Free plugin on WordPress.org.
- It speeds up **media delivery** (images/videos from a CDN, less load and bandwidth on the host). It does not fix slow PHP, heavy themes or database — never say it makes "everything" fast.
- Retention policies: keep local / delete thumbnails / delete all local copies → frees hosting disk space.
- **Bring Back**: brings media back from the cloud to the server — no lock-in.
- Safe mode: a staging/local copy of the site never writes to or deletes from the bucket.
- Bulk offload from the admin or WP-CLI (`wp omnioffloader offload`).
- Cloudflare R2 has a free tier (check current limits before quoting numbers) and no egress fees.

## Visual style

The reference reels set the *format* (light motion graphics, one headline per sentence, cards, a character). The **colours are always OmniOffloader's brand**, the same as the plugin admin and every other video (`project/video/src/theme.ts`, `src/reel/kit.tsx` `K`):

- **Canvas:** 1080×1920. Background `#f4f7fc` with two soft drifting blue blobs (`#3b86ee` top-left, `#7cb4ff` bottom-right). One **mood switch** to the brand navy (`#123a86 → #071531 → #040a1a`) for the worst-pain beat, then back to light.
- **Headline** (`HL_TOP` 370): 1–2 short bold Bangla lines, centred, ink `#0f1f3d`, **one key word coloured** — brand blue `#1a5fd6` for the idea, red `#dc3b2f` for a problem, green `#16a34a` for a fix, the logo gradient (`#3b86ee → #0e3f9e`) for the brand. A 3–7 word paraphrase of the spoken phrase, not a subtitle; it changes every sentence.
- **Visual zone** (`VZ_TOP` 590, scenes laid out in 1080×830 and scaled ×1.1): white rounded cards, pastel-blue icon tiles, OK/✗ pills, the real plugin screens in a browser frame. Never orange or purple accents.
- **Layout is centred:** headline + visual zone sit in the middle of the frame. Fill each beat down to the character — if a scene has only a top card, add the character below it or shift the scene with `<Layer dy>`. Keep only ~350 px at the bottom for Facebook's caption.
- **Character:** DiceBear Avataaars bust (`@dicebear/avataaars`, design by Pablo Stanley, free for commercial use) in a brand-blue T-shirt, name tag "আপনি". Moods: happy / worried (closed frown + sweat drop) / neutral / thinking — swap with the story, with a small bounce.
- **Ending:** a profile card (logo, Follow + Share clicked by the cursor) with the OmniOffloader logo lockup under it.

## Animation vocabulary (each a reusable Remotion component)

| Motion | Used for |
| --- | --- |
| Headline swap: old line fades/slides up, new one springs in; coloured word lands last | Every sentence |
| Card / tile stagger pop-in (spring scale 0.9→1, 60–80 ms apart) | Lists, grids |
| Number counter (0 → 9.8k, 0 → 10) | Stats, scores, sizes |
| Dimming: previous visual goes grey/transparent while a new element (big icon in a circle) overlays it | "কিন্তু একটু থামুন" turns |
| Badge flip: green "OK" → red, line turns red, counter "Events: 0" | Something breaks |
| Dotted connector with dots travelling along it | Data/files moving |
| Strike-through old plan → new plan card | Before/after |
| Scale tipping, coins flying, cursor click, typing caret | Metaphors and CTA |
| Character expression change + small bounce | Emotion beats |

Keep motion gentle (springs, no hard cuts); there is always something moving, but only one new thing per beat.

## Production (project/video, Remotion)

1. **Script** with this skill → `reel-<slug>.json`: one entry per beat `{ id, text (spoken), headline, accent (the coloured word), visual }`.
2. **Voice:** the owner records the whole script in ElevenLabs as one take → `voice-reel/main.mp3` (a pause after every `।`/`?` matters more than speed).
3. **Timing:** one full take is fine — `pnpm voice:reel` (`scripts/reel-voice.py`) trims pauses to 0.45 s, runs Whisper in chunks and writes `src/reel-timing.json`; cue animations with `W( scene, sentence, 'word' )` from `src/reel/timing.ts`.
4. **Scenes:** reuse `src/reel/kit.tsx` (Background, Headline, Card, Tile, Pill, BigCircle, Connector, Flow, Cursor, BrowserFrame, Character, Bubble); one component per scene in `scenes.tsx`. Fonts from `src/theme.ts` (Inter + Hind Siliguri).
5. **Audio:** voice + quiet music bed + `public/sfx` click/pop/whoosh on pop-ins.
6. **Render:** `remotion render Reel… reel-<slug>.mp4` (h264, crf 18). Check every frame's bottom 35% is clear. Post with the full script as caption + 3–5 hashtags.
