"""Print word start times (s) per scene, for syncing animation cues."""
import asyncio, json, edge_tts
async def main():
    for s in json.load(open('script.json')):
        c = edge_tts.Communicate(s['text'], 'en-US-AndrewMultilingualNeural', rate='+4%', boundary='WordBoundary')
        words = []
        async for ch in c.stream():
            if ch['type'] == 'WordBoundary':
                words.append(f"{ch['text']}@{ch['offset']/1e7:.1f}")
        print(s['id'] + ': ' + ' '.join(words) + '\n')
asyncio.run(main())
