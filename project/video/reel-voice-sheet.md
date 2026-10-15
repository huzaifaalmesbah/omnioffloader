# Reel voice-over — "Optimize করেছেন, তাও slow?"

Either record **one ElevenLabs clip per block** below and save it as `voice-reel/<file>` (exact name), **or record everything as one file** `voice-reel/full.mp3` — paste the blocks in order with a scene break between them (Multilingual v2: `<break time="1.2s" />`; v3: a blank line plus `...`). Claude splits the full file at those breaks with Whisper + silence detection and inserts an even gap before each scene. The text is the source of truth for timing — if you change a word while recording, tell me (or edit `reel-script.json`).

**ElevenLabs settings**

- Same voice and same settings for all 9 clips (as for the Bangla R2 video).
- Model: Eleven Multilingual v2 (or v3). Stability ~45–55, Similarity ~75, Style low (0–15), Speaker boost on.
- Pace: conversational, slightly brisk. Each `।` / `?` must get a small natural pause (≥ 0.2 s) — sentence timing is found from those pauses.
- Questions (`?`) should sound like real questions to the viewer.
- Export MP3 44.1 kHz, 128 kbps or higher. No music, no effects.

If a word comes out wrong (e.g. "R2", "OmniOffloader"), regenerate that clip rather than fixing it in an editor.

## `01-hook.mp3`

```
আপনার WordPress সাইট optimize করেছেন। Cache plugin দিয়েছেন, ছবি compress করেছেন। তাও সাইট খুলতে সময় লাগে?
```

## `02-question.mp3`

```
তাহলে একটা প্রশ্ন করি। আপনার সাইটের ছবিগুলো আসে কোথা থেকে, বলেন তো? বেশিরভাগ সাইটে, একটা ছোট hosting server থেকে। Cache plugin পেজটা দ্রুত বানায়, এটা সত্যি। কিন্তু প্রতিটা ছবি তারপরও ওই একই server থেকে যায়। আর একটা পেজে ছবিই সাধারণত সবচেয়ে ভারী অংশ।
```

## `03-analogy.mp3`

```
একটা উদাহরণ দিই। ধরুন আপনার দোকান শুধু ঢাকায়। চট্টগ্রাম, সিলেট, রাজশাহী, সবাইকে ঢাকায় এসে কিনতে হয়। ভিড় বাড়ে, লাইন লম্বা হয়। এখন যদি প্রতিটা শহরে একটা branch থাকে? CDN ঠিক এটাই করে। আপনার ছবি visitor এর কাছের server থেকে যায়।
```

## `04-cloud.mp3`

```
আর ছবি রাখা থাকে cloud storage এ, যেমন Cloudflare R2। R2 তে free tier আছে, আর download এর জন্য আলাদা টাকা নেয় না।
```

## `05-pain.mp3`

```
কিন্তু তারপর? হাজার হাজার পুরনো ছবি cloud এ নেবে কে? প্রতিটা post এ ছবির link বদলাবে কে? নতুন ছবি upload করলেই কে পাঠাবে?
```

## `06-product.mp3`

```
এই ঝামেলা থেকেই আমি OmniOffloader বানিয়েছি। একটা free WordPress plugin। আপনার bucket connect করবেন, একবার Bulk Offload চাপবেন। পুরনো সব ছবি cloud এ যাবে, নতুনগুলো upload হলেই যাবে। সাইটে ছবি আসবে cloud থেকে, link নিজে থেকেই বদলায়।
```

## `07-control.mp3`

```
চাইলে server থেকে local copy মুছে জায়গাও খালি করতে পারবেন। মত বদলালে? Bring Back দিয়ে সব ছবি আবার server এ ফেরত।
```

## `08-honest.mp3`

```
তবে একটা কথা সত্যি বলি। Theme বা plugin নিজেই ভারী হলে, সেটা আলাদা সমস্যা। কিন্তু slow হওয়ার কারণ যদি ছবি হয়, এটা খুব সহজ fix। মূল কথা হলো, শুধু optimize না, ছবি কোথা থেকে যাচ্ছে সেটাও দেখুন।
```

## `09-cta.mp3`

```
আপনার সাইটের ছবি এখন কোথা থেকে আসে? কমেন্টে জানান। Plugin এর link কমেন্টে দিলাম। WordPress নিয়ে এমন practical ভিডিও পেতে Follow করুন।
```
