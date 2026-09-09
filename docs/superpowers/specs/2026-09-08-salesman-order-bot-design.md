# Salesman / Dealer Order Bot — Design Spec

- **Date:** 2026-09-08
- **Status:** Draft for review
- **Owner:** Farah / Durian Bikers (Tuned By QBEE)
- **Related:** AutoCount integration, WhatsApp Cloud API, WooCommerce stock API

## 1. Purpose

Let dealers and salesmen place orders over WhatsApp in natural, messy form (text, part
number, photo, or voice note) and have those orders turned into **AutoCount Sales Orders**
and, after a human approval, **AutoCount Invoices** — without anyone re-typing them by hand.

This is "Track B" (order-taking). It is distinct from "Track A" (the existing respond.io
stock/price question bot). Track B connects **directly to the Meta WhatsApp Cloud API**
(no paid middleman platform), so it is independent of Track A's respond.io setup.

## 2. Users & scale

- **3 salesmen** — work with the dealers, place orders on their behalf.
- **Hundreds of dealers** — the customers; some will message the bot directly.
- **1 admin** — oversees, approves invoicing.
- Volume today is low; design for clarity and safety first, throughput later.

## 3. Confirmed order journey (agreed with operator)

1. A **dealer** (direct) or a **salesman** (on a dealer's behalf) sends an order via WhatsApp.
   Content may be **text, SKU/part no., photo, or voice note** — any mix.
2. Bot identifies the **dealer**:
   - Direct dealer → by **their phone number** → AutoCount debtor code.
   - Salesman-placed → by the **dealer name** the salesman gives.
   Bot asks the sender to **verify** ("You are ABC Trading — 300-A001 — correct?").
3. Bot understands the order (voice→text, photo→items), **matches products to AutoCount
   stock codes**, and shows a summary: "2× SHINKO F016 @ RM180 = RM360 — confirm?"
4. Sender confirms the order details.
5. Bot **creates the Sales Order in AutoCount** (an SO moves **no** stock or money),
   exports it as **PDF**, and sends the PDF back into the chat.
6. A **salesman or admin** reviews the PDF and **explicitly confirms** ("CONFIRM").
   Only a salesman/admin may do this — not a dealer.
7. That confirmation authorises the bot to **create the Invoice automatically** (the Invoice
   is the step that moves stock + money). Before it does, the bot shows the exact figures:
   "About to invoice: ABC Trading, 2× SHINKO F016, RM360 — reply CONFIRM."

### Safety model (non-negotiable)
- The bot never moves stock or money without an explicit human CONFIRM immediately prior.
- Reads are read-only. Writes go only through AutoCount's own document creation (SO/Invoice),
  never by writing raw database tables.
- Testing happens against the **ABC test company** in AutoCount and a **test WhatsApp number**,
  never the live Tuned By QBEE company or the real salesmen group.

## 4. Architecture — four building blocks

```
  1. EARS            2. BRAIN              3. HANDS
  WhatsApp    ──▶    Claude        ──▶    AutoCount bridge
  (unofficial        understands &        creates SO/Invoice,
   bridge, IN        matches, decides     exports PDF
   the group)  ◀──                  ◀──   (runs on the Windows PC)
                        │
                        ▼
                  4. MEMORY
                  Order log + dealer/alias store (dashboard optional)
```

- **Ears — WhatsApp (unofficial bridge, in the group).** A **normal WhatsApp account on a
  dedicated spare number**, automated via an unofficial bridge (the existing `whatsapp-mcp`
  companion-device setup is the basis). This account is a **member of the salesmen's group**,
  reads group messages (text/voice/photo), and replies in the group; it also handles **1:1 dealer
  chats** on the same number. Runs on the **VPS**. **Trade-off (accepted by operator):** this
  breaks WhatsApp's ToS and the number can be banned; blast radius is limited to the spare number
  (recoverable ~30 min re-setup) because it is internal, low-volume, and not the main business line.
- **Brain — Claude.** Reads each message (transcribes voice, reads images), identifies the
  dealer, matches products, decides what to confirm, drafts replies. Runs on the **VPS**.
- **Hands — AutoCount bridge.** The only part that writes into AutoCount. Must run on the
  **Windows machine** next to AutoCount (the office server `DESKTOP-29M5DOL`). Mechanism TBD
  by the AutoCount API quote (paid plugin/API) with a free fallback of AutoCount Excel import.
- **Memory — order log + store.** A small database of every order and its status, plus learned
  dealer nicknames. Because the bot is **in the group**, the group itself provides shared
  visibility, so a web dashboard is **optional** (nice-to-have for searchable history). Runs on the VPS.

The Brain (VPS) and Hands (Windows) are on different machines and talk over the network — the
one genuinely fiddly seam, and exactly what the AutoCount API quote resolves.

## 5. WhatsApp channel decision

**Unofficial bridge — the bot is a real WhatsApp account IN the salesmen's group.** Chosen by
the operator (2026-09-08) after weighing the alternatives: the group workflow is what the salesmen
actually use, and being *inside* the group matters more to them than avoiding all ban risk.

- **Why not official:** an official Meta Cloud API number is a business endpoint, not a normal
  account — it **cannot join or be in a WhatsApp group at all** (a hard technical limit, not a
  rule). "Official + in the group" is impossible; only a normal account can be in a group, and
  automating a normal account is the unofficial method.
- **Risk, consciously accepted:** automating a normal WhatsApp account **violates WhatsApp's ToS**
  (a private policy, not a law) and the number **can be banned**. Mitigations that make this
  tolerable for THIS internal use:
  - Use a **dedicated spare number**, never the main business line → a ban costs only that number.
  - It's a **private internal group** of 3 known salesmen + dealers, **low volume**, not mass
    messaging strangers → far lower ban likelihood.
  - If banned: get another spare number, re-add to the group (~30 min). Group + salesmen unaffected.
- **Basis:** the existing `whatsapp-mcp` unofficial companion-device bridge (already working) is
  the starting point. One normal account serves **both** the salesmen group and 1:1 dealer chats.
- **Contrast (recorded for later):** the **customer bot** (Track A / public) must stay **official**
  on the main number — a ban there WOULD be a disaster. Unofficial-in-group is only acceptable for
  this internal salesman tool.

**Cost.** No WhatsApp platform fee (unofficial bridge is self-hosted on the VPS). The only
per-use cost is the Claude API ("brain") — tiny, pay-as-you-go. AutoCount API is separate (quote).

## 6. Phased delivery

| Phase | Scope | Depends on | Start |
|------|-------|-----------|-------|
| **1** | Ears + Brain: bot in a **test group** receives WhatsApp (text/voice/photo), understands the order, matches dealer + products, drafts "confirm?" — using **exported CSV** data | Spare number + `whatsapp-mcp` bridge + a test group | **Now** |
| **2** | Live lookups (real-time dealer/stock from AutoCount) | Read-only SQL login | Login delivered |
| **3** | Hands: create SO → PDF → confirm → Invoice | AutoCount API quote + setup | Quote approved |
| **4** | Nickname learning + (optional dashboard) + go-live in the real group | Phases 1–3 | Last |

## 7. Phase 1 — detail (what we build & test now)

**Goal:** prove the riskiest, most uncertain part cheaply — that Claude reliably understands a
messy Malay/English voice note (and photos, and text) and picks the **right dealer** and the
**right AutoCount stock items** — before any AutoCount writing exists.

**In scope (Phase 1):**
- The bot (spare number, `whatsapp-mcp` bridge) joins a **test group** (you + spare number + maybe
  one helper) — never the real salesmen group yet.
- Receive and transcribe voice notes; read order photos; parse text/SKU.
- Dealer matching against an **exported debtor CSV** (name + phone + agent + contact).
- Product matching against an **exported stock CSV** (code + description + price).
- Draft the confirmation summary back to the sender.
- **Stops before** creating anything in AutoCount.

**Out of scope (Phase 1):** any AutoCount write, the invoice step, the dashboard, live DB reads,
production number, the real group.

**Test data needed from AutoCount (via the proven export→Drive pipeline):**
- Full **debtor list** (all dealers, incl. zero-balance) with phone / agent / contact columns.
- **Stock list** (item code, description, price, balance).

**Success criteria for Phase 1:**
- Given a set of sample messages (text, photo, voice) for known ABC-company dealers, the bot
  identifies the correct debtor and the correct stock items, and asks for verification, with
  an accuracy we both judge good enough to proceed — and never silently guesses.

## 8. Dependencies & open items

- **AutoCount write API** — quote pending from the reseller. Decides how the Hands work.
- **Read-only SQL login** — pending from the reseller/engineer; enables Phase 2 live reads.
  (Confirmed possible "without restriction". Server encrypts with a self-signed cert; Windows
  Auth fails on the workgroup, so SQL Server Authentication is used.)
- **WhatsApp bridge** — the unofficial `whatsapp-mcp` bridge on a **dedicated spare number**,
  running on the VPS (or the Mac for testing). Ban risk accepted (see §5); use the spare number,
  never the main line. Have a spare-number re-setup runbook ready in case of a ban.
- **Hosting** — VPS (Exabytes NVMe C4) for Ears/Brain/Memory; Windows office PC for Hands.
  (No public webhook needed — the bridge connects out to WhatsApp itself.)
- **Dealer phone data quality** — "detect by phone" only works if dealer phone numbers are
  actually stored on AutoCount debtor records. Verify during Phase 2.
- **Product/SKU discipline** — per project rule, never infer or invent SKUs; ambiguous product
  matches must always be confirmed by a human.

## 9. Non-goals

- No writing to raw AutoCount database tables — ever.
- No auto-invoicing without a human CONFIRM.
- The bot's number is a **dedicated spare**, never the main business line (ban-blast-radius control).
- The **customer/public bot** (later) must NOT use this unofficial method — official only there.
- No testing in the real salesmen group until Phase 1 passes in a test group.
