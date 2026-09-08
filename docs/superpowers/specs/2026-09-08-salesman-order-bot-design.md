# Salesman / Dealer Order Bot — Design Spec

- **Date:** 2026-09-08
- **Status:** Draft for review
- **Owner:** Farah / Durian Bikers (Tuned By QBEE)
- **Related:** AutoCount integration, respond.io bridge, WooCommerce stock API

## 1. Purpose

Let dealers and salesmen place orders over WhatsApp in natural, messy form (text, part
number, photo, or voice note) and have those orders turned into **AutoCount Sales Orders**
and, after a human approval, **AutoCount Invoices** — without anyone re-typing them by hand.

This is "Track B" (order-taking). It is distinct from "Track A" (the existing respond.io
stock/price question bot), though both share the same WhatsApp channel.

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
  (official,         understands &        creates SO/Invoice,
   via respond.io)   matches, decides     exports PDF
             ◀──                    ◀──   (runs on the Windows PC)
                        │
                        ▼
                  4. MEMORY
                  Orders dashboard + dealer/alias store
```

- **Ears — WhatsApp (official).** Receives text/voice/photo/PDF, sends replies + the SO PDF,
  supports tappable [✅ CONFIRM]/[❌ CANCEL] buttons. Delivered through **respond.io** (a
  Meta-approved WhatsApp BSP the business already uses), so there is **no ban risk**. Runs
  effectively on the VPS side via respond.io's Developer API v2.
- **Brain — Claude.** Reads each message (transcribes voice, reads images), identifies the
  dealer, matches products, decides what to confirm, drafts replies. Runs on the **VPS**.
- **Hands — AutoCount bridge.** The only part that writes into AutoCount. Must run on the
  **Windows machine** next to AutoCount (the office server `DESKTOP-29M5DOL`). Mechanism TBD
  by the AutoCount API quote (paid plugin/API) with a free fallback of AutoCount Excel import.
- **Memory — dashboard + store.** A small database of every order and its status, plus learned
  dealer nicknames; a **web dashboard** (hosted on the VPS, opened by link / Add-to-Home-Screen)
  gives the 3 salesmen + admin the shared visibility the group used to provide. Runs on the VPS.

The Brain (VPS) and Hands (Windows) are on different machines and talk over the network — the
one genuinely fiddly seam, and exactly what the AutoCount API quote resolves.

## 5. WhatsApp channel decision

**Official Meta route, via respond.io.** Rationale:
- Business-critical and long-term → the unofficial linked-device method's permanent, no-appeal
  ban risk is unacceptable.
- Trade-off accepted: official WhatsApp is **1:1 only — it cannot operate inside a group**.
  (WhatsApp "Channels" are broadcast-only and unusable for orders.)
- Group functions are replaced:
  - Placing orders → direct 1:1 chats with the bot.
  - Shared visibility → the **web dashboard**.
  - The salesmen's existing human group stays as-is for chatter; the bot is not in it.
- The group's **chat history** is still usable: do a one-time WhatsApp "Export Chat" and feed
  it to the bot once to seed dealer names / nicknames / past-order patterns.

Open question for review: confirm respond.io is the intended gateway for Track B too (it already
carries Track A), vs. wiring raw Meta Cloud API directly.

## 6. Phased delivery

| Phase | Scope | Depends on | Start |
|------|-------|-----------|-------|
| **1** | Ears + Brain: receive WhatsApp (text/voice/photo), understand the order, match dealer + products, draft "confirm?" — using **exported CSV** data | Spare number + WhatsApp test setup | **Now** |
| **2** | Live lookups (real-time dealer/stock from AutoCount) | Read-only SQL login | Login delivered |
| **3** | Hands: create SO → PDF → confirm → Invoice | AutoCount API quote + setup | Quote approved |
| **4** | Dashboard + nickname learning + go-live on the official number | Phases 1–3 | Last |

## 7. Phase 1 — detail (what we build & test now)

**Goal:** prove the riskiest, most uncertain part cheaply — that Claude reliably understands a
messy Malay/English voice note (and photos, and text) and picks the **right dealer** and the
**right AutoCount stock items** — before any AutoCount writing exists.

**In scope (Phase 1):**
- A test WhatsApp connection (spare number; respond.io test setup or Meta sandbox).
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
- **respond.io plan limits** — Track A works around the Growth plan's lack of outbound HTTP by
  inverting the call (cron pulls). Track B's needs (send/receive media, buttons, PDFs) must be
  checked against the current respond.io plan.
- **Hosting** — VPS (Exabytes NVMe C4) for Ears/Brain/Memory; Windows office PC for Hands.
- **Dealer phone data quality** — "detect by phone" only works if dealer phone numbers are
  actually stored on AutoCount debtor records. Verify during Phase 2.
- **Product/SKU discipline** — per project rule, never infer or invent SKUs; ambiguous product
  matches must always be confirmed by a human.

## 9. Non-goals

- No writing to raw AutoCount database tables — ever.
- No auto-invoicing without a human CONFIRM.
- No operating inside the real salesmen WhatsApp group (official API can't, and we won't risk it).
- Not replacing the salesmen's human coordination group.
