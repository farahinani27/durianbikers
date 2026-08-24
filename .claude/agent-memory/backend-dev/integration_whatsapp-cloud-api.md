---
name: whatsapp-cloud-api-quirks
description: Meta WhatsApp Cloud API gotchas — 24h customer service window, error 131047, phone number ID vs number, token expiry
metadata:
  type: reference
---

Meta WhatsApp Cloud API, as used by `DB_Chat_WhatsApp_Notifier` in [[chatbot-order-notifications]].
Endpoint: `POST https://graph.facebook.com/v21.0/<phone_number_id>/messages`, `Authorization: Bearer <token>`.

Failure modes that bite, in the order they usually bite:

1. **24-hour customer service window.** Free-form `type: text` messages are only permitted within 24
   hours of the recipient last messaging the business number. Order alerts are business-initiated and
   will normally fall *outside* it, so Meta rejects them with **error 131047**. The fix is an approved
   message **template**, not a retry. Until a template is approved, treat WhatsApp as best-effort and
   keep Telegram or email as the reliable owner-alert channel.
2. **Phone number ID is not the phone number.** It is the numeric ID from WhatsApp > API Setup.
   Pasting the actual phone number produces a confusing 400.
3. **Recipient format** is E.164 *without* the leading `+` (Malaysia: `60123456789`).
4. **Temporary tokens expire after 24 hours.** The test token on the API Setup screen is temporary —
   production needs a permanent system-user token from Meta Business, otherwise notifications silently
   stop a day after setup.

Because sends are fire-and-forget (`blocking => false` on a live checkout), none of these errors are
visible at call time — they only appear in Meta's dashboard or by replaying the request manually.
Design the UI to explain the window up front rather than relying on error feedback.

Operator was mid-setup of WhatsApp Business API as of 2026-08-17; the channel is coded but stays
inert until token + phone number ID + recipient are all present.
