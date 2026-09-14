/*
Durian Bikers Chatbot — frontend widget
Text domain: durianbikers-chatbot

Vanilla JS. No jQuery, no build step, no third-party requests, no storage.
Wrapped in an IIFE like motormania-child/assets/js/custom.js; enqueue in the
footer with an empty deps array. The widget builds its own DOM, so nothing
needs to be printed in wp_footer.

SHADOW DOM
The widget mounts inside a shadow root. MotorMania, Elementor, Essential
Addons and ShopEngine all spray global CSS; the shadow boundary is what
keeps a stray `div {}` rule from wrecking the panel. chatbot.css is linked
into the shadow root, reusing the copy the browser already cached from
wp_enqueue_style. The CSS URL is resolved from `dbChat.cssUrl`, else from
this script's own src, else from the enqueued <link> in the page — so no
backend change is required for it to work.

Expects wp_localize_script( handle, 'dbChat', [...] ) with:
    ajaxUrl        REST route URL for POST /wp-json/durianbikers/v1/chat
    greeting       opening assistant message
    suggestions    array of plain strings (up to 4 rendered) — the starter
                   chips; see FOLLOW-UP CHIPS below for the mid-conversation
                   ones, which arrive on the response instead
Optional: sessionUrl, cssUrl, title, subtitle, botName, placeholder,
launcherLabel, footnote, privacyUrl, privacyLabel, avatar, offlineText,
errorText, rateLimitText, networkText, searchingText, closeLabel, sendLabel,
handoff.

`privacyUrl` should be passed as get_privacy_policy_url(); the disclaimer
link is simply omitted when it is empty, rather than guessing a slug.

HUMAN HANDOFF (WhatsApp)
Optional `dbChat.handoff`:
    url         wa.me link, already built server-side, WITHOUT any prefill
    label       button label
    hours       staffed hours, shown as a caption
    isOpen      boolean — see below, may be absent
    openNote    caption shown when open
    closedNote  caption shown when closed
    opener      neutral prefill used when there is no customer message
If `handoff` is missing or `handoff.url` is empty, nothing is rendered at
all — no row, no border, no empty space. The owner may not have configured
a number.

`isOpen` is deliberately treated as a THREE-state value. The site runs a
7-day LiteSpeed page cache, so an open/closed flag baked into the localized
(cached) HTML would be wrong most of the time. The backend therefore serves
it from the uncached session route instead:

    GET /wp-json/durianbikers/v1/session
    -> { "nonce": "…", "handoff": { "isOpen": true } }

Only `isOpen` travels that way; everything static stays in `dbChat.handoff`,
where caching is harmless. Resolution order here:
    1. the session response — `handoff.isOpen`, or a flat `isOpen` on the
       response root,
    2. `dbChat.handoff.isOpen`,
    3. neither: render the button with NO open/closed claim rather than
       guess. Hours still show; the note does not.
Branch 3 is the one that matters: if the session fetch fails, claiming
"we're open" would be the worst available failure. Merging is per-key and
only for keys that actually arrive, so a session payload carrying just
`isOpen` cannot blank out `url` or `label` from the config. Malformed hours
on the backend produce `isOpen: false` — it fails closed, so the closed note
must read as "leave a message", never as "you'll get an instant reply".

The session is fetched once, on first panel open. Someone who opens the chat
at 5:58pm and is still reading at 6:05pm sees a stale note. That is accepted:
re-polling a footer caption is not worth the complexity, and the copy is
written so a stale note is still honest.

PREFILL IS A PRIVACY BOUNDARY
The `?text=` parameter is built at click time and contains ONLY the
customer's own last typed message, trimmed to 200 characters and
URL-encoded. Never an assistant reply, never product data, never anything
else from the transcript. If the conversation has touched orders at all
(order numbers, tracking, refunds — see ORDER_HINT), the prefill is skipped
entirely in favour of a neutral opener: a wa.me URL is visible in browser
history, in the OS share sheet and to anyone looking at the screen, and
order data has no business being there.

NONCES AND FULL-PAGE CACHE
There is deliberately NO `dbChat.nonce`. The site runs LiteSpeed full-page
caching with a 7-day TTL while WP nonces last ~12-24h, so a nonce baked
into cached HTML is dead for most visitors and reloading just re-serves the
same stale page. Instead the widget GETs a fresh nonce from
`GET /wp-json/durianbikers/v1/session` the first time the panel is opened,
keeps it in memory for the page lifetime, and silently re-fetches + retries
once if a chat POST comes back 409 `db_chat_stale_nonce` (or 403
`rest_cookie_invalid_nonce`). Customers never see a "session expired"
message — that is ours to fix, not theirs to read.

RATE-LIMIT CLIENT TOKEN
Each chat POST carries a random per-tab id under the header named by
`dbChat.tokenHeader` (fallback `X-DB-Chat-Session`), so the backend can
rate-limit on hash(IP + token). The site may sit behind a proxy where every
visitor shares an IP; without the token one visitor's burst would lock out
the whole store. The id must match ^[A-Za-z0-9_-]{8,64}$ — anything else is
regenerated rather than sent, because a malformed token collapses into a
shared bucket.

It is an abuse-control input and nothing else: never an analytics id, never
sent anywhere but the chat POST, and sessionStorage rather than
localStorage on purpose so it dies with the tab instead of becoming a
tracking surface. Private mode is fine — it silently falls back to an
in-memory value. This is the only browser storage the widget uses; the
transcript itself is never stored.

FOLLOW-UP CHIPS (guided product finder)
The free lookup-mode finder walks a customer who cannot phrase a question
through a few taps ("help me find something" -> category -> brand -> budget).
It does that with one optional extra key on the chat response:

    { "reply": "...", "products": [], "pages": [],
      "chips": ["Helmets", "Tyres", "Brakes", "Accessories"] }

`chips` is a FLAT ARRAY OF PLAIN STRINGS, not {label, value} objects. Tapping
a chip sends its label as the customer's next message, so the label is the
value; a hidden value would make the transcript show one thing while another
was sent. It also means typing "Helmets" and tapping the "Helmets" chip are
the same request — the finder parses the message either way, and the widget
does no flow tracking of its own.

Up to 6 are rendered (starter chips stay at 4). The backend's step lists can
be longer, so every step is required to include an escape option ("Any" /
"Something else") within the first six — otherwise a truncated list becomes
a dead end.

Absent, empty, or anything that is not an array of plain strings renders
nothing and changes nothing, so a backend that never sends the key behaves
exactly as before. The row is torn down at the start of every new turn:
stale options that no longer apply are worse than none.

SECURITY: every string that originates from the model or the server is
written with textContent, and URLs are scheme-checked before setAttribute.
Nothing here ever assigns innerHTML from response data. Product cards are
built ONLY from the `products` array — the reply text is never parsed for
products.
*/

