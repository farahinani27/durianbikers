---
name: shared-memory-dir
description: Several backend-dev sessions run in parallel and share this memory directory — merge MEMORY.md, never overwrite it
metadata:
  type: project
---

`.claude/agent-memory/backend-dev/` is shared by every concurrent backend-dev session, and the
project runs several in parallel (the chatbot build used two at once, plus frontend-dev and
database-dev in their own directories).

**Why it matters:** `MEMORY.md` is a single file. On 2026-08-17 two sessions each wrote it with
`Write` within ten minutes and each silently erased the other's index entries. The individual memory
files survived — only the index was lost, which is worse than it sounds, because an unindexed memory
file is effectively invisible in future conversations.

**The session SCRATCHPAD is shared too, and it bit on 2026-08-18.** A generic
`mutate.py` written there was silently overwritten by another agent's frontend
mutation runner mid-session; the first sign was my own script failing on a line
I never wrote. Prefix scratch tooling with what it belongs to
(`mutate-lookup-engine.py`, `lookup-harness.php`), and do not assume a file you
created is still yours. Same failure as the MEMORY.md collision, different
directory.

**How to apply:** always `cat MEMORY.md` and `ls` the directory immediately before editing the
index, and add your lines to what is already there rather than writing the file from scratch. Prefer
`Edit` over `Write` for `MEMORY.md`. Before creating a new memory file, check whether a parallel
session has already written one on the same topic — dedupe by folding your unique material into
theirs and linking, instead of keeping two overlapping files.
