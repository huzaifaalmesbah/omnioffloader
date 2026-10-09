"""Generate narration clips + sentence timings with edge-tts, then write src/timing.json."""
import json, subprocess, re
VOICE = 'en-US-AndrewMultilingualNeural'
out = []
def secs(t):
    h, m, s = t.replace(',', '.').split(':'); return int(h) * 3600 + int(m) * 60 + float(s)
for s in json.load(open('script.json')):
    mp3, srt = f"public/voice/{s['id']}.mp3", f"public/voice/{s['id']}.srt"
    subprocess.run(['edge-tts', '--voice', VOICE, '--rate=+4%', '--text', s['text'], '--write-media', mp3, '--write-subtitles', srt], check=True)
    dur = float(re.search(r'estimated duration: ([\d.]+)', subprocess.run(['afinfo', mp3], capture_output=True, text=True).stdout).group(1))
    cues = []
    for block in open(srt).read().strip().split('\n\n'):
        lines = block.strip().split('\n')
        a, b = lines[1].split(' --> ')
        cues.append({'start': secs(a), 'end': secs(b), 'text': ' '.join(lines[2:])})
    out.append({'id': s['id'], 'duration': dur, 'cues': cues})
json.dump(out, open('src/timing.json', 'w'), indent=1)
print(json.dumps([(o['id'], o['duration'], [(round(c['start'],1), c['text'][:40]) for c in o['cues']]) for o in out], indent=0))
