---
name: watching-videos
description: How to "watch" a video the operator drops in — extract frames with Swift/AVFoundation; there is no ffmpeg and no audio
metadata:
  type: feedback
---

When the operator shares a video (local file or link), do not just refuse. Extract
frames and read them as images.

**Why:** He sends reference/tutorial videos expecting me to look at them. My first
answer to a YouTube link was a flat "I can't watch video", and he immediately
downloaded the file and re-asked — the refusal was the wrong shape.

**How to apply:**
- YouTube/remote URLs: genuinely not fetchable. Ask for the file, the transcript,
  or screenshots.
- Local file: `ffmpeg` is NOT installed on the Mac mini, but `/usr/bin/swift` is.
  A small AVFoundation script (`AVAssetImageGenerator`, `maximumSize` 1280,
  JPEG q0.6) extracts N evenly-spaced frames in seconds. Sample coarse (~16
  frames) first, then a finer pass only where the content is dense. A working
  copy lives at `/tmp/vidframes.swift` — cheap to rewrite if gone.
- `rm -rf` in a compound bash command gets denied by permissions; write to a new
  output dir instead of clearing one.
- **State clearly that there is no audio.** Frames only — the narration is lost,
  so never present a summary as if I heard it.