(function () {
	'use strict';

	// Captured synchronously: document.currentScript is only valid during
	// this script's own top-level execution.
	var THIS_SCRIPT = document.currentScript;

	// --- CONFIG --- //

	var cfg = window.dbChat || {};

	var TEXT = {
		title: cfg.title || 'Durian Bikers Assistant',
		subtitle: cfg.subtitle || 'Parts & gear help',
		botName: cfg.botName || 'Durian Bikers',
		// Must stay in sync with DB_Chat_Settings::default_greeting().
		// Do NOT promise fitment help here: the catalogue has no bike-model
		// attribute, so the assistant always declines fitment questions. A
		// greeting that offers it sets up every customer for a let-down.
		greeting: cfg.greeting || 'Hi! Ask me anything about our parts, tyres and gear.',
		placeholder: cfg.placeholder || "I'm looking for…",
		launcherLabel: cfg.launcherLabel || 'Chat with Durian Bikers',
		closeLabel: cfg.closeLabel || 'Close chat',
		sendLabel: cfg.sendLabel || 'Send message',
		footnote: cfg.footnote || 'AI assistant — it can make mistakes. Please confirm fitment before you buy.',
		privacyLabel: cfg.privacyLabel || 'Privacy policy',
		inStock: cfg.inStockLabel || 'In stock',
		outOfStock: cfg.outOfStockLabel || 'Out of stock',
		view: cfg.viewLabel || 'View',
		pick: cfg.pickLabel || 'This one',
		// Heading over the page links the backend returns in `pages`.
		pages: cfg.pagesLabel || 'From our site:',
		typing: cfg.typingLabel || 'Assistant is typing',
		searching: cfg.searchingText || 'Searching the catalogue…',
		rateLimit: cfg.rateLimitText || 'You have sent a lot of messages in a short time. Please wait a few minutes and try again.',
		offline: cfg.offlineText || 'The chat assistant is offline right now. Please try again later, or contact us directly and a human will help.',
		error: cfg.errorText || 'Sorry, something went wrong on our side. Please try again in a moment.',
		network: cfg.networkText || 'Could not reach the store. Check your connection and try again.',
		tooLong: cfg.tooLongText || 'That message is a bit long. Please shorten it to 1000 characters or less.',
		retry: cfg.retryLabel || 'Try again',
		// Handoff fallbacks. Every one of these is overridden by
		// dbChat.handoff.* — they exist so a half-filled settings screen still
		// produces a sane button, not so English is hardcoded.
		handoffLabel: 'Chat with us on WhatsApp',
		handoffOpener: 'Hi, I have a question from your website'
	};

	var MAX_MESSAGE = 1000;      // matches the REST contract
	var MAX_HISTORY_TURNS = 10;
	var MAX_HISTORY_SENT = 10;   // REST contract: history array max 10 entries
	var MAX_PRODUCTS = 4;
	var MAX_PAGES = 2;           // REST contract: `pages` is 0–2 entries
	var MAX_SUGGESTIONS = 4;     // starter chips shown at open
	/*
		Follow-up chips (the guided product finder's next step) get a slightly
		wider cap than the starter row: 4 is enough for an opening prompt but
		too tight for a step like "which brand". It is still a cap, and the
		backend's own step lists can be longer than this — which is why every
		finder step is required to carry an escape option ("Any" / "Something
		else") INSIDE the first six, so a truncated list is never a dead end.
	*/
	var MAX_CHIPS = 6;
	var REQUEST_TIMEOUT = 45000; // backend runs a catalogue search loop; be patient
	var SESSION_TIMEOUT = 15000;
	var SEARCHING_AFTER = 2500;  // ms before the typing dots explain themselves
	var MOBILE_QUERY = '(max-width: 600px)';
	var CLIENT_KEY = 'db_chat_client';
	var HANDOFF_PREFILL_MAX = 200;  // characters of the customer's own message
	var HANDOFF_HIGHLIGHT_MS = 6000;
	// Header name is contract, not decoration — read it from the localized
	// object so a backend rename cannot silently send every request into the
	// shared rate-limit bucket. Fallback matches the route as built.
	var TOKEN_HEADER = (function () {
		var name = typeof cfg.tokenHeader === 'string' ? cfg.tokenHeader.trim() : '';
		return /^[A-Za-z0-9-]{1,64}$/.test(name) ? name : 'X-DB-Chat-Session';
	})();
	var SVG_NS = 'http://www.w3.org/2000/svg';

	// --- STATE --- //
	// Browser storage holds two things, both sessionStorage (dies with the
	// tab): the rate-limit client token, and the saved conversation described
	// under CONVERSATION PERSISTENCE — which is never written once orders come up.

	var history = [];
	var isOpen = false;
	var isSending = false;
	var lastAttempt = '';
	var lastFocused = null;
	var savedScrollY = 0;
	var shadow = null;
	var sessionNonce = '';
	var sessionPromise = null;
	var clientToken = '';
	var els = {};

	// Handoff state. `handoffOpen` is tri-state on purpose: null means "we do
	// not know", and we say nothing rather than guess.
	var handoff = null;
	var handoffOpen = null;
	var handoffHighlightTimer = null;
	// The customer's own last typed message — the ONLY transcript content that
	// may ever reach the wa.me URL.
	var lastUserMessage = '';
	// Sticky once true: the moment orders enter the conversation, the prefill
	// is off for the rest of the page lifetime.
	var ordersTouched = false;
	// Rendered conversation, for restoring it on the next page of this tab.
	var transcript = [];
	var restoring = false;
	// Extra request fields for a retry of the last message (the pick id).
	var lastAttemptExtra = null;

	// --- SMALL HELPERS --- //

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text !== undefined && text !== null) {
			node.textContent = String(text);
		}
		return node;
	}

	/**
	 * Inline SVG icons. Path data is authored here and never comes from a
	 * response, so building it from static strings is safe.
	 */
	function icon(paths, extraClass) {
		var svg = document.createElementNS(SVG_NS, 'svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('fill', 'none');
		svg.setAttribute('stroke', 'currentColor');
		svg.setAttribute('stroke-width', '2');
		svg.setAttribute('stroke-linecap', 'round');
		svg.setAttribute('stroke-linejoin', 'round');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('focusable', 'false');
		if (extraClass) {
			svg.setAttribute('class', extraClass);
		}
		paths.forEach(function (d) {
			var p = document.createElementNS(SVG_NS, 'path');
			p.setAttribute('d', d);
			svg.appendChild(p);
		});
		return svg;
	}

	/**
	 * Brand marks are solid shapes, not 2px strokes like the icon set above,
	 * so they need their own builder. Same rule applies: path data is authored
	 * here and never comes from a response.
	 */
	function brandIcon(paths, extraClass) {
		var svg = document.createElementNS(SVG_NS, 'svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('fill', 'currentColor');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('focusable', 'false');
		if (extraClass) {
			svg.setAttribute('class', extraClass);
		}
		paths.forEach(function (d) {
			var p = document.createElementNS(SVG_NS, 'path');
			p.setAttribute('d', d);
			svg.appendChild(p);
		});
		return svg;
	}

	// WhatsApp glyph, from Simple Icons (CC0). Filled, so it is drawn with
	// brandIcon() rather than icon().
	var BRAND_ICONS = {
		whatsapp: ['M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z']
	};

	var ICONS = {
		chat: ['M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z'],
		close: ['M18 6 6 18', 'M6 6l12 12'],
		send: ['M12 19V5', 'M5 12l7-7 7 7'],
		bot: ['M12 8V4H8', 'M4 8h16v12H4z', 'M2 14h2', 'M20 14h2', 'M9 13v2', 'M15 13v2'],
		image: ['M3 3h18v18H3z', 'M8.5 10a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z', 'M21 15l-5-5L5 21'],
		page: ['M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z', 'M14 2v6h6', 'M9 13h6', 'M9 17h4'],
		alert: ['M12 9v4', 'M12 17h.01', 'M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z']
	};

	/**
	 * Only allow http(s) and site-relative URLs. Blocks javascript:, data:
	 * and anything else, even though these values come from our own server.
	 */
	function safeUrl(value) {
		if (typeof value !== 'string') {
			return '';
		}
		var url = value.trim();
		if (!url) {
			return '';
		}
		if (url.charAt(0) === '/' && url.charAt(1) !== '/') {
			return url;
		}
		try {
			var parsed = new URL(url, window.location.origin);
			if (parsed.protocol === 'http:' || parsed.protocol === 'https:') {
				return parsed.href;
			}
		} catch (e) {
			return '';
		}
		return '';
	}

	function isMobileSheet() {
		return window.matchMedia(MOBILE_QUERY).matches;
	}

	function scrollToEnd() {
		if (els.log) {
			els.log.scrollTop = els.log.scrollHeight;
		}
	}

	/** Focus inside a shadow root does not surface on document.activeElement. */
	function activeEl() {
		if (shadow && shadow.activeElement) {
			return shadow.activeElement;
		}
		return document.activeElement;
	}

	// --- STYLESHEET RESOLUTION --- //

	function resolveCssUrl() {
		var fromConfig = safeUrl(cfg.cssUrl);
		if (fromConfig) {
			return fromConfig;
		}
		if (THIS_SCRIPT && THIS_SCRIPT.src && THIS_SCRIPT.src.indexOf('chatbot.js') !== -1) {
			return THIS_SCRIPT.src.replace(/chatbot\.js(\?.*)?$/, 'chatbot.css$1');
		}
		var links = document.querySelectorAll('link[rel="stylesheet"][href*="chatbot.css"]');
		if (links.length) {
			return links[0].href;
		}
		return '';
	}

	function attachStyles(reveal) {
		var href = resolveCssUrl();
		if (!href) {
			// No stylesheet found: show the widget anyway rather than leave
			// an invisible host on the page.
			reveal();
			return;
		}
		var link = document.createElement('link');
		link.setAttribute('rel', 'stylesheet');
		link.setAttribute('href', href);
		link.addEventListener('load', reveal);
		link.addEventListener('error', reveal);
		shadow.appendChild(link);
		// Belt and braces: never leave the widget hidden on a slow stylesheet.
		window.setTimeout(reveal, 2000);
	}

	// --- DOM BUILD --- //

	function buildAvatar(className) {
		var avatar = el('span', className);
		var src = safeUrl(cfg.avatar);
		if (src) {
			var img = document.createElement('img');
			img.setAttribute('src', src);
			img.setAttribute('alt', '');
			img.setAttribute('loading', 'lazy');
			img.setAttribute('decoding', 'async');
			img.addEventListener('error', function () {
				avatar.replaceChildren(icon(ICONS.bot));
			});
			avatar.appendChild(img);
		} else {
			avatar.appendChild(icon(ICONS.bot));
		}
		return avatar;
	}

	function buildLauncher() {
		var button = el('button', 'db-chat-launcher');
		button.type = 'button';
		button.setAttribute('aria-label', TEXT.launcherLabel);
		button.setAttribute('aria-expanded', 'false');
		button.setAttribute('aria-controls', 'db-chat-panel');
		button.title = TEXT.launcherLabel;
		button.appendChild(icon(ICONS.chat, 'db-chat-icon-open'));
		button.appendChild(icon(ICONS.close, 'db-chat-icon-close'));
		button.appendChild(el('span', 'db-chat-launcher-dot'));
		button.addEventListener('click', function () {
			if (isOpen) {
				closePanel();
			} else {
				openPanel();
			}
		});
		return button;
	}

	function buildOverlay() {
		var overlay = el('button', 'db-chat-overlay');
		overlay.type = 'button';
		overlay.setAttribute('tabindex', '-1');
		overlay.setAttribute('aria-hidden', 'true');
		overlay.addEventListener('click', closePanel);
		return overlay;
	}

	function buildHeader() {
		var header = el('header', 'db-chat-header');
		header.appendChild(buildAvatar('db-chat-avatar'));

		var heading = el('div', 'db-chat-heading');
		var title = el('p', 'db-chat-title', TEXT.title);
		title.id = 'db-chat-title';
		heading.appendChild(title);
		heading.appendChild(el('p', 'db-chat-subtitle', TEXT.subtitle));
		header.appendChild(heading);

		var close = el('button', 'db-chat-close');
		close.type = 'button';
		close.setAttribute('aria-label', TEXT.closeLabel);
		close.appendChild(icon(ICONS.close));
		close.addEventListener('click', closePanel);
		header.appendChild(close);

		els.close = close;
		return header;
	}

	/**
	 * THE chip button. Starter chips at open and follow-up chips arriving
	 * mid-conversation are the same affordance, so they share this factory and
	 * the same .db-chat-chip class — there is deliberately not a second chip
	 * component. Tapping either kind sends its own label as the customer's
	 * next message, which is also exactly what typing that label would do.
	 *
	 * el() writes the label with textContent. Follow-up labels are
	 * server-supplied and built from taxonomy term names the owner can edit in
	 * wp-admin, so a category literally named `<script>` is a plausible
	 * accident: it must appear as those characters and nothing else.
	 */
	function buildChip(label) {
		var chip = el('button', 'db-chat-chip', label);
		chip.type = 'button';
		chip.addEventListener('click', function () {
			send(label);
		});
		return chip;
	}

	/**
	 * Reduce a chip list to what can actually be rendered: plain, trimmed,
	 * non-empty, de-duplicated strings, capped at `max`.
	 *
	 * `strict` is the only difference between the two callers:
	 *
	 *   false — starter chips, from the owner's own dbChat.suggestions. These
	 *           have always skipped junk entries and rendered the rest, and
	 *           that behaviour is preserved.
	 *   true  — follow-up chips, from the server, mid-conversation. All or
	 *           nothing: a finder step that quietly lost "Brakes" because one
	 *           entry came back malformed would show the customer a menu that
	 *           is WRONG rather than absent, and a wrong menu is the worse
	 *           failure. Absent, empty, or not an array of plain strings
	 *           renders nothing at all.
	 *
	 * A label longer than the message cap is dropped either way: tapping it
	 * could only ever produce the "too long" notice, so offering it is a lie.
	 * Truncating instead would send a string the backend never offered.
	 *
	 * DO NOT ADD ANY OTHER TRANSFORMATION HERE. This is half of a two-file
	 * contract; the other half is includes/class-product-finder.php,
	 * match_option(), which carries the matching do-not-touch block and gates
	 * its prefix match on the marker described below. Each file names the
	 * other on purpose — what makes this class of bug expensive is distance,
	 * and a reader here needs to be able to find the code they would break.
	 *
	 * Whitespace trimming is the only permitted transformation, and it is
	 * safe. A label is sent to the backend byte-for-byte
	 * as the customer's message, and the finder truncates long labels on a
	 * word boundary and appends an ellipsis — then matches them by detecting
	 * that ellipsis on the RAW message and prefix-matching what precedes it.
	 * So "tidying" a label here — normalising U+2026 to "...", stripping
	 * trailing punctuation, collapsing the three-dot form, Unicode
	 * normalisation — would strip the very marker the match is gated on and
	 * turn every truncated chip back into a dead end: the tap would resolve
	 * to nothing while typing the full name still worked. That exact split
	 * was a live bug once already (Aug 2026). Verified byte-level: a tapped
	 * truncated chip sends its final character as U+2026 (charCode 8230)
	 * unaltered, and the literal "..." spelling passes through untouched too.
	 */
	function chipLabels(list, max, strict) {
		var out = [];
		if (!Array.isArray(list) || !list.length) {
			return out;
		}
		for (var i = 0; i < list.length; i += 1) {
			var item = list[i];
			if (typeof item !== 'string') {
				if (strict) {
					return [];
				}
				continue;
			}
			var label = item.trim();
			if (!label || label.length > MAX_MESSAGE) {
				continue;
			}
			if (out.length < max && out.indexOf(label) === -1) {
				out.push(label);
			}
		}
		return out;
	}

	function buildSuggestions() {
		var wrap = el('div', 'db-chat-suggestions');
		wrap.setAttribute('role', 'group');
		wrap.setAttribute('aria-label', 'Suggested questions');

		var labels = chipLabels(cfg.suggestions, MAX_SUGGESTIONS, false);
		labels.forEach(function (label) {
			wrap.appendChild(buildChip(label));
		});

		if (!labels.length) {
			wrap.hidden = true;
		}
		return wrap;
	}

	function buildFootnote() {
		var note = el('p', 'db-chat-footnote', TEXT.footnote);
		var privacy = safeUrl(cfg.privacyUrl);
		if (privacy) {
			note.appendChild(document.createTextNode(' '));
			var link = el('a', null, TEXT.privacyLabel);
			link.setAttribute('href', privacy);
			link.setAttribute('rel', 'nofollow');
			note.appendChild(link);
		}
		return note;
	}

	// --- HUMAN HANDOFF (WhatsApp) --- //

	/**
	 * Anything that suggests the conversation has touched the customer's own
	 * orders. Matching here is deliberately loose: a false positive costs a
	 * neutral opener instead of a helpful one, a false negative would put an
	 * order number into a URL that lands in browser history. Malay terms are
	 * included because customers do ask in Malay.
	 */
	var ORDER_HINT = new RegExp(
		'(^|[^a-z])(orders?|ordered|ordering|invoices?|invois|receipts?|resit|' +
		'tracking|tracked|track|parcels?|shipments?|shipped|consignment|awb|' +
		'refunds?|pesanan|penghantaran|pembelian)([^a-z]|$)' +
		'|#\\s*\\d{3,}' +      // "#1042"
		'|\\d{5,}',            // a bare order-number-looking run of digits
		'i'
	);

	function noteOrderContext(text) {
		if (ordersTouched || typeof text !== 'string' || !text) {
			return;
		}
		if (ORDER_HINT.test(text)) {
			ordersTouched = true;
			clearSavedState();
		}
	}

	/**
	 * Belt and braces on top of the text heuristic above: if a response ever
	 * reports which tools it ran, trust that too.
	 *
	 * The backend does NOT send `tools_used` today, and that is a deliberate
	 * decision, not an oversight — it declined to add any new field to the one
	 * response that can carry order data. So this is a no-op in practice and
	 * the text check above is the whole mechanism. Left in place because it
	 * costs nothing and fails safe: no field, no behaviour change.
	 */
	function noteOrderTools(data) {
		if (ordersTouched || !data || typeof data !== 'object') {
			return;
		}
		var used = Array.isArray(data.tools_used) ? data.tools_used : null;
		if (used && used.indexOf('get_my_orders') !== -1) {
			ordersTouched = true;
			clearSavedState();
		}
	}

	// --- CONVERSATION PERSISTENCE --- //
	/*
	 * Kept in sessionStorage so tapping a product card can open the product
	 * page in the same tab without losing the chat. Per tab, per store, 30
	 * minutes, 100 KB. Never once orders come up: the moment they do, what was
	 * saved is deleted and nothing more is written for this page's lifetime.
	 * Everything read back is rendered through the same textContent builders
	 * as a live reply.
	 */
	var STATE_VERSION = 1;
	var STATE_MAX_AGE = 30 * 60 * 1000;
	var STATE_MAX_BYTES = 100000;
	var STATE_MAX_ENTRIES = MAX_HISTORY_TURNS * 2;

	function stateKey() {
		var store = typeof cfg.store === 'string' ? cfg.store.trim().toLowerCase() : '';
		if (!/^[a-z]{2,8}$/.test(store)) {
			var match = /^\/([a-z]{2})(\/|$)/.exec(window.location.pathname || '');
			store = match ? match[1] : 'default';
		}
		return 'db_chat_state_v' + STATE_VERSION + '_' + store;
	}

	function clearSavedState() {
		try {
			window.sessionStorage.removeItem(stateKey());
		} catch (e) { /* storage unavailable */ }
	}

	function saveState() {
		if (restoring) {
			return;
		}
		if (ordersTouched) {
			clearSavedState();
			return;
		}
		var entries = transcript.slice(-STATE_MAX_ENTRIES).map(function (entry, i, list) {
			// Chips are only valid on the last turn.
			if (entry.role !== 'bot' || i === list.length - 1) {
				return entry;
			}
			return { role: 'bot', text: entry.text, products: entry.products, pages: entry.pages, chips: [] };
		});
		var payload;
		try {
			payload = JSON.stringify({ v: STATE_VERSION, savedAt: Date.now(), open: isOpen, history: history, pending: pendingOrder, transcript: entries });
		} catch (e) {
			return;
		}
		if (payload.length > STATE_MAX_BYTES) {
			clearSavedState();
			return;
		}
		try {
			window.sessionStorage.setItem(stateKey(), payload);
		} catch (e) { /* private mode or quota: the chat still works in memory */ }
	}

	function readSavedState() {
		var raw = null;
		try {
			raw = window.sessionStorage.getItem(stateKey());
		} catch (e) {
			return null;
		}
		if (!raw) {
			return null;
		}
		var data = null;
		try {
			data = raw.length <= STATE_MAX_BYTES ? JSON.parse(raw) : null;
		} catch (e) {
			data = null;
		}
		var age = data && typeof data.savedAt === 'number' ? Date.now() - data.savedAt : -1;
		var valid = !!data && typeof data === 'object' && data.v === STATE_VERSION &&
			age >= 0 && age <= STATE_MAX_AGE &&
			Array.isArray(data.history) && Array.isArray(data.transcript);
		if (!valid) {
			clearSavedState();
			return null;
		}
		return data;
	}

	function restoreState() {
		var data = readSavedState();
		if (!data) {
			return;
		}
		restoring = true;
		try {
			var entries = data.transcript.slice(-STATE_MAX_ENTRIES);
			entries.forEach(function (entry, i) {
				if (!entry || typeof entry.text !== 'string' || !entry.text.trim()) {
					return;
				}
				if (entry.role === 'user') {
					addUserMessage(entry.text);
					transcript.push({ role: 'user', text: entry.text });
					return;
				}
				if (entry.role !== 'bot') {
					return;
				}
				var products = Array.isArray(entry.products) ? entry.products : [];
				var pages = Array.isArray(entry.pages) ? entry.pages : [];
				var chips = Array.isArray(entry.chips) ? entry.chips : [];
				addBotMessage(entry.text);
				addProducts(products);
				addPages(pages);
				if (i === entries.length - 1) {
					addChips(chips);
				}
				transcript.push({ role: 'bot', text: entry.text, products: products, pages: pages, chips: chips });
			});
			history = data.history.filter(function (turn) {
				return turn && (turn.role === 'user' || turn.role === 'assistant') && typeof turn.content === 'string';
			}).slice(-(MAX_HISTORY_TURNS * 2));
			pendingOrder = data.pending && typeof data.pending === 'object' ? data.pending : null;
			if (transcript.length && els.suggestions) {
				els.suggestions.hidden = true;
			}
		} finally {
			restoring = false;
		}
		if (data.open === true) {
			openPanel();
		}
	}

	function trimmed(value) {
		return typeof value === 'string' ? value.trim() : '';
	}

	/**
	 * Merge a handoff payload over what we already have, per key, and only for
	 * keys that actually arrive. The session response carries just `isOpen`;
	 * it must never blank out `url` or `label` from the localized config.
	 */
	function mergeHandoff(src) {
		if (!src || typeof src !== 'object') {
			return;
		}

		var target = handoff || { url: '', label: '', hours: '', openNote: '', closedNote: '', opener: '' };

		// http(s) only, same check as every other URL in this file.
		var url = safeUrl(src.url);
		if (url) {
			target.url = url;
		}

		function take(key, aliases) {
			for (var i = 0; i < aliases.length; i += 1) {
				var value = trimmed(src[aliases[i]]);
				if (value) {
					target[key] = value;
					return;
				}
			}
		}

		take('label', ['label']);
		take('hours', ['hours']);
		take('openNote', ['openNote', 'open_note']);
		take('closedNote', ['closedNote', 'closed_note']);
		take('opener', ['opener', 'openerText', 'opener_text']);

		if (typeof src.isOpen === 'boolean') {
			handoffOpen = src.isOpen;
		} else if (typeof src.is_open === 'boolean') {
			handoffOpen = src.is_open;
		}

		handoff = target;
	}

	/**
	 * Pull the volatile open/closed flag out of the session response. Accepts
	 * it under `handoff` (the agreed shape) or flat on the root, so a backend
	 * that flattens it later does not silently drop us back to "unknown".
	 * Only ever reads a real boolean — a missing or non-boolean value leaves
	 * the state alone, which is what keeps branch 3 reachable.
	 */
	function applySessionHandoff(data) {
		if (!data || typeof data !== 'object') {
			return;
		}
		if (data.handoff && typeof data.handoff === 'object') {
			mergeHandoff(data.handoff);
		} else if (typeof data.isOpen === 'boolean') {
			handoffOpen = data.isOpen;
		} else if (typeof data.is_open === 'boolean') {
			handoffOpen = data.is_open;
		}
		renderHandoff();
	}

	function handoffOpener() {
		return (handoff && handoff.opener) || TEXT.handoffOpener;
	}

	/**
	 * The ONLY transcript content allowed into the URL: the customer's own last
	 * typed message, capped at 200 characters. Assistant replies, product data,
	 * page links and anything order-shaped are excluded by construction.
	 */
	function prefillText() {
		if (ordersTouched) {
			return handoffOpener();
		}
		var message = trimmed(lastUserMessage);
		if (!message) {
			return handoffOpener();
		}
		if (message.length > HANDOFF_PREFILL_MAX) {
			// Leave room for the ellipsis so the result is never over the cap.
			message = message.slice(0, HANDOFF_PREFILL_MAX - 1).replace(/\s+$/, '') + '…';
		}
		return message;
	}

	/**
	 * Build the wa.me URL with `?text=`. Any prefill already present on the
	 * configured URL is dropped rather than duplicated, and the fragment is
	 * preserved. encodeURIComponent, not URLSearchParams — the latter encodes
	 * spaces as "+", which is not what WhatsApp documents.
	 */
	function handoffHref() {
		var base = handoff.url;
		var text = prefillText();
		if (!text) {
			return base;
		}

		var hash = '';
		var hashAt = base.indexOf('#');
		if (hashAt !== -1) {
			hash = base.slice(hashAt);
			base = base.slice(0, hashAt);
		}

		var query = '';
		var queryAt = base.indexOf('?');
		if (queryAt !== -1) {
			query = base.slice(queryAt + 1).split('&').filter(function (part) {
				return part && part.indexOf('text=') !== 0;
			}).join('&');
			base = base.slice(0, queryAt);
		}

		return base + '?' + (query ? query + '&' : '') + 'text=' + encodeURIComponent(text) + hash;
	}

	/**
	 * Kept current rather than built only inside a click handler: a real <a>
	 * has to survive long-press, "open in new tab" and middle-click, none of
	 * which fire a click event. Refreshed on every event that can precede an
	 * activation, and again whenever the conversation changes, so the href in
	 * the DOM is never stale.
	 */
	function refreshHandoffHref() {
		if (!els.handoffLink || !handoff || !handoff.url) {
			return;
		}
		els.handoffLink.setAttribute('href', handoffHref());
	}

	/**
	 * Brief attention pull on the footer row when a reply points at it. One
	 * mechanism only — deliberately not also an inline button in the message,
	 * which would give the customer two things to find. CSS drops the motion
	 * under prefers-reduced-motion and leaves a static tint instead.
	 */
	function highlightHandoff() {
		var row = els.handoff;
		if (!row || row.hidden) {
			return;
		}
		row.classList.remove('is-highlight');
		void row.offsetWidth;
		row.classList.add('is-highlight');
		window.clearTimeout(handoffHighlightTimer);
		handoffHighlightTimer = window.setTimeout(function () {
			row.classList.remove('is-highlight');
		}, HANDOFF_HIGHLIGHT_MS);
	}

	/**
	 * Does this reply send the customer to the button? The assistant is told to
	 * hand off by name, so looking for the channel in the text covers it
	 * without a contract change; an explicit backend flag wins if one appears.
	 */
	function replyOffersHandoff(reply, data) {
		if (data && data.suggest_handoff === true) {
			return true;
		}
		if (!handoff || !handoff.url || typeof reply !== 'string') {
			return false;
		}
		return /whats\s?app/i.test(reply);
	}

	function buildHandoffRow() {
		var row = el('div', 'db-chat-handoff');
		row.hidden = true;
		els.handoff = row;
		return row;
	}

	/**
	 * Render (or re-render) the row from current state. Called once at build
	 * time and again when the session response lands. With no usable config
	 * the row stays empty and hidden — no button, no border, no gap.
	 */
	function renderHandoff() {
		var row = els.handoff;
		if (!row) {
			return;
		}

		row.replaceChildren();
		row.classList.remove('is-open', 'is-closed');
		els.handoffLink = null;

		if (!handoff || !handoff.url) {
			row.hidden = true;
			return;
		}

		var link = el('a', 'db-chat-handoff-link');
		link.setAttribute('href', handoff.url);
		link.setAttribute('target', '_blank');
		link.setAttribute('rel', 'noopener noreferrer');
		link.appendChild(brandIcon(BRAND_ICONS.whatsapp, 'db-chat-handoff-icon'));
		link.appendChild(el('span', 'db-chat-handoff-label', handoff.label || TEXT.handoffLabel));

		// Rebuild the URL on anything that can precede opening the link,
		// including the long-press and context menus that never fire a click.
		// None of these ever call preventDefault, hence passive: the touch
		// handlers must not make the sheet feel sticky to scroll.
		['pointerdown', 'touchstart', 'contextmenu', 'focus', 'keydown', 'click'].forEach(function (type) {
			link.addEventListener(type, refreshHandoffHref, { passive: true });
		});

		row.appendChild(link);
		els.handoffLink = link;

		var note = '';
		if (handoffOpen === true) {
			note = handoff.openNote;
			row.classList.add('is-open');
		} else if (handoffOpen === false) {
			note = handoff.closedNote;
			row.classList.add('is-closed');
		}
		// handoffOpen === null: hours only. Never an availability claim we
		// cannot back up.

		if (handoff.hours || note) {
			var meta = el('p', 'db-chat-handoff-meta');
			meta.id = 'db-chat-handoff-meta';
			if (handoff.hours) {
				meta.appendChild(el('span', 'db-chat-handoff-hours', handoff.hours));
			}
			if (note) {
				meta.appendChild(el('span', 'db-chat-handoff-note', note));
			}
			row.appendChild(meta);
			link.setAttribute('aria-describedby', meta.id);
		}

		row.hidden = false;
		refreshHandoffHref();
	}

	function buildComposer() {
		var composer = el('div', 'db-chat-composer');
		var form = el('form', 'db-chat-form');
		form.setAttribute('novalidate', 'novalidate');

		var label = el('label', 'db-chat-visually-hidden', 'Your message');
		label.setAttribute('for', 'db-chat-input');
		form.appendChild(label);

		var input = document.createElement('textarea');
		input.className = 'db-chat-input';
		input.id = 'db-chat-input';
		input.rows = 1;
		input.maxLength = MAX_MESSAGE;
		input.setAttribute('placeholder', TEXT.placeholder);
		input.setAttribute('autocomplete', 'off');
		input.setAttribute('autocapitalize', 'sentences');
		input.addEventListener('input', autoGrow);
		input.addEventListener('keydown', function (event) {
			// Enter sends, Shift+Enter makes a new line.
			if (event.key === 'Enter' && !event.shiftKey) {
				event.preventDefault();
				send(input.value);
			}
		});
		form.appendChild(input);

		var sendBtn = el('button', 'db-chat-send');
		sendBtn.type = 'submit';
		sendBtn.setAttribute('aria-label', TEXT.sendLabel);
		sendBtn.appendChild(icon(ICONS.send));
		form.appendChild(sendBtn);

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			send(input.value);
		});

		composer.appendChild(form);
		// Escape hatch to a human, above the AI disclaimer. Quiet on purpose:
		// it must not compete with the composer for attention.
		composer.appendChild(buildHandoffRow());
		composer.appendChild(buildFootnote());

		els.input = input;
		els.send = sendBtn;
		return composer;
	}

	function buildPanel() {
		var panel = el('div', 'db-chat-panel');
		panel.id = 'db-chat-panel';
		panel.setAttribute('role', 'dialog');
		panel.setAttribute('aria-modal', 'true');
		panel.setAttribute('aria-label', TEXT.title);
		panel.setAttribute('aria-labelledby', 'db-chat-title');
		panel.hidden = true;

		var log = el('div', 'db-chat-log');
		log.setAttribute('role', 'log');
		log.setAttribute('aria-live', 'polite');
		log.setAttribute('aria-relevant', 'additions text');
		log.setAttribute('tabindex', '0');
		log.setAttribute('aria-label', 'Conversation');

		var suggestions = buildSuggestions();

		panel.appendChild(buildHeader());
		panel.appendChild(log);
		panel.appendChild(suggestions);
		panel.appendChild(buildComposer());

		els.log = log;
		els.suggestions = suggestions;
		return panel;
	}

	function build() {
		var host = el('div', 'db-chat-host');
		host.id = 'db-chat-host';
		document.body.appendChild(host);

		var mount;
		if (typeof host.attachShadow === 'function') {
			shadow = host.attachShadow({ mode: 'open' });
			mount = shadow;
		} else {
			// Very old browser: fall back to the light DOM. Everything in
			// chatbot.css is scoped to .db-chat-root, so this still works.
			shadow = null;
			mount = host;
		}

		// Static handoff config, before the panel is built. `isOpen` may be
		// absent here on purpose — the session response supplies it.
		mergeHandoff(cfg.handoff);

		var root = el('div', 'db-chat-root');
		var panel = buildPanel();
		var launcher = buildLauncher();

		root.appendChild(buildOverlay());
		root.appendChild(panel);
		root.appendChild(launcher);
		mount.appendChild(root);

		els.host = host;
		els.root = root;
		els.panel = panel;
		els.launcher = launcher;
		els.overlay = root.querySelector('.db-chat-overlay');

		document.body.classList.add('db-chat-active');

		var revealed = false;
		function reveal() {
			if (revealed) {
				return;
			}
			revealed = true;
			host.classList.add('is-ready');
		}

		if (shadow) {
			attachStyles(reveal);
		} else {
			reveal();
		}

		renderHandoff();
		addBotMessage(TEXT.greeting);
		restoreState();
	}

	// --- MESSAGE RENDERING --- //

	function addUserMessage(text) {
		var wrap = el('div', 'db-chat-msg db-chat-msg-user');
		wrap.appendChild(el('div', 'db-chat-bubble', text));
		els.log.appendChild(wrap);
		scrollToEnd();
		return wrap;
	}

	/** Bot row: avatar + bubble + brand caption. */
	function addBotMessage(text) {
		var wrap = el('div', 'db-chat-msg db-chat-msg-bot');
		wrap.appendChild(buildAvatar('db-chat-msg-avatar'));

		var body = el('div', 'db-chat-msg-body');
		// textContent: assistant output is untrusted model text.
		body.appendChild(el('div', 'db-chat-bubble', text));
		body.appendChild(el('p', 'db-chat-caption', TEXT.botName));
		wrap.appendChild(body);

		els.log.appendChild(wrap);
		scrollToEnd();
		return wrap;
	}

	function buildProductCard(product) {
		var card = el('li', 'db-chat-product');
		var href = safeUrl(product.url);

		// Thumbnail and text are one link, same tab. The conversation is saved
		// on every turn, so it reopens on the product page exactly as it was.
		var main = el(href ? 'a' : 'div', 'db-chat-product-main');
		if (href) {
			main.setAttribute('href', href);
			main.addEventListener('click', function () {
				saveState();
			});
		}

		var thumb = el('div', 'db-chat-product-thumb');
		var imageSrc = safeUrl(product.image);
		if (imageSrc) {
			var img = document.createElement('img');
			img.setAttribute('src', imageSrc);
			img.setAttribute('alt', '');
			img.setAttribute('loading', 'lazy');
			img.setAttribute('decoding', 'async');
			img.addEventListener('error', function () {
				thumb.replaceChildren(icon(ICONS.image));
			});
			thumb.appendChild(img);
		} else {
			thumb.appendChild(icon(ICONS.image));
		}
		main.appendChild(thumb);

		var body = el('div', 'db-chat-product-body');
		body.appendChild(el('p', 'db-chat-product-name', product.name));

		var meta = el('div', 'db-chat-product-meta');
		// Price arrives pre-formatted in MYR from WooCommerce. Never build a
		// currency string here.
		if (typeof product.price === 'string' && product.price.trim()) {
			meta.appendChild(el('span', 'db-chat-product-price', product.price.trim()));
		}
		var inStock = product.in_stock === true;
		meta.appendChild(el(
			'span',
			'db-chat-pill ' + (inStock ? 'db-chat-pill-in' : 'db-chat-pill-out'),
			inStock ? TEXT.inStock : TEXT.outOfStock
		));
		body.appendChild(meta);
		main.appendChild(body);
		card.appendChild(main);

		// "This one": tells the bot exactly which product. A sibling of the
		// link, never inside it. Only for a real positive integer id — the
		// server re-checks the product either way.
		var id = typeof product.id === 'number' && product.id > 0 && Math.floor(product.id) === product.id ? product.id : 0;
		if (id) {
			var pick = el('button', 'db-chat-product-pick', TEXT.pick);
			pick.type = 'button';
			pick.setAttribute('aria-label', TEXT.pick + ': ' + product.name);
			pick.addEventListener('click', function () {
				send(product.name, { pick: id });
			});
			card.appendChild(pick);
		}

		return card;
	}

	/**
	 * Cards come ONLY from the `products` array in the JSON response. The
	 * reply text is never parsed for product data.
	 */
	function addProducts(products) {
		if (!Array.isArray(products) || !products.length) {
			return;
		}

		var list = el('ul', 'db-chat-products');
		list.setAttribute('aria-label', 'Suggested products');
		var count = 0;

		products.forEach(function (product) {
			if (count >= MAX_PRODUCTS) {
				return;
			}
			if (!product || typeof product !== 'object') {
				return;
			}
			if (typeof product.name !== 'string' || !product.name.trim()) {
				return;
			}
			list.appendChild(buildProductCard(product));
			count += 1;
		});

		if (!count) {
			return;
		}

		var wrap = el('div', 'db-chat-msg db-chat-msg-bot');
		// Blank spacer so the cards line up under the bubble above them.
		wrap.appendChild(el('span', 'db-chat-msg-spacer'));
		var body = el('div', 'db-chat-msg-body');
		body.appendChild(list);
		wrap.appendChild(body);

		els.log.appendChild(wrap);
		scrollToEnd();
	}

	/**
	 * Links to the store's own pages (delivery, returns, warranty…), from the
	 * `pages` array in the JSON response. Like products, these are never
	 * parsed out of the reply text.
	 *
	 * The backend resolves title and URL from the real WP_Post and re-checks
	 * publish status and the allowlist at response time, so the model never
	 * sees or invents a URL. safeUrl() is still applied — same belt-and-braces
	 * as product links, and it costs nothing.
	 */
	function addPages(pages) {
		if (!Array.isArray(pages) || !pages.length) {
			return;
		}

		// No aria-label here: the visible label paragraph below sits directly
		// above the list, and naming both makes it announce twice.
		var list = el('ul', 'db-chat-pages');
		var count = 0;

		pages.forEach(function (page) {
			if (count >= MAX_PAGES) {
				return;
			}
			if (!page || typeof page !== 'object') {
				return;
			}
			if (typeof page.title !== 'string' || !page.title.trim()) {
				return;
			}
			var href = safeUrl(page.url);
			if (!href) {
				return;
			}

			var item = el('li', 'db-chat-page');
			var link = el('a', 'db-chat-page-link');
			link.setAttribute('href', href);
			link.appendChild(icon(ICONS.page, 'db-chat-page-icon'));
			link.appendChild(el('span', 'db-chat-page-title', page.title.trim()));
			item.appendChild(link);
			list.appendChild(item);
			count += 1;
		});

		if (!count) {
			return;
		}

		var wrap = el('div', 'db-chat-msg db-chat-msg-bot');
		// Blank spacer so the links line up under the bubble above them.
		wrap.appendChild(el('span', 'db-chat-msg-spacer'));
		var body = el('div', 'db-chat-msg-body');
		body.appendChild(el('p', 'db-chat-pages-label', TEXT.pages));
		body.appendChild(list);
		wrap.appendChild(body);

		els.log.appendChild(wrap);
		scrollToEnd();
	}

	/**
	 * FOLLOW-UP CHIPS — the guided product finder's next step.
	 *
	 * Contract agreed with the backend (Aug 2026): `chips` is a flat array of
	 * plain strings. Not {label, value} objects, deliberately — tapping a chip
	 * sends its label as the customer's message, so the label IS the value.
	 * A hidden value would make the transcript show one thing while something
	 * else was sent. Typing "Helmets" and tapping the "Helmets" chip therefore
	 * produce the identical request; the widget stays dumb and the finder does
	 * the understanding.
	 *
	 * Absent, empty, or not an array of plain strings renders nothing and
	 * changes nothing, so a reply that never carries the key is completely
	 * untouched. That is still MOST replies: only the finder's own turns
	 * carry `chips`. The Claude path and any lookup reply the finder did not
	 * handle omit the key entirely, so the absent-key path is the common case
	 * and not a legacy one. (Stated structurally on purpose — an earlier
	 * version of this comment said "every backend in production right now",
	 * which was true when written and wrong within days.)
	 *
	 * WHY THEY GO IN THE LOG, not in the pinned starter row above the
	 * composer: the log is already role="log" aria-live="polite"
	 * aria-relevant="additions", so appending the group here is what announces
	 * the new options to a screen reader. Adding a second live region would
	 * compete with that one and get the reply read twice.
	 */
	function addChips(chips) {
		// Never two chip rows at once, whatever the caller did.
		clearChips();

		var labels = chipLabels(chips, MAX_CHIPS, true);
		if (!labels.length) {
			return;
		}

		var group = el('div', 'db-chat-chips');
		group.setAttribute('role', 'group');
		// Named here because there is no visible heading. Compare
		// .db-chat-pages, which HAS a visible label and therefore deliberately
		// carries no aria-label — naming both makes it announce twice.
		group.setAttribute('aria-label', 'Suggested replies');
		labels.forEach(function (label) {
			group.appendChild(buildChip(label));
		});

		var wrap = el('div', 'db-chat-msg db-chat-msg-bot');
		// Blank spacer so the chips line up under the bubble above them, the
		// same way product cards and page links do.
		wrap.appendChild(el('span', 'db-chat-msg-spacer'));
		var body = el('div', 'db-chat-msg-body');
		body.appendChild(group);
		wrap.appendChild(body);

		els.log.appendChild(wrap);
		els.chips = wrap;
		scrollToEnd();
	}

	/**
	 * Chips are only valid for the turn that produced them. A customer tapping
	 * "Under RM200" three turns after that step ended would send the finder a
	 * message it is no longer expecting, so the row is torn down the moment a
	 * new turn starts — whether it started by tapping a chip or by typing.
	 *
	 * If focus was sitting on the chip being removed, it is handed to the log
	 * rather than dropped on the body: the log is tabbable (tabindex="0"), so
	 * the trap and Esc keep working, and it does not pop the on-screen
	 * keyboard the way focusing the composer would on the mobile sheet.
	 */
	function clearChips() {
		if (!els.chips) {
			return;
		}
		var hadFocus = els.chips.contains(activeEl());
		if (els.chips.parentNode) {
			els.chips.remove();
		}
		els.chips = null;
		if (hadFocus && els.log) {
			try {
				els.log.focus({ preventScroll: true });
			} catch (e) {
				els.log.focus();
			}
		}
	}

	function addNotice(text, isError, onRetry) {
		var notice = el('div', 'db-chat-notice' + (isError ? ' db-chat-notice-error' : ''));
		notice.setAttribute('role', 'status');
		notice.appendChild(icon(ICONS.alert));

		var body = el('div');
		body.appendChild(el('span', null, text));

		if (typeof onRetry === 'function') {
			var retry = el('button', 'db-chat-notice-retry', TEXT.retry);
			retry.type = 'button';
			retry.addEventListener('click', function () {
				notice.remove();
				onRetry();
			});
			body.appendChild(document.createElement('br'));
			body.appendChild(retry);
		}

		notice.appendChild(body);
		els.log.appendChild(notice);
		scrollToEnd();
	}

	function showTyping() {
		hideTyping();

		var wrap = el('div', 'db-chat-msg db-chat-msg-bot');
		wrap.appendChild(buildAvatar('db-chat-msg-avatar'));

		var body = el('div', 'db-chat-msg-body');
		var bubble = el('div', 'db-chat-bubble db-chat-typing');
		bubble.setAttribute('aria-label', TEXT.typing);
		bubble.appendChild(el('span', 'db-chat-dot'));
		bubble.appendChild(el('span', 'db-chat-dot'));
		bubble.appendChild(el('span', 'db-chat-dot'));
		body.appendChild(bubble);
		wrap.appendChild(body);

		els.log.appendChild(wrap);
		els.typing = wrap;
		scrollToEnd();

		// Replies take 3-8s while the backend searches the catalogue, so say
		// so rather than pulse in silence.
		els.typingTimer = window.setTimeout(function () {
			if (!els.typing) {
				return;
			}
			body.appendChild(el('p', 'db-chat-typing-note', TEXT.searching));
			scrollToEnd();
		}, SEARCHING_AFTER);
	}

	function hideTyping() {
		window.clearTimeout(els.typingTimer);
		els.typingTimer = null;
		if (els.typing && els.typing.parentNode) {
			els.typing.remove();
		}
		els.typing = null;
	}

	function autoGrow() {
		if (!els.input) {
			return;
		}
		els.input.style.height = 'auto';
		var wanted = els.input.scrollHeight;
		els.input.style.height = Math.min(wanted, 120) + 'px';
		// Only show a scrollbar once the textarea has actually hit its cap;
		// otherwise a classic-scrollbar OS draws an empty track.
		els.input.style.overflowY = wanted > 120 ? 'auto' : 'hidden';
	}

	function setBusy(busy) {
		isSending = busy;
		if (els.send) {
			els.send.disabled = busy;
			els.send.classList.toggle('is-busy', busy);
		}
		if (els.input) {
			els.input.setAttribute('aria-busy', busy ? 'true' : 'false');
		}
	}

	// --- SESSION NONCE + CLIENT TOKEN --- //

	function randomId() {
		try {
			if (window.crypto && typeof window.crypto.randomUUID === 'function') {
				return window.crypto.randomUUID();
			}
			if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
				var bytes = new Uint8Array(16);
				window.crypto.getRandomValues(bytes);
				return Array.prototype.map.call(bytes, function (b) {
					return ('0' + b.toString(16)).slice(-2);
				}).join('');
			}
		} catch (e) { /* fall through */ }
		return 'db' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
	}

	/**
	 * Random per-tab id so the backend can rate-limit on hash(IP + token)
	 * instead of IP alone — visitors behind a shared proxy IP must not lock
	 * each other out. Falls back to an in-memory value in private mode.
	 */
	function getClientToken() {
		if (clientToken) {
			return clientToken;
		}
		var stored = '';
		try {
			stored = window.sessionStorage.getItem(CLIENT_KEY) || '';
		} catch (e) {
			stored = '';
		}
		// Must match the backend's ^[A-Za-z0-9_-]{8,64}$ — anything malformed
		// is dropped into a shared rate-limit bucket, so regenerate instead
		// of sending it. Also stops a tampered value reaching a header.
		if (!/^[A-Za-z0-9_-]{8,64}$/.test(stored)) {
			stored = '';
		}
		if (!stored) {
			stored = randomId();
			try {
				window.sessionStorage.setItem(CLIENT_KEY, stored);
			} catch (e) { /* private mode: memory only */ }
		}
		clientToken = stored;
		return clientToken;
	}

	/**
	 * Derive the session route from the chat route. Handles both pretty
	 * permalinks (.../durianbikers/v1/chat) and the plain
	 * ?rest_route=/durianbikers/v1/chat form.
	 */
	function sessionUrl() {
		var explicit = safeUrl(cfg.sessionUrl);
		if (explicit) {
			return explicit;
		}
		if (typeof cfg.ajaxUrl !== 'string' || !cfg.ajaxUrl) {
			return '';
		}
		return cfg.ajaxUrl.replace(/\/chat(\/)?(?=$|[?&#])/, '/session$1');
	}

	function fetchWithTimeout(url, options, ms) {
		var controller = typeof AbortController === 'function' ? new AbortController() : null;
		if (controller) {
			options.signal = controller.signal;
		}
		var timer = window.setTimeout(function () {
			if (controller) {
				controller.abort();
			}
		}, ms);
		return window.fetch(url, options).then(function (response) {
			window.clearTimeout(timer);
			return response;
		}, function (error) {
			window.clearTimeout(timer);
			throw error;
		});
	}

	/**
	 * Fetch (and memoise) a fresh REST nonce. Never read from the page HTML:
	 * LiteSpeed serves that from a 7-day cache and the nonce inside it is
	 * long dead. `force` bypasses the cached value after a stale-nonce 409.
	 */
	function fetchNonce(force) {
		if (!force && sessionNonce) {
			return Promise.resolve(sessionNonce);
		}
		if (!force && sessionPromise) {
			return sessionPromise;
		}

		var url = sessionUrl();
		if (!url) {
			return Promise.reject(new Error('db_chat_no_session_url'));
		}

		sessionPromise = fetchWithTimeout(url, {
			method: 'GET',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { 'Accept': 'application/json' }
		}, SESSION_TIMEOUT).then(function (response) {
			if (!response.ok) {
				throw new Error('db_chat_session_http_' + response.status);
			}
			return response.json();
		}).then(function (data) {
			if (!data || typeof data.nonce !== 'string' || !data.nonce) {
				throw new Error('db_chat_session_empty');
			}
			// Uncached route, so this is where a trustworthy open/closed flag
			// can come from. Never fatal: a missing handoff block just leaves
			// the button without an availability claim.
			applySessionHandoff(data);
			sessionNonce = data.nonce;
			sessionPromise = null;
			return sessionNonce;
		}).catch(function (error) {
			sessionPromise = null;
			throw error;
		});

		return sessionPromise;
	}

	// --- NETWORK --- //

	/**
	 * Pull a displayable message out of a WP_Error style JSON body. It is
	 * rendered with textContent so it cannot inject markup; the fallback
	 * covers a missing, empty or absurdly long message.
	 */
	function serverMessage(data, fallback) {
		if (data && typeof data.message === 'string') {
			var msg = data.message.trim();
			if (msg && msg.length <= 400) {
				return msg;
			}
		}
		return fallback;
	}

	/*
	 * The half-finished purchase, held between messages.
	 *
	 * It lives here rather than on the server for the same reason the
	 * conversation history does. Nothing here is trusted: the server re-reads
	 * the product, the variation, the stock and the price from WooCommerce
	 * before anything is added to a cart.
	 *
	 * Dies with the tab, deliberately. A purchase half-finished yesterday
	 * should not be waiting to be confirmed today.
	 */
	var pendingOrder = null;

	function postChat(message, nonce, extra) {
		var headers = {
			'Content-Type': 'application/json',
			'Accept': 'application/json',
			'X-WP-Nonce': nonce
		};
		// Abuse control only. This token goes nowhere else, is never used as
		// an analytics id, and dies with the tab.
		headers[TOKEN_HEADER] = getClientToken();

		return fetchWithTimeout(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: JSON.stringify(extra && extra.pick ? {
				message: message,
				history: history.slice(-MAX_HISTORY_SENT),
				pending: pendingOrder,
				pick: extra.pick
			} : {
				message: message,
				history: history.slice(-MAX_HISTORY_SENT),
				pending: pendingOrder
			})
		}, REQUEST_TIMEOUT).then(function (response) {
			return response.json().catch(function () {
				return null;
			}).then(function (data) {
				return { status: response.status, ok: response.ok, data: data };
			});
		});
	}

	/** A nonce that has aged out behind the full-page cache. */
	function isStaleNonce(result) {
		if (!result || result.ok) {
			return false;
		}
		var code = result.data && typeof result.data.code === 'string' ? result.data.code : '';
		if (result.status === 409) {
			return code === '' || code === 'db_chat_stale_nonce';
		}
		if (result.status === 403) {
			return code === 'rest_cookie_invalid_nonce';
		}
		return false;
	}

	/**
	 * One transparent retry on a stale nonce: fetch a fresh one and send the
	 * same message again. The customer should never read "session expired".
	 */
	function dispatch(message, attempt, extra) {
		return fetchNonce(attempt > 0).then(function (nonce) {
			return postChat(message, nonce, extra);
		}).then(function (result) {
			if (attempt === 0 && isStaleNonce(result)) {
				return dispatch(message, 1, extra);
			}
			return result;
		});
	}

	function send(rawValue, extra) {
		if (isSending) {
			return;
		}

		var message = String(rawValue == null ? '' : rawValue).trim();
		if (!message) {
			return;
		}
		if (message.length > MAX_MESSAGE) {
			addNotice(TEXT.tooLong, true);
			return;
		}
		if (!cfg.ajaxUrl) {
			addNotice(TEXT.offline, true);
			return;
		}

		lastAttempt = message;
		lastAttemptExtra = extra && extra.pick ? { pick: extra.pick } : null;
		var sentExtra = lastAttemptExtra;
		// The customer's own words: the only thing the WhatsApp prefill may
		// ever carry, and only if orders never came up.
		lastUserMessage = message;
		noteOrderContext(message);
		refreshHandoffHref();
		addUserMessage(message);

		if (els.input) {
			els.input.value = '';
			autoGrow();
		}
		if (els.suggestions) {
			els.suggestions.hidden = true;
		}
		// A new turn has started, so last turn's follow-up options are stale
		// whether the customer tapped one or typed something else entirely.
		clearChips();

		setBusy(true);
		showTyping();

		dispatch(message, 0, sentExtra).then(function (result) {
			hideTyping();
			setBusy(false);
			handleResponse(result, message, sentExtra);
		}).catch(function (error) {
			hideTyping();
			setBusy(false);
			// Session route failed, network down, DNS, or the request
			// timeout — always land on a real message, never a dead spinner.
			var isSession = error && typeof error.message === 'string' &&
				error.message.indexOf('db_chat_session') === 0;
			addNotice(isSession ? TEXT.error : TEXT.network, true, function () {
				send(lastAttempt, lastAttemptExtra);
			});
		});
	}

	function handleResponse(result, sentMessage, sentExtra) {
		var data = result.data;

		// Before anything renders: if this turn went near the customer's
		// orders, the prefill is off from here on.
		noteOrderTools(data);

		if (!result.ok) {
			if (result.status === 429) {
				addNotice(serverMessage(data, TEXT.rateLimit), false);
				return;
			}
			if (result.status === 503) {
				addNotice(serverMessage(data, TEXT.offline), false);
				return;
			}
			if (result.status === 400 || result.status === 403 || result.status === 409) {
				// Terminal by contract (db_chat_missing_nonce, validation, or
				// a stale nonce that survived the one silent retry). Retrying
				// from the same page would just fail the same way.
				addNotice(serverMessage(data, TEXT.error), true);
				return;
			}
			// 500 and anything else — worth one manual retry.
			addNotice(serverMessage(data, TEXT.error), true, function () {
				send(sentMessage, sentExtra);
			});
			return;
		}

		var reply = data && typeof data.reply === 'string' ? data.reply.trim() : '';
		if (!reply) {
			addNotice(TEXT.error, true, function () {
				send(sentMessage, sentExtra);
			});
			return;
		}

		// An assistant reply can name an order too ("order #1042 shipped"), so
		// it counts as touching orders even though the reply text itself never
		// reaches the URL.
		noteOrderContext(reply);
		refreshHandoffHref();

		addBotMessage(reply);
		/*
		 * Always reassign, including to null. A reply with no pending order is
		 * how a finished or cancelled purchase is CLEARED — keeping the old
		 * one would leave the widget acting on an order that is over.
		 */
		pendingOrder = (data && data.pending) ? data.pending : null;

		if (data && data.cart && data.cart.added) {
			// The theme supplies the header cart fragment; ask WooCommerce to
			// refresh it so the icon count matches what just happened.
			if (window.jQuery) {
				try {
					window.jQuery(document.body).trigger('wc_fragment_refresh');
				} catch (e) {
					// A stale icon is not worth breaking the reply over.
				}
			}
		}

		addProducts(data ? data.products : null);
		// Page links before chips: products are the buying path, these are the
		// "read the policy in full" follow-up. A reply can carry both.
		addPages(data ? data.pages : null);
		// Chips last of all, so the thing the customer is meant to tap next
		// sits closest to the composer. Usually a finder step arrives with no
		// products and no pages, but the order has to hold when it does.
		addChips(data ? data.chips : null);

		// The assistant just pointed at the button — make it easy to find.
		if (replyOffersHandoff(reply, data)) {
			highlightHandoff();
		}

		history.push({ role: 'user', content: sentMessage });
		history.push({ role: 'assistant', content: reply });
		// In-memory only, capped at 10 turns (2 entries per turn).
		if (history.length > MAX_HISTORY_TURNS * 2) {
			history = history.slice(-(MAX_HISTORY_TURNS * 2));
		}

		transcript.push({ role: 'user', text: sentMessage });
		transcript.push({
			role: 'bot',
			text: reply,
			products: data && Array.isArray(data.products) ? data.products : [],
			pages: data && Array.isArray(data.pages) ? data.pages : [],
			chips: data && Array.isArray(data.chips) ? data.chips : []
		});
		if (transcript.length > STATE_MAX_ENTRIES) {
			transcript = transcript.slice(-STATE_MAX_ENTRIES);
		}
		saveState();
	}

	// --- OPEN / CLOSE / FOCUS --- //

	function lockScroll() {
		savedScrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
		document.body.style.top = (-savedScrollY) + 'px';
		document.body.classList.add('db-chat-locked');
	}

	function unlockScroll() {
		if (!document.body.classList.contains('db-chat-locked')) {
			return;
		}
		document.body.classList.remove('db-chat-locked');
		document.body.style.top = '';
		window.scrollTo(0, savedScrollY);
	}

	function openPanel() {
		if (isOpen) {
			return;
		}
		isOpen = true;
		lastFocused = document.activeElement;

		// Kick the session nonce off first so the round-trip overlaps the
		// open animation. Failures surface at send time, not here.
		fetchNonce(false).catch(function () { /* handled on send */ });

		els.panel.hidden = false;
		// Force a reflow so the open transition runs from the closed state.
		void els.panel.offsetWidth;
		els.root.classList.add('is-open', 'is-seen');
		els.launcher.setAttribute('aria-expanded', 'true');

		if (isMobileSheet()) {
			lockScroll();
		}

		document.addEventListener('keydown', onKeydown, true);
		saveState();

		window.setTimeout(function () {
			if (!isOpen) {
				return;
			}
			// Do not pop the on-screen keyboard the instant the sheet opens.
			if (isMobileSheet()) {
				els.close.focus();
			} else if (els.input) {
				els.input.focus();
			}
			scrollToEnd();
		}, 80);
	}

	function closePanel() {
		if (!isOpen) {
			return;
		}
		isOpen = false;
		els.root.classList.remove('is-open');
		els.launcher.setAttribute('aria-expanded', 'false');
		unlockScroll();
		document.removeEventListener('keydown', onKeydown, true);
		saveState();

		window.setTimeout(function () {
			if (!isOpen) {
				els.panel.hidden = true;
			}
		}, 320);

		if (lastFocused && typeof lastFocused.focus === 'function' && document.contains(lastFocused)) {
			lastFocused.focus();
		} else {
			els.launcher.focus();
		}
	}

	var FOCUSABLE = 'a[href], button:not(:disabled), textarea:not(:disabled), input:not(:disabled), select:not(:disabled), [tabindex]:not([tabindex="-1"])';

	function focusables() {
		return Array.prototype.filter.call(
			els.panel.querySelectorAll(FOCUSABLE),
			function (node) {
				return node.offsetParent !== null || node === activeEl();
			}
		);
	}

	function onKeydown(event) {
		if (event.key === 'Escape' || event.key === 'Esc') {
			event.stopPropagation();
			closePanel();
			return;
		}

		if (event.key !== 'Tab') {
			return;
		}

		// Focus trap: keep Tab inside the dialog while it is open.
		var nodes = focusables();
		if (!nodes.length) {
			return;
		}
		var first = nodes[0];
		var last = nodes[nodes.length - 1];
		var active = activeEl();

		if (!els.panel.contains(active)) {
			event.preventDefault();
			first.focus();
			return;
		}
		if (event.shiftKey && active === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && active === last) {
			event.preventDefault();
			first.focus();
		}
	}

	// Rotating the phone or resizing across the 600px breakpoint while the
	// panel is open must not strand the body in a locked state.
	window.addEventListener('resize', function () {
		if (!isOpen) {
			return;
		}
		if (isMobileSheet()) {
			if (!document.body.classList.contains('db-chat-locked')) {
				lockScroll();
			}
		} else {
			unlockScroll();
		}
	});

	// --- INIT --- //

	function init() {
		if (document.getElementById('db-chat-host')) {
			return;
		}
		if (typeof window.fetch !== 'function') {
			return;
		}
		build();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
