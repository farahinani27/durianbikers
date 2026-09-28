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
else from the transcript. If the conversation has looked up the customer's
own orders (the server's order_lookup signal — see noteOrderTools), the
prefill is skipped in favour of a neutral opener: a wa.me URL is visible in browser
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
		handoffOpener: 'Hi, I have a question from your website',
		// Pre-chat contact gate (name + email required before chatting).
		gatePrompt: cfg.gatePromptText || "Before we start, please tell us who we're chatting with:",
		gateSubmit: cfg.gateSubmitLabel || 'Start chat',
		gateNameError: cfg.gateNameErrorText || "Please enter your name so we know who we're talking to.",
		gateEmailError: cfg.gateEmailErrorText || 'Please enter a valid email so we can reply.',
		gatePlaceholder: cfg.gatePlaceholderText || 'Enter your name and email above to start…',
		gatePhonePlaceholder: cfg.gatePhonePlaceholderText || 'Phone number (optional)'
	};

	var MAX_MESSAGE = 1000;      // matches the REST contract
	var MAX_HISTORY_TURNS = 10;
	var MAX_HISTORY_SENT = 10;   // REST contract: history array max 10 entries
	var MAX_PRODUCTS = 4;
	var MAX_PAGES = 2;           // REST contract: `pages` is 0–2 entries
	var MAX_CART_ITEMS = 20;     // defensive cap; a real cart is never this long
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
	// Human handoff (staff inbox) — a second, unrelated "handoff" concept from
	// the WhatsApp button above. `dbchat_cid` is localStorage on purpose (it
	// must outlive the tab, unlike everything else this file stores) and
	// holds only an opaque conversation id — never a message, never contact
	// info. POLL_IDLE_MS caps how long fetchNewMessages() is allowed to keep
	// ticking with the panel open and nobody replying, so a customer who
	// opens the chat and walks away does not poll forever.
	var CONVERSATION_ID_KEY = 'dbchat_cid';
	// Per-viewer memory of whether the "Contact & help" extras are expanded.
	var EXTRAS_KEY = 'dbchat_extras_open';
	var POLL_IDLE_MS = 10 * 60 * 1000;
	// Basic shape check for the contact-step email field. Deliberately loose
	// (matches the backend's sanitize_email() intent, not full RFC 5322) —
	// good enough to catch typos without rejecting a real address.
	var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
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
	// True while a checkout SUMMARY is on screen waiting to be confirmed, so a
	// plain typed reply can be routed to the deterministic checkout confirm.
	var awaitingCheckoutConfirm = false;
	// Photo attach (vision). visionEnabled gates the whole UI; pendingImage
	// holds the { media_type, data } for the NEXT send, cleared after it goes.
	var visionEnabled = cfg.visionEnabled === '1' || cfg.visionEnabled === true;
	var pendingImage = null;
	// Pre-chat contact gate. When on, a guest must give a name + email before
	// the composer unlocks. contactGiven holds { name, email } once satisfied
	// (a signed-in account via /session, a restored guest submission, or the
	// form). contactFromGate is true only for a guest's own submission — that
	// is the one case whose details must ride the first /chat POST (a signed-in
	// customer's come from their account server-side). contactSessionResolved
	// gates showing the form until /session has told us whether they're signed
	// in, so a logged-in customer never sees the form flash.
	var contactGateEnabled = cfg.contactGate === '1' || cfg.contactGate === true;
	var contactGiven = null;
	var contactFromGate = false;
	var contactSessionResolved = false;
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

	// Human handoff (staff inbox), OFF by default in production. Every single
	// behaviour below — the footer button, the escalate prompt, the contact
	// step, polling, rendering agent/system messages — is gated on
	// handoffEnabled, which only ever becomes true from a real GET /session
	// response. While it is false this file behaves exactly as it did before
	// this feature existed: no new button, no new fetches, no new DOM.
	var conversationId = null;
	var lastMessageId = 0;
	var handoffEnabled = false;
	var pollSeconds = 5;
	var pollTimer = null;
	var pollIdleTimer = null;
	// 'bot' | 'waiting' | 'human' | 'resolved' | null (unknown / not started)
	var currentStatus = null;
	// True once fetchNewMessages() has completed at least one round since the
	// current startPolling() call. The FIRST round after (re)starting polling
	// backfills whatever history is already sitting on the server (e.g. after
	// a page reload) and must render silently — only messages that show up on
	// a later round are genuinely new arrivals worth a sound.
	var pollBackfilled = true;
	// Lazily-created WebAudio context for the inbound-message notification.
	// Never built until a user gesture (opening the widget, submitting the
	// handoff form) primes it — see primeAudioContext().
	var audioCtx = null;

	// Seeded once, synchronously, so the very first /session call already
	// carries the id if this tab (or a previous one, via localStorage) has
	// one. try/catch: private mode / blocked storage must not break the rest
	// of the widget.
	try {
		var storedConversationId = window.localStorage.getItem(CONVERSATION_ID_KEY);
		if (typeof storedConversationId === 'string' && storedConversationId) {
			conversationId = storedConversationId;
		}
	} catch (e) { /* storage unavailable: start without one */ }

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
		user: ['M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2', 'M12 3a4 4 0 1 0 0 8 4 4 0 1 0 0-8z'],
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

	/**
	 * Avatar for a HUMAN team member's reply. A person glyph, never the bot
	 * logo, so the customer can see at a glance that a real person answered
	 * once the conversation is handed off.
	 */
	function buildAgentAvatar(className) {
		var avatar = el('span', className + ' db-chat-avatar-agent');
		avatar.appendChild(icon(ICONS.user));
		return avatar;
	}

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
	 * Mark the conversation as having touched the customer's own ORDER data —
	 * the reply carried real order details (order number, tracking, items) from
	 * get_my_orders. This drives two protections: the chat is not persisted, and
	 * the WhatsApp prefill drops it (so an order number never lands in a URL).
	 *
	 * The signal is PRECISE, from the server: `data.order_lookup === true` (set
	 * only when the order tool actually ran this turn), or a `tools_used` list
	 * naming get_my_orders. It deliberately does NOT trigger on the mere WORD
	 * "order" — that fired constantly (the bot's own "track your orders" line
	 * included) and wiped every ordinary chat's saved history, which is exactly
	 * what customers were losing on navigation.
	 */
	function noteOrderTools(data) {
		if (ordersTouched || !data || typeof data !== 'object') {
			return;
		}
		var used = Array.isArray(data.tools_used) ? data.tools_used : null;
		var byTool = !!(used && used.indexOf('get_my_orders') !== -1);
		if (data.order_lookup === true || byTool) {
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
	// How long a chat survives with no activity before it is dropped and the
	// widget starts fresh. Each save refreshes the clock, so this is an IDLE
	// timeout: 1 hour since the customer's last message/interaction.
	var STATE_MAX_AGE = 60 * 60 * 1000;
	// Raised from 100KB to fit a persisted photo (a downscaled JPEG data URL is
	// a few hundred KB). saveState drops image data before it ever hits this cap.
	var STATE_MAX_BYTES = 2500000;
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
		var savedAt = Date.now();
		var payload;
		try {
			payload = JSON.stringify({ v: STATE_VERSION, savedAt: savedAt, open: isOpen, history: history, pending: pendingOrder, transcript: entries, contact: (contactFromGate ? contactGiven : null) });
		} catch (e) {
			return;
		}
		// Too big — almost always a persisted photo. Drop image data (keep the
		// text conversation) and retry, so the chat still persists on reload.
		if (payload.length > STATE_MAX_BYTES) {
			var lean = entries.map(function (entry) {
				if (entry && entry.role === 'user' && entry.image) {
					return { role: 'user', text: entry.text };
				}
				return entry;
			});
			try {
				payload = JSON.stringify({ v: STATE_VERSION, savedAt: savedAt, open: isOpen, history: history, pending: pendingOrder, transcript: lean, contact: (contactFromGate ? contactGiven : null) });
			} catch (e2) {
				return;
			}
			if (payload.length > STATE_MAX_BYTES) {
				clearSavedState();
				return;
			}
		}
		try {
			window.sessionStorage.setItem(stateKey(), payload);
		} catch (e) { /* private mode or quota: the chat still works in memory */ }
	}

	// Read + validate the saved state at ONE storage key. Invalid/expired state
	// at that key is removed. Returns the parsed object or null.
	function parseStateAt(key) {
		var raw = null;
		try {
			raw = window.sessionStorage.getItem(key);
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
			try {
				window.sessionStorage.removeItem(key);
			} catch (e) { /* storage unavailable */ }
			return null;
		}
		return data;
	}

	function readSavedState() {
		var key = stateKey();
		var data = parseStateAt(key);
		if (data) {
			return data;
		}

		/*
		 * The store prefix in the key can differ between pages of the same site:
		 * the "/" homepage keys as `_default`, while "/my/" pages key as `_my`.
		 * Without this, opening the chat on the homepage and then clicking into
		 * the store would look like a fresh widget — the in-progress chat is in a
		 * different bucket. So when THIS page's bucket is empty, adopt the most
		 * recent valid chat from any other bucket and migrate it to this key, so
		 * the conversation follows the customer as they browse.
		 */
		var prefix = 'db_chat_state_v' + STATE_VERSION + '_';
		var keys = [];
		try {
			for (var i = 0; i < window.sessionStorage.length; i++) {
				var k = window.sessionStorage.key(i);
				if (k) {
					keys.push(k);
				}
			}
		} catch (e) { /* iteration unavailable: nothing to migrate */ }

		var best = null;
		var bestKey = null;
		keys.forEach(function (k) {
			if (k.indexOf(prefix) !== 0 || k === key) {
				return;
			}
			var d = parseStateAt(k);
			if (d && (!best || (d.savedAt || 0) > (best.savedAt || 0))) {
				best = d;
				bestKey = k;
			}
		});

		if (best && bestKey) {
			// Crossing between two DIFFERENT real stores (my <-> gb) could carry a
			// stale-currency pending order; drop just that (the chat text is fine
			// to keep). Migrating from the ambiguous `_default` splash keeps it.
			var curStore = key.slice(prefix.length);
			var srcStore = bestKey.slice(prefix.length);
			if (/^[a-z]{2}$/.test(curStore) && /^[a-z]{2}$/.test(srcStore) && curStore !== srcStore) {
				best.pending = null;
			}
			try {
				window.sessionStorage.setItem(key, JSON.stringify(best));
				window.sessionStorage.removeItem(bestKey);
			} catch (e) { /* storage full/unavailable: still use it in memory */ }
			return best;
		}

		return null;
	}

	function restoreState() {
		var data = readSavedState();
		if (!data) {
			return;
		}
		// A returning guest who already gave their details this tab session:
		// restore them so the pre-chat gate does not ask again. (A signed-in
		// customer's come from /session instead, never from saved state.)
		if (contactGateEnabled && data.contact && typeof data.contact === 'object' &&
			data.contact.email && EMAIL_RE.test(String(data.contact.email))) {
			contactGiven = { name: trimmed(data.contact.name), email: trimmed(data.contact.email), phone: trimmed(data.contact.phone) };
			contactFromGate = true;
		}
		restoring = true;
		try {
			var entries = data.transcript.slice(-STATE_MAX_ENTRIES);
			entries.forEach(function (entry, i) {
				if (!entry || typeof entry.text !== 'string') {
					return;
				}
				var entryImage = (entry.role === 'user' && typeof entry.image === 'string' && entry.image.indexOf('data:image/') === 0) ? entry.image : '';
				// A text-less entry is only valid when it carried a photo.
				if (!entry.text.trim() && !entryImage) {
					return;
				}
				if (entry.role === 'user') {
					addUserMessage(entry.text, entryImage || undefined);
					transcript.push(entryImage ? { role: 'user', text: entry.text, image: entryImage } : { role: 'user', text: entry.text });
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

	/**
	 * Human handoff (staff inbox) — an unrelated feature from the WhatsApp
	 * button above, sharing only the `handoff` key on the session response.
	 * Reads `conversation_id` (round-tripped regardless of the flag: see
	 * sessionUrl's caller) and `handoff.enabled` / `handoff.pollSeconds`
	 * (only ever present when the backend has the feature turned on).
	 *
	 * A missing or malformed block leaves handoffEnabled false, which is the
	 * production default — nothing here ever turns it on by guessing.
	 */
	function applyHandoffFeature(data) {
		if (!data || typeof data !== 'object') {
			return;
		}
		if (typeof data.conversation_id === 'string' && data.conversation_id) {
			conversationId = data.conversation_id;
			try {
				window.localStorage.setItem(CONVERSATION_ID_KEY, conversationId);
			} catch (e) { /* storage unavailable: memory only for this tab */ }
		}

		var block = data.handoff && typeof data.handoff === 'object' ? data.handoff : null;
		if (!block || block.enabled !== true) {
			return;
		}

		handoffEnabled = true;
		var seconds = parseInt(block.pollSeconds, 10);
		if (!isNaN(seconds) && seconds > 0) {
			pollSeconds = seconds;
		}

		// Backend now round-trips the conversation's current status here too,
		// so a page reload mid-handoff (currentStatus starts out null) knows
		// to resume polling instead of going silent until the customer sends
		// another message.
		currentStatus = data.handoff.status || currentStatus;

		if ('resolved' === currentStatus) {
			// A reopened widget must never resume a resolved thread: forget
			// the conversation entirely (mirrors the live-poll resolved
			// branch in fetchNewMessages()) so the next message starts a
			// brand new one. The server enforces this too (see
			// class-chat-endpoint.php), but a reload should not even try.
			conversationId = null;
			lastMessageId = 0;
			currentStatus = null;
			try {
				window.localStorage.removeItem(CONVERSATION_ID_KEY);
			} catch (e) { /* storage unavailable: memory only */ }
			// Also wipe the saved transcript so a resolved chat does not
			// restore on this reload or a later page (mirrors the live-poll
			// resolved branch).
			clearSavedState();
			setHumanHandoffButtonVisible(true);
			setBotHandoffButtonVisible(false);
			return;
		}

		setHumanHandoffButtonVisible(currentStatus !== 'waiting' && currentStatus !== 'human');
		setBotHandoffButtonVisible(currentStatus === 'waiting' || currentStatus === 'human');
		if (conversationId && (currentStatus === 'waiting' || currentStatus === 'human')) {
			startPolling();
		}
	}

	/**
	 * Pre-chat contact gate — read the /session response's `contact` block. For
	 * a signed-in customer the backend sends { name, email, satisfied:true } so
	 * we can unlock without ever showing the form. Marks the session resolved
	 * either way, which is what lets a guest's form finally appear (and never
	 * flash to a logged-in customer beforehand).
	 */
	function applyContactGate(data) {
		if (!contactGateEnabled) {
			return;
		}
		contactSessionResolved = true;
		var c = data && typeof data === 'object' ? data.contact : null;
		if (!contactGiven && c && typeof c === 'object' && c.satisfied === true && c.email) {
			contactGiven = { name: trimmed(c.name), email: trimmed(c.email), phone: trimmed(c.phone) };
			contactFromGate = false; // account-sourced; server fills it, don't POST it.
		}
		refreshContactGate();
	}

	/** Lock or unlock the composer + starter chips behind the contact gate. */
	function setComposerLocked(locked) {
		if (els.input) {
			els.input.disabled = locked;
			try {
				els.input.setAttribute('placeholder', locked ? TEXT.gatePlaceholder : TEXT.placeholder);
			} catch (e) { /* attribute set can't fail in practice */ }
		}
		if (els.send) {
			els.send.disabled = locked;
		}
		if (els.suggestions) {
			els.suggestions.classList.toggle('db-chat-suggestions-locked', locked);
		}
	}

	/**
	 * Reconcile the gate with current state. Satisfied → unlock and drop the
	 * form. Not satisfied → lock, and show the form only once /session has
	 * resolved and the panel is open (so a logged-in customer never sees it).
	 */
	function refreshContactGate() {
		if (!contactGateEnabled) {
			return;
		}
		if (contactGiven) {
			setComposerLocked(false);
			if (els.contactGate) {
				els.contactGate.remove();
				els.contactGate = null;
			}
			return;
		}
		setComposerLocked(true);
		if (contactSessionResolved && isOpen) {
			openContactGateForm();
		}
	}

	/**
	 * The pre-chat form: name + email, both required. A leaner sibling of
	 * openContactStep() (the handoff form) — no phone, no cancel, and on submit
	 * it unlocks the composer rather than posting anywhere. The details are kept
	 * client-side (saved state) and ride the FIRST /chat POST, where the server
	 * persists them on the conversation.
	 */
	function openContactGateForm() {
		if (els.contactGate) {
			return;
		}

		var wrap = el('div', 'db-chat-msg db-chat-msg-bot');
		wrap.appendChild(buildAvatar('db-chat-msg-avatar'));
		var body = el('div', 'db-chat-msg-body');

		var box = el('div', 'db-chat-contact-step db-chat-contact-gate');
		box.appendChild(el('p', 'db-chat-contact-label', TEXT.gatePrompt));

		var nameInput = document.createElement('input');
		nameInput.type = 'text';
		nameInput.className = 'db-chat-contact-input db-chat-contact-name';
		nameInput.setAttribute('placeholder', 'Your name');
		nameInput.setAttribute('autocomplete', 'name');
		box.appendChild(nameInput);

		var emailInput = document.createElement('input');
		emailInput.type = 'email';
		emailInput.className = 'db-chat-contact-input db-chat-contact-email';
		emailInput.setAttribute('placeholder', 'you@example.com');
		emailInput.setAttribute('autocomplete', 'email');
		box.appendChild(emailInput);

		var error = el('p', 'db-chat-contact-error', '');
		error.setAttribute('role', 'alert');
		error.hidden = true;
		box.appendChild(error);

		// Phone is OPTIONAL — no validation, blank is fine. Placed after the
		// email error so a validation message sits directly under the email.
		var phoneInput = document.createElement('input');
		phoneInput.type = 'tel';
		phoneInput.className = 'db-chat-contact-input db-chat-contact-phone';
		phoneInput.setAttribute('placeholder', TEXT.gatePhonePlaceholder);
		phoneInput.setAttribute('autocomplete', 'tel');
		box.appendChild(phoneInput);

		function showError(text, field) {
			error.textContent = text;
			error.hidden = false;
			if (field) {
				field.classList.add('is-invalid');
				try {
					field.focus();
				} catch (e) { /* not focusable: harmless */ }
			}
		}
		function clearError() {
			if (error.hidden) {
				return;
			}
			error.hidden = true;
			error.textContent = '';
			nameInput.classList.remove('is-invalid');
			emailInput.classList.remove('is-invalid');
		}
		nameInput.addEventListener('input', clearError);
		emailInput.addEventListener('input', clearError);

		function attempt() {
			var name = trimmed(nameInput.value);
			var email = trimmed(emailInput.value);
			if (!name) {
				showError(TEXT.gateNameError, nameInput);
				return;
			}
			if (!email || !EMAIL_RE.test(email)) {
				showError(TEXT.gateEmailError, emailInput);
				return;
			}
			clearError();
			contactGiven = { name: name, email: email, phone: trimmed(phoneInput.value) };
			contactFromGate = true;
			// Real user gesture: prime the notification audio for later polling.
			primeAudioContext();
			try {
				saveState();
			} catch (e) { /* memory-only is fine */ }
			refreshContactGate();
			if (els.input) {
				try {
					els.input.focus();
				} catch (e) { /* harmless */ }
			}
		}

		var actions = el('div', 'db-chat-contact-actions');
		var startBtn = el('button', 'db-chat-contact-send', TEXT.gateSubmit);
		startBtn.type = 'button';
		startBtn.addEventListener('click', attempt);
		actions.appendChild(startBtn);

		[nameInput, emailInput, phoneInput].forEach(function (input) {
			input.addEventListener('keydown', function (event) {
				if (event.key === 'Enter') {
					event.preventDefault();
					attempt();
				}
			});
		});

		box.appendChild(actions);
		body.appendChild(box);
		wrap.appendChild(body);
		els.log.appendChild(wrap);
		els.contactGate = wrap;
		scrollToEnd();
		try {
			nameInput.focus();
		} catch (e) { /* not focusable yet: harmless */ }
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

	// --- HUMAN HANDOFF (STAFF INBOX) --- //
	// Unrelated to the WhatsApp escape hatch above: this is the in-widget
	// "talk to a team member" flow (contact capture + polled agent replies).
	// Built once, hidden by default — a production visitor, where
	// handoffEnabled never becomes true, never sees this row change state.

	/**
	 * Persistent footer control. Hidden until GET /session confirms
	 * handoffEnabled; toggled by setHumanHandoffButtonVisible() from then on
	 * (hidden again once a handoff is actually raised, so there is no way to
	 * open two contact steps at once).
	 */
	function buildHumanHandoffRow() {
		var row = el('div', 'db-chat-human-handoff');
		row.hidden = true;

		var btn = el('button', 'db-chat-human-handoff-btn', 'Talk to a human');
		btn.type = 'button';
		btn.addEventListener('click', function () {
			openContactStep();
		});
		row.appendChild(btn);

		els.humanHandoffRow = row;
		els.humanHandoffBtn = btn;
		return row;
	}

	/**
	 * Show/hide the footer button. Always false while handoffEnabled is
	 * false, so nothing here can un-hide it before /session has said the
	 * feature is really on.
	 */
	function setHumanHandoffButtonVisible(visible) {
		if (!els.humanHandoffRow) {
			return;
		}
		els.humanHandoffRow.hidden = !handoffEnabled || !visible;
	}

	/**
	 * The mirror of the human button: lets a customer who is waiting for / with
	 * a person hand THEMSELVES back to the AI assistant, no staff action
	 * needed. Built once, hidden by default; only ever shown while the
	 * conversation is waiting/human.
	 */
	function buildBotHandoffRow() {
		var row = el('div', 'db-chat-human-handoff db-chat-bot-handoff');
		row.hidden = true;

		var btn = el('button', 'db-chat-human-handoff-btn', 'Talk to the AI assistant');
		btn.type = 'button';
		btn.addEventListener('click', function () {
			// Real user gesture: keep the notification audio primed too.
			primeAudioContext();
			submitBackToBot();
		});
		row.appendChild(btn);

		els.botHandoffRow = row;
		els.botHandoffBtn = btn;
		return row;
	}

	/** Show/hide the "back to AI" button. Always false while handoffEnabled is false. */
	function setBotHandoffButtonVisible(visible) {
		if (!els.botHandoffRow) {
			return;
		}
		els.botHandoffRow.hidden = !handoffEnabled || !visible;
	}

	/**
	 * Customer-initiated return to the bot. POST /handoff/cancel flips the
	 * conversation back to `bot` server-side (see class-handoff-endpoint.php);
	 * locally we stop polling, restore the "Talk to a human" button, and let
	 * the next message go through the normal AI reply path. Same conversation
	 * id — this is not a new chat.
	 */
	function submitBackToBot() {
		if (!handoffEnabled || !conversationId) {
			return;
		}
		fetchNonce(false).then(function (nonce) {
			return fetchWithTimeout(apiUrl('handoff/cancel'), {
				method: 'POST',
				credentials: 'same-origin',
				headers: authHeaders(nonce, true),
				body: JSON.stringify({ conversation_id: conversationId })
			}, REQUEST_TIMEOUT);
		}).then(function (response) {
			return response.ok ? response.json() : null;
		}).then(function (d) {
			if (!d) {
				addNotice(TEXT.network, true);
				return;
			}
			stopPolling();
			currentStatus = 'bot';
			setBotHandoffButtonVisible(false);
			setHumanHandoffButtonVisible(true);
			renderSystemNote('You are back with the AI assistant. How can I help?');
		}).catch(function () {
			addNotice(TEXT.network, true);
		});
	}

	/**
	 * Inline "Want me to bring in a team member?" confirm, rendered into the
	 * transcript when a /chat response carries `escalate === true`. Same
	 * message-row shape as a chips row (spacer + body), so it lines up under
	 * the reply it belongs to.
	 */
	function addEscalatePrompt() {
		if (!handoffEnabled) {
			return;
		}

		var wrap = el('div', 'db-chat-msg db-chat-msg-bot');
		wrap.appendChild(el('span', 'db-chat-msg-spacer'));
		var body = el('div', 'db-chat-msg-body');

		var box = el('div', 'db-chat-escalate');
		box.appendChild(el('p', 'db-chat-escalate-text', 'Want me to bring in a team member?'));
		var confirm = el('button', 'db-chat-escalate-btn', 'Yes, connect me');
		confirm.type = 'button';
		confirm.addEventListener('click', function () {
			box.remove();
			openContactStep();
		});
		box.appendChild(confirm);

		body.appendChild(box);
		wrap.appendChild(body);
		els.log.appendChild(wrap);
		scrollToEnd();
	}

	/**
	 * Inline cart-change CONFIRM CARD, rendered into the transcript when a
	 * `/chat` response carries a `proposal` object (Phase 2 cart tools —
	 * propose/commit). Same message-row shape as the escalate prompt above
	 * (spacer + body), and reuses its `.db-chat-escalate` box + button
	 * markup rather than inventing new CSS, plus the two-button row from the
	 * contact step (`.db-chat-contact-actions` / `.db-chat-contact-cancel`)
	 * for the "No" side.
	 *
	 * The Yes/No buttons send their OWN VISIBLE TEXT as the next chat
	 * message — never a hidden value — exactly like buildChip() sends a
	 * chip's own label. That is what lets the server's DB_Chat_Confirm read
	 * the customer's literal words ("Yes, add it" / "No") for the commit
	 * gate; a button that displayed one string but sent another would show
	 * the transcript lying about what the customer said.
	 *
	 * Defensive: a missing/malformed `proposal`, or one with no name or an
	 * action outside add/update/remove, renders nothing.
	 */
	function addCartConfirm(proposal) {
		if (!proposal || typeof proposal !== 'object') {
			return;
		}
		if (typeof proposal.name !== 'string' || !proposal.name.trim()) {
			return;
		}
		var action = proposal.action;
		if (action !== 'add' && action !== 'update' && action !== 'remove') {
			return;
		}

		var name = proposal.name.trim();
		var label = typeof proposal.label === 'string' ? proposal.label.trim() : '';
		var price = typeof proposal.price === 'string' ? proposal.price.trim() : '';
		var qty = typeof proposal.qty === 'number' && proposal.qty >= 0 && Math.floor(proposal.qty) === proposal.qty
			? proposal.qty
			: 0;
		var proposalId = typeof proposal.proposal_id === 'string' ? proposal.proposal_id : '';

		var itemText = name + (label ? ' (' + label + ')' : '');
		var summary;
		var yesLabel;

		if (action === 'add') {
			summary = 'Add ' + (qty > 0 ? qty : 1) + ' × ' + itemText + (price ? ' — ' + price : '') + '?';
			yesLabel = 'Yes, add it';
		} else if (action === 'update') {
			summary = 'Update ' + itemText + ' to qty ' + qty + (price ? ' (' + price + ')' : '') + '?';
			yesLabel = 'Yes, update it';
		} else {
			summary = 'Remove ' + itemText + ' from your cart?';
			yesLabel = 'Yes, remove it';
		}

		var wrap = el('div', 'db-chat-msg db-chat-msg-bot');
		wrap.appendChild(el('span', 'db-chat-msg-spacer'));
		var body = el('div', 'db-chat-msg-body');

		var box = el('div', 'db-chat-escalate');
		box.appendChild(el('p', 'db-chat-escalate-text', summary));

		var actions = el('div', 'db-chat-contact-actions');

		var yes = el('button', 'db-chat-escalate-btn', yesLabel);
		yes.type = 'button';
		yes.addEventListener('click', function () {
			box.remove();
			// The literal button text IS the message (same rule as chips). The
			// structured signal routes the turn to a deterministic commit of
			// THIS exact staged proposal — the server still re-checks the staged
			// proposal and the literal message before it writes anything.
			send(yesLabel, { cart_confirm: { decision: 'yes', proposal_id: proposalId } });
		});
		actions.appendChild(yes);

		var no = el('button', 'db-chat-contact-cancel', 'No');
		no.type = 'button';
		no.addEventListener('click', function () {
			box.remove();
			send('No', { cart_confirm: { decision: 'no', proposal_id: proposalId } });
		});
		actions.appendChild(no);

		box.appendChild(actions);
		body.appendChild(box);
		wrap.appendChild(body);
		els.log.appendChild(wrap);
		scrollToEnd();
	}

	/**
	 * Size picker: tappable size buttons for a variable product the customer
	 * chose with "This one". Tapping one routes a structured `size_pick` signal
	 * (product_id + variation_id) that the server turns into an add-to-cart
	 * confirm. Rendered via `el()`/textContent only; never persisted.
	 */
	function addSizePicker(sizePicker) {
		if (!sizePicker || typeof sizePicker !== 'object') {
			return;
		}
		var productId = parseInt(sizePicker.product_id, 10);
		var sizes = Array.isArray(sizePicker.sizes) ? sizePicker.sizes : null;
		if (!productId || !sizes || !sizes.length) {
			return;
		}

		var wrap = el('div', 'db-chat-msg db-chat-msg-bot');
		wrap.appendChild(el('span', 'db-chat-msg-spacer'));
		var body = el('div', 'db-chat-msg-body');
		var box = el('div', 'db-chat-escalate');

		var name = typeof sizePicker.name === 'string' ? sizePicker.name.trim() : '';
		if (name) {
			box.appendChild(el('p', 'db-chat-escalate-text', name));
		}

		var actions = el('div', 'db-chat-size-actions');
		var count = 0;
		sizes.forEach(function (s) {
			if (count >= 12 || !s || typeof s !== 'object') {
				return;
			}
			var vid = parseInt(s.variation_id, 10);
			var label = typeof s.label === 'string' ? s.label.trim() : '';
			if (!vid || !label) {
				return;
			}
			var btn = el('button', 'db-chat-size-btn', label);
			btn.type = 'button';
			btn.addEventListener('click', function () {
				send(label, { size_pick: { product_id: productId, variation_id: vid } });
			});
			actions.appendChild(btn);
			count += 1;
		});
		if (!count) {
			return;
		}

		box.appendChild(actions);
		body.appendChild(box);
		wrap.appendChild(body);
		els.log.appendChild(wrap);
		scrollToEnd();
	}

	/**
	 * PHASE 3 CHECKOUT — response keys `checkout` / `checkout_link` (see
	 * task-7-brief). Nothing here is persisted to sessionStorage: `checkout`,
	 * `checkout_link`, the form field values and `address_line` never touch
	 * `transcript`/`saveState` (mirrors the Phase 2 `proposal`/`cart` rule —
	 * see `handleResponse`/`saveState`). A stale checkout step must never
	 * reappear after a reload.
	 *
	 * `checkout = { mode: 'guest'|'logged_in', needs: 'address'|'', summary }`
	 * `checkout_link = { url }`
	 */

	/**
	 * Guest hybrid-capture form: first/last name, phone, email, address_1,
	 * city, postcode, country, plus Send/Cancel — same markup/approach as
	 * `openContactStep`/`submitHandoff`. Field VALUES go ONLY into the
	 * `checkout_details` extra on `send()`; the message text sent alongside
	 * is always the neutral string below, so no address text ever lands in
	 * the visible transcript or the `history` array POSTed back to the
	 * server. Guarded against opening twice at once.
	 */
	function openCheckoutForm() {
		if (els.checkoutStep) {
			return;
		}

		var wrap = el('div', 'db-chat-msg db-chat-msg-bot');
		wrap.appendChild(el('span', 'db-chat-msg-spacer'));
		var body = el('div', 'db-chat-msg-body');

		var box = el('div', 'db-chat-contact-step');
		box.appendChild(el('p', 'db-chat-contact-label', 'Where should we deliver your order?'));

		var fields = [
			{ key: 'first_name', placeholder: 'First name', type: 'text' },
			{ key: 'last_name', placeholder: 'Last name', type: 'text' },
			{ key: 'phone', placeholder: 'Phone number', type: 'text' },
			{ key: 'email', placeholder: 'you@example.com', type: 'email' },
			{ key: 'address_1', placeholder: 'Street address', type: 'text' },
			{ key: 'city', placeholder: 'City', type: 'text' },
			{ key: 'state', placeholder: 'State / region (e.g. Johor)', type: 'text' },
			{ key: 'postcode', placeholder: 'Postcode', type: 'text' },
			{ key: 'country', placeholder: 'Country', type: 'text' }
		];
		// Store-scoped delivery countries for the dropdown: /my = Malaysia only,
		// /gb = worldwide except Malaysia (see checkout_countries_for_store()).
		// Fall back to Malaysia so the form always works.
		var checkoutCountries = (cfg.checkoutCountries && typeof cfg.checkoutCountries === 'object' && Object.keys(cfg.checkoutCountries).length)
			? cfg.checkoutCountries
			: { MY: 'Malaysia' };

		var inputs = {};
		fields.forEach(function (field) {
			var input;
			if (field.key === 'country') {
				// Country is a DROPDOWN, not free text — the customer picks from
				// the countries this store actually ships to.
				input = document.createElement('select');
				input.className = 'db-chat-contact-input db-chat-contact-select';
				var codes = Object.keys(checkoutCountries);
				if (codes.length > 1) {
					var ph = document.createElement('option');
					ph.value = '';
					ph.textContent = 'Select your country';
					ph.disabled = true;
					ph.selected = true;
					input.appendChild(ph);
				}
				codes.forEach(function (code) {
					var opt = document.createElement('option');
					opt.value = code;
					opt.textContent = checkoutCountries[code];
					input.appendChild(opt);
				});
			} else {
				input = document.createElement('input');
				input.type = field.type;
				input.setAttribute('placeholder', field.placeholder);
				input.setAttribute('autocomplete', 'off');
				input.className = 'db-chat-contact-input';
			}
			box.appendChild(input);
			inputs[field.key] = input;
		});

		var actions = el('div', 'db-chat-contact-actions');
		var sendBtn = el('button', 'db-chat-contact-send', 'Send');
		sendBtn.type = 'button';
		var cancelBtn = el('button', 'db-chat-contact-cancel', 'Cancel');
		cancelBtn.type = 'button';

		function close() {
			wrap.remove();
			els.checkoutStep = null;
		}

		function attemptSend() {
			var details = {};
			fields.forEach(function (field) {
				details[field.key] = trimmed(inputs[field.key].value);
			});
			sendBtn.disabled = true;
			close();
			// Neutral message text ONLY — see the function doc comment above.
			// The actual address travels solely in `checkout_details`.
			send('Here are my delivery details', { checkout_details: details });
		}

		sendBtn.addEventListener('click', attemptSend);
		cancelBtn.addEventListener('click', function () {
			close();
			send('I’ll enter my delivery details later');
		});
		Object.keys(inputs).forEach(function (key) {
			inputs[key].addEventListener('keydown', function (event) {
				if (event.key === 'Enter') {
					event.preventDefault();
					attemptSend();
				}
			});
		});

		actions.appendChild(sendBtn);
		actions.appendChild(cancelBtn);
		box.appendChild(actions);

		body.appendChild(box);
		wrap.appendChild(body);
		els.log.appendChild(wrap);
		els.checkoutStep = wrap;
		scrollToEnd();
		try {
			inputs.first_name.focus();
		} catch (e) { /* not focusable yet: harmless */ }
	}

	/**
	 * Logged-in tap-confirm: "Deliver to <address_line>?" with a ✓ (ask the
	 * server to use the saved address — no PII in the message itself) and a
	 * ✗ (ask to use a different one, also no PII). `addressLine` is rendered
	 * with `el()`/textContent only, exactly like every other server string.
	 */
	function addCheckoutConfirm(addressLine) {
		var text = 'Deliver to ' + (addressLine ? addressLine : 'your saved address') + '?';

		var wrap = el('div', 'db-chat-msg db-chat-msg-bot');
		wrap.appendChild(el('span', 'db-chat-msg-spacer'));
		var body = el('div', 'db-chat-msg-body');

		var box = el('div', 'db-chat-escalate');
		box.appendChild(el('p', 'db-chat-escalate-text', text));

		var actions = el('div', 'db-chat-contact-actions');

		var yes = el('button', 'db-chat-escalate-btn', '✓ Yes, use it');
		yes.type = 'button';
		yes.addEventListener('click', function () {
			box.remove();
			send('Yes, use my saved address', { checkout_confirm_address: true });
		});
		actions.appendChild(yes);

		var no = el('button', 'db-chat-contact-cancel', '✗ Use a different address');
		no.type = 'button';
		no.addEventListener('click', function () {
			box.remove();
			send('I’d like to use a different address');
		});
		actions.appendChild(no);

		box.appendChild(actions);
		body.appendChild(box);
		wrap.appendChild(body);
		els.log.appendChild(wrap);
		scrollToEnd();
	}

	/**
	 * Itemised checkout SUMMARY card: each item (name, label, qty,
	 * line_price — same row builder as the cart summary), shipping, total,
	 * and the delivery address, with an "all correct?" line. Rendered via
	 * `el()`/textContent only; never written to `transcript`/`saveState`.
	 */
	function addCheckoutSummary(summary) {
		if (!summary || typeof summary !== 'object') {
			return;
		}
		var items = Array.isArray(summary.items) ? summary.items : null;

		var wrap = el('div', 'db-chat-msg db-chat-msg-bot');
		wrap.appendChild(el('span', 'db-chat-msg-spacer'));
		var body = el('div', 'db-chat-msg-body');
		var rendered = false;

		if (items) {
			var list = el('ul', 'db-chat-products');
			list.setAttribute('aria-label', 'Order summary');
			var count = 0;
			items.forEach(function (item) {
				if (count >= MAX_CART_ITEMS) {
					return;
				}
				if (!item || typeof item !== 'object') {
					return;
				}
				if (typeof item.name !== 'string' || !item.name.trim()) {
					return;
				}
				var qty = typeof item.qty === 'number' && item.qty > 0 && Math.floor(item.qty) === item.qty
					? item.qty
					: 0;
				if (!qty) {
					return;
				}
				list.appendChild(buildCartItemRow(item, qty));
				count += 1;
			});
			if (count) {
				body.appendChild(list);
				rendered = true;
			}
		}

		if (typeof summary.shipping === 'string' && summary.shipping.trim()) {
			body.appendChild(el('p', 'db-chat-product-price', 'Shipping: ' + summary.shipping.trim()));
			rendered = true;
		}
		if (typeof summary.total === 'string' && summary.total.trim()) {
			body.appendChild(el('p', 'db-chat-product-price', 'Total: ' + summary.total.trim()));
			rendered = true;
		}
		if (typeof summary.address_line === 'string' && summary.address_line.trim()) {
			body.appendChild(el('p', 'db-chat-escalate-text', 'Deliver to: ' + summary.address_line.trim()));
			rendered = true;
		}

		if (!rendered) {
			return;
		}

		body.appendChild(el('p', 'db-chat-escalate-text', 'Is everything above correct?'));

		// Let the customer fix or change their delivery address — a wrong
		// address, or a different country — instead of being stuck with whatever
		// was captured. Re-opens the same address form. Only shown once an
		// address is on the summary (the confirm step).
		if (typeof summary.address_line === 'string' && summary.address_line.trim()) {
			var changeBtn = el('button', 'db-chat-checkout-change', 'Change delivery address');
			changeBtn.type = 'button';
			changeBtn.addEventListener('click', function () {
				openCheckoutForm();
			});
			body.appendChild(changeBtn);
		}

		wrap.appendChild(body);
		els.log.appendChild(wrap);
		scrollToEnd();
	}

	/**
	 * "Proceed to payment" — the terminal action of the checkout flow. A
	 * plain same-tab link (no `target`) to the WooCommerce checkout page, so
	 * back/forward behaves normally. No auto-advance: the customer taps it.
	 */
	function addCheckoutLink(checkoutLink) {
		if (!checkoutLink || typeof checkoutLink !== 'object') {
			return;
		}
		var href = safeUrl(checkoutLink.url);
		if (!href) {
			return;
		}

		var wrap = el('div', 'db-chat-msg db-chat-msg-bot');
		wrap.appendChild(el('span', 'db-chat-msg-spacer'));
		var body = el('div', 'db-chat-msg-body');

		var link = el('a', 'db-chat-escalate-btn', 'Proceed to payment');
		link.setAttribute('href', href);
		body.appendChild(link);

		wrap.appendChild(body);
		els.log.appendChild(wrap);
		scrollToEnd();
	}

	/**
	 * Dispatches the `checkout` response key to the right render path
	 * (RULING A shape — see task-7-brief): the guest form, the logged-in
	 * tap-confirm, and/or the summary card. `checkout_link` is handled
	 * separately by `addCheckoutLink()`.
	 */
	function addCheckoutStep(checkout, hasLink) {
		if (!checkout || typeof checkout !== 'object') {
			return;
		}
		// Empty cart: the server refuses to build a summary or a payment link, so
		// never render a form, a summary card, or a confirm button. Say so plainly
		// instead of showing an RM0.00 "proceed to payment".
		if (checkout.needs === 'cart') {
			renderSystemNote('Your cart is empty — add an item and I can take you to checkout.');
			return;
		}
		if (checkout.needs === 'address') {
			if (checkout.mode === 'guest') {
				openCheckoutForm();
			} else if (checkout.mode === 'logged_in') {
				var addressLine = checkout.summary && typeof checkout.summary.address_line === 'string'
					? checkout.summary.address_line.trim()
					: '';
				addCheckoutConfirm(addressLine);
			}
		}
		addCheckoutSummary(checkout.summary);

		// Address is complete and we are not already at the payment link: this
		// is the confirm step. Offer an explicit button, and flag that a typed
		// reply now should be read as the confirmation.
		if (checkout.needs !== 'address' && !hasLink && checkout.summary) {
			addCheckoutConfirmButton();
			awaitingCheckoutConfirm = true;
		}
	}

	/**
	 * The checkout summary's explicit confirm control. Tapping it proceeds to
	 * payment deterministically (server returns the checkout link); the server
	 * never creates an order or takes payment — the customer completes that on
	 * the WooCommerce checkout page.
	 */
	function addCheckoutConfirmButton() {
		var wrap = el('div', 'db-chat-msg db-chat-msg-bot');
		wrap.appendChild(el('span', 'db-chat-msg-spacer'));
		var body = el('div', 'db-chat-msg-body');

		var btn = el('button', 'db-chat-escalate-btn', 'Confirm & proceed to payment');
		btn.type = 'button';
		btn.addEventListener('click', function () {
			awaitingCheckoutConfirm = false;
			send('Yes, everything is correct', { checkout_confirm: true });
		});

		body.appendChild(btn);
		wrap.appendChild(body);
		els.log.appendChild(wrap);
		scrollToEnd();
	}

	/**
	 * Contact-capture step: Name (optional), Email (REQUIRED), Phone
	 * (optional), plus Send/Cancel, appended to the transcript. Send
	 * validates the email client-side before POSTing /handoff; Cancel closes
	 * the step and returns to normal chat WITHOUT calling /handoff at all.
	 * Guarded against opening twice at once.
	 */
	function openContactStep() {
		if (!handoffEnabled || els.contactStep) {
			return;
		}

		var wrap = el('div', 'db-chat-msg db-chat-msg-bot');
		wrap.appendChild(el('span', 'db-chat-msg-spacer'));
		var body = el('div', 'db-chat-msg-body');

		var box = el('div', 'db-chat-contact-step');
		box.appendChild(el('p', 'db-chat-contact-label', 'Leave your details so we can reply if we miss you (email required)'));

		var nameInput = document.createElement('input');
		nameInput.type = 'text';
		nameInput.className = 'db-chat-contact-input db-chat-contact-name';
		nameInput.setAttribute('placeholder', 'Your name');
		nameInput.setAttribute('autocomplete', 'off');
		box.appendChild(nameInput);

		var emailInput = document.createElement('input');
		emailInput.type = 'email';
		emailInput.className = 'db-chat-contact-input db-chat-contact-email';
		emailInput.setAttribute('placeholder', 'you@example.com');
		emailInput.setAttribute('autocomplete', 'off');
		box.appendChild(emailInput);

		var emailError = el('p', 'db-chat-contact-error', '');
		emailError.setAttribute('role', 'alert');
		emailError.hidden = true;
		box.appendChild(emailError);

		var phoneInput = document.createElement('input');
		phoneInput.type = 'text';
		phoneInput.className = 'db-chat-contact-input db-chat-contact-phone';
		phoneInput.setAttribute('placeholder', 'Phone number');
		phoneInput.setAttribute('autocomplete', 'off');
		box.appendChild(phoneInput);

		function showEmailError(text) {
			emailError.textContent = text;
			emailError.hidden = false;
			emailInput.classList.add('is-invalid');
		}
		function clearEmailError() {
			if (emailError.hidden) {
				return;
			}
			emailError.hidden = true;
			emailError.textContent = '';
			emailInput.classList.remove('is-invalid');
		}
		emailInput.addEventListener('input', clearEmailError);

		var actions = el('div', 'db-chat-contact-actions');
		var sendBtn = el('button', 'db-chat-contact-send', 'Send');
		sendBtn.type = 'button';
		var cancelBtn = el('button', 'db-chat-contact-cancel', 'Cancel');
		cancelBtn.type = 'button';

		function close() {
			wrap.remove();
			els.contactStep = null;
		}

		function attemptSend() {
			var email = trimmed(emailInput.value);
			if (!email || !EMAIL_RE.test(email)) {
				showEmailError('Please enter a valid email so we can reply.');
				try {
					emailInput.focus();
				} catch (e) { /* not focusable: harmless */ }
				return;
			}
			clearEmailError();
			sendBtn.disabled = true;
			submitHandoff({
				name: trimmed(nameInput.value),
				email: email,
				phone: trimmed(phoneInput.value)
			}, {
				onInvalidEmail: function () {
					sendBtn.disabled = false;
					showEmailError('Please enter a valid email so we can reply.');
					try {
						emailInput.focus();
					} catch (e) { /* not focusable: harmless */ }
				},
				onSuccess: close,
				onOtherFailure: function () {
					sendBtn.disabled = false;
				}
			});
		}

		sendBtn.addEventListener('click', attemptSend);
		cancelBtn.addEventListener('click', close);
		[nameInput, emailInput, phoneInput].forEach(function (input) {
			input.addEventListener('keydown', function (event) {
				if (event.key === 'Enter') {
					event.preventDefault();
					attemptSend();
				}
			});
		});

		actions.appendChild(sendBtn);
		actions.appendChild(cancelBtn);
		box.appendChild(actions);

		body.appendChild(box);
		wrap.appendChild(body);
		els.log.appendChild(wrap);
		els.contactStep = wrap;
		scrollToEnd();
		try {
			nameInput.focus();
		} catch (e) { /* not focusable yet: harmless */ }
	}

	/**
	 * POST /handoff with the captured contact details. Same header discipline
	 * as /chat (authHeaders, same nonce, same token header, same credentials
	 * mode). On success the conversation is with a human: stop offering the
	 * button, show the confirmation line, and start polling for replies. A
	 * 400 db_chat_email_required (the backend re-validating the same rule)
	 * routes back to the caller's onInvalidEmail rather than the generic
	 * network-failure notice.
	 */
	function submitHandoff(contact, callbacks) {
		callbacks = callbacks || {};
		if (!handoffEnabled || !conversationId) {
			return;
		}

		// Called synchronously from the Send button's click handler: a real
		// user gesture, so this is where the notification AudioContext gets
		// primed for the polling that submitHandoff() itself starts below.
		primeAudioContext();

		var body = { conversation_id: conversationId };
		if (contact) {
			body.contact_name = contact.name || '';
			body.contact_email = contact.email || '';
			body.contact_phone = contact.phone || '';
		}

		fetchNonce(false).then(function (nonce) {
			return fetchWithTimeout(apiUrl('handoff'), {
				method: 'POST',
				credentials: 'same-origin',
				headers: authHeaders(nonce, true),
				body: JSON.stringify(body)
			}, REQUEST_TIMEOUT);
		}).then(function (response) {
			return response.json().catch(function () {
				return null;
			}).then(function (data) {
				return { ok: response.ok, data: data };
			});
		}).then(function (result) {
			if (!result.ok) {
				var code = result.data && (result.data.code || (result.data.data && result.data.data.code));
				if ('db_chat_email_required' === code) {
					if (typeof callbacks.onInvalidEmail === 'function') {
						callbacks.onInvalidEmail();
					}
					return;
				}
				throw new Error((result.data && result.data.message) || 'handoff_failed');
			}
			var data = result.data;
			currentStatus = data && typeof data.status === 'string' ? data.status : 'waiting';
			setHumanHandoffButtonVisible(false);
			setBotHandoffButtonVisible(true);
			renderSystemNote('A team member will reply here shortly.');
			startPolling();
			if (typeof callbacks.onSuccess === 'function') {
				callbacks.onSuccess();
			}
		}).catch(function () {
			// Transient failure: leave the button available so the customer
			// can simply try again rather than land on a dead end.
			setHumanHandoffButtonVisible(true);
			setBotHandoffButtonVisible(false);
			addNotice(TEXT.network, true);
			if (typeof callbacks.onOtherFailure === 'function') {
				callbacks.onOtherFailure();
			}
		});
	}

	/** Centered, subtle system line — used for both the local confirmation
	 *  above and any `system`-sender message that comes back from polling. */
	function renderSystemNote(text) {
		if (typeof text !== 'string' || !text.trim()) {
			return;
		}
		var note = el('p', 'db-chat-system-note', text.trim());
		note.setAttribute('role', 'status');
		els.log.appendChild(note);
		scrollToEnd();
	}

	/**
	 * Create (or resume) the notification AudioContext. MUST be called
	 * synchronously from inside a real user-gesture handler — browsers block
	 * audio otherwise. The customer has always interacted by the time either
	 * caller runs (opened the widget / submitted the handoff form), so
	 * calling this at the top of openPanel() and submitHandoff() is enough to
	 * keep the context alive for the poll-driven tones that follow later.
	 * Best-effort: never throws, never blocks the caller.
	 */
	function primeAudioContext() {
		try {
			var Ctx = window.AudioContext || window.webkitAudioContext;
			if (!Ctx) {
				return;
			}
			if (!audioCtx) {
				audioCtx = new Ctx();
			}
			if ('suspended' === audioCtx.state && audioCtx.resume) {
				audioCtx.resume();
			}
		} catch (e) { /* audio is best-effort only */ }
	}

	/**
	 * Subtle single-tone chime for a new inbound team/system message. Quieter
	 * and shorter than the staff inbox's alert beep on purpose — this plays
	 * for a customer, not a notification the admin needs to act on. No-op if
	 * primeAudioContext() was never able to create a context (no gesture yet,
	 * unsupported browser, blocked autoplay).
	 */
	function playInboundTone() {
		try {
			if (!audioCtx) {
				return;
			}
			var schedule = function () {
				try {
					var ctx = audioCtx;
					var now = ctx.currentTime;
					var osc = ctx.createOscillator();
					var gain = ctx.createGain();
					osc.type = 'sine';
					osc.frequency.setValueAtTime(660, now);
					gain.gain.setValueAtTime(0.0001, now);
					gain.gain.exponentialRampToValueAtTime(0.22, now + 0.01);
					gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.18);
					osc.connect(gain);
					gain.connect(ctx.destination);
					osc.start(now);
					osc.stop(now + 0.2);
				} catch (e) { /* best-effort */ }
			};
			// resume() is async: a context that slipped back to 'suspended'
			// between the priming gesture and this poll would silently drop a
			// tone scheduled immediately, so wait for the resume to resolve.
			if ('suspended' === audioCtx.state && audioCtx.resume) {
				audioCtx.resume().then(schedule).catch(function () {});
			} else {
				schedule();
			}
		} catch (e) { /* audio is best-effort only */ }
	}

	/**
	 * `agent` → a "Team" bubble, reusing the same avatar/body/caption shape
	 * as a bot message so it sits visually consistent with the rest of the
	 * transcript. `system` → the subtle centered note. Anything else, or an
	 * empty body, renders nothing.
	 *
	 * @param {object}  m              Message row from GET /messages.
	 * @param {boolean} suppressSound  True on the first poll round after
	 *                                 (re)starting polling — that round is a
	 *                                 history backfill, not a new arrival, and
	 *                                 must render silently.
	 */
	function renderTeamMessage(m, suppressSound) {
		if (!m || typeof m !== 'object') {
			return;
		}
		var body = typeof m.body === 'string' ? m.body.trim() : '';
		if (!body) {
			return;
		}
		if (m.sender === 'system') {
			renderSystemNote(body);
			if (!suppressSound) {
				playInboundTone();
			}
			return;
		}
		if (m.sender !== 'agent') {
			return;
		}

		var wrap = el('div', 'db-chat-msg db-chat-msg-bot db-chat-msg-agent');
		wrap.appendChild(buildAgentAvatar('db-chat-msg-avatar'));
		var msgBody = el('div', 'db-chat-msg-body');
		msgBody.appendChild(el('div', 'db-chat-bubble db-chat-bubble-agent', body));
		msgBody.appendChild(el('p', 'db-chat-caption', 'Team'));
		wrap.appendChild(msgBody);
		els.log.appendChild(wrap);
		scrollToEnd();
		if (!suppressSound) {
			playInboundTone();
		}
	}

	/**
	 * Poll GET /messages while the panel is open and a human is (or might
	 * soon be) on the other end. Interval length comes from the backend
	 * (`handoff.pollSeconds`, floored at 2s client-side too, matching the
	 * brief's own floor). Stops on: resolved, panel close, or ~10 minutes of
	 * no activity — never runs during normal bot chat.
	 */
	function startPolling() {
		if (pollTimer || !handoffEnabled || !conversationId) {
			return;
		}
		// The next fetchNewMessages() round is a backfill (history that was
		// already sitting on the server, e.g. `since=0` on first load): it
		// must render without a sound. See pollBackfilled's declaration.
		pollBackfilled = true;
		var ms = Math.max(2000, (pollSeconds || 5) * 1000);
		pollTimer = window.setInterval(fetchNewMessages, ms);
		armPollIdleTimeout();
		// setInterval alone does not fire until the first tick elapses, so a
		// reply (or an assignment note) already sitting on the server the
		// moment handoff completes would sit unseen for a full pollSeconds.
		// Poll once immediately too.
		fetchNewMessages();
	}

	function stopPolling() {
		if (pollTimer) {
			window.clearInterval(pollTimer);
			pollTimer = null;
		}
		if (pollIdleTimer) {
			window.clearTimeout(pollIdleTimer);
			pollIdleTimer = null;
		}
	}

	function armPollIdleTimeout() {
		if (pollIdleTimer) {
			window.clearTimeout(pollIdleTimer);
		}
		pollIdleTimer = window.setTimeout(stopPolling, POLL_IDLE_MS);
	}

	function fetchNewMessages() {
		if (!isOpen || !handoffEnabled || !conversationId) {
			return;
		}
		armPollIdleTimeout();

		// Cache-buster + no-store: while waiting for the next reply the `since`
		// value is unchanged, so this is a static URL polled every few seconds.
		// A cache layer serving a stale copy would hide the agent's reply until
		// a manual refresh, so force a fresh fetch every time.
		var url = apiUrl('messages') + '?conversation_id=' + encodeURIComponent(conversationId) + '&since=' + lastMessageId + '&_ts=' + Date.now();
		fetchNonce(false).then(function (nonce) {
			return fetchWithTimeout(url, {
				method: 'GET',
				cache: 'no-store',
				credentials: 'same-origin',
				headers: authHeaders(nonce, false)
			}, REQUEST_TIMEOUT);
		}).then(function (response) {
			return response.ok ? response.json() : null;
		}).then(function (d) {
			if (!d) {
				return;
			}
			// Only the FIRST round after (re)starting polling is a backfill;
			// flip it before rendering so a message added by a retry of this
			// very round is never silently miscounted as backfill again.
			var isBackfill = pollBackfilled;
			pollBackfilled = false;
			(Array.isArray(d.messages) ? d.messages : []).forEach(function (m) {
				renderTeamMessage(m, isBackfill);
				var mid = parseInt(m && m.id, 10);
				if (!isNaN(mid) && mid > lastMessageId) {
					lastMessageId = mid;
				}
			});
			if (typeof d.status !== 'string') {
				return;
			}
			var previousStatus = currentStatus;
			currentStatus = d.status;

			if (currentStatus === 'resolved') {
				// The conversation is over: stop polling, say so, and forget
				// it entirely — localStorage id, in-memory id, last-seen
				// message id, status — so the customer's NEXT message opens
				// a brand new conversation instead of continuing this one.
				stopPolling();
				renderSystemNote('This chat has been closed. Send a message to start a new one.');
				conversationId = null;
				lastMessageId = 0;
				currentStatus = null;
				try {
					window.localStorage.removeItem(CONVERSATION_ID_KEY);
				} catch (e) { /* storage unavailable: memory only */ }
				// Resolved is a hard clear point: wipe the saved transcript too, so
				// the closed chat does not restore on the next page or reload.
				clearSavedState();
				setHumanHandoffButtonVisible(true);
				setBotHandoffButtonVisible(false);
				return;
			}

			if (currentStatus === 'bot' && (previousStatus === 'waiting' || previousStatus === 'human')) {
				// Reassigned back to the bot: stop polling and let the next
				// message go through the normal AI reply path again. Same
				// conversation id on purpose — this is not a new chat.
				stopPolling();
				setHumanHandoffButtonVisible(true);
				setBotHandoffButtonVisible(false);
			}
		}).catch(function () { /* transient network hiccup: try again next tick */ });
	}

	/* ---- Photo attach (vision) ---- */

	// Read a picked/pasted image file, downscale it, and stage it for the next
	// send. Everything is client-side; nothing is uploaded until the customer
	// actually sends.
	function handleImageFile(file) {
		if (!visionEnabled || !file || !/^image\//.test(file.type || '')) {
			return;
		}
		var reader = new FileReader();
		reader.onload = function () {
			var img = new Image();
			img.onload = function () { stagePendingImage(img); };
			img.onerror = function () { addNotice('Could not read that image.', true); };
			img.src = reader.result;
		};
		reader.onerror = function () { addNotice('Could not read that image.', true); };
		reader.readAsDataURL(file);
	}

	// Downscale to a sane size and re-encode as JPEG, so the payload stays
	// small and one code path (image/jpeg) reaches the server.
	function stagePendingImage(img) {
		var maxDim = 1536;
		var w = img.naturalWidth || img.width;
		var h = img.naturalHeight || img.height;
		if (!w || !h) {
			addNotice('Could not read that image.', true);
			return;
		}
		var scale = Math.min(1, maxDim / Math.max(w, h));
		var cw = Math.max(1, Math.round(w * scale));
		var ch = Math.max(1, Math.round(h * scale));

		var canvas = document.createElement('canvas');
		canvas.width = cw;
		canvas.height = ch;
		var ctx = canvas.getContext('2d');
		if (!ctx) {
			addNotice('Could not process that image.', true);
			return;
		}
		// White backfill so a transparent PNG doesn't turn black under JPEG.
		ctx.fillStyle = '#ffffff';
		ctx.fillRect(0, 0, cw, ch);
		ctx.drawImage(img, 0, 0, cw, ch);

		var dataUrl;
		try {
			dataUrl = canvas.toDataURL('image/jpeg', 0.85);
		} catch (e) {
			addNotice('Could not process that image.', true);
			return;
		}
		var comma = dataUrl.indexOf(',');
		if (comma < 0) {
			return;
		}
		pendingImage = { media_type: 'image/jpeg', data: dataUrl.slice(comma + 1) };
		renderImagePreview(dataUrl);
	}

	function renderImagePreview(dataUrl) {
		if (!els.imagePreview) {
			return;
		}
		els.imagePreview.innerHTML = '';

		var thumb = document.createElement('img');
		thumb.className = 'db-chat-image-thumb';
		thumb.src = dataUrl;
		thumb.alt = 'Attached photo';

		var remove = el('button', 'db-chat-image-remove', '✕');
		remove.type = 'button';
		remove.setAttribute('aria-label', 'Remove photo');
		remove.addEventListener('click', clearPendingImage);

		els.imagePreview.appendChild(thumb);
		els.imagePreview.appendChild(remove);
		els.imagePreview.hidden = false;
	}

	function clearPendingImage() {
		pendingImage = null;
		if (els.imagePreview) {
			els.imagePreview.innerHTML = '';
			els.imagePreview.hidden = true;
		}
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

		// Photo attach: a button left of the textarea, a hidden file input, a
		// preview strip above the form, and paste-an-image support. Only when
		// the vision toggle is on.
		if (visionEnabled) {
			var preview = el('div', 'db-chat-image-preview');
			preview.hidden = true;
			composer.appendChild(preview);
			els.imagePreview = preview;

			var fileInput = document.createElement('input');
			fileInput.type = 'file';
			fileInput.accept = 'image/png,image/jpeg,image/webp,image/gif';
			fileInput.className = 'db-chat-visually-hidden';
			fileInput.addEventListener('change', function () {
				if (fileInput.files && fileInput.files[0]) {
					handleImageFile(fileInput.files[0]);
				}
				fileInput.value = '';
			});

			var attachBtn = el('button', 'db-chat-attach');
			attachBtn.type = 'button';
			attachBtn.setAttribute('aria-label', 'Attach a photo');
			attachBtn.appendChild(icon(ICONS.image));
			attachBtn.addEventListener('click', function () { fileInput.click(); });

			form.insertBefore(attachBtn, input);
			form.appendChild(fileInput);

			input.addEventListener('paste', function (event) {
				if (!event.clipboardData) {
					return;
				}
				var items = event.clipboardData.items || [];
				for (var i = 0; i < items.length; i++) {
					if (items[i].type && items[i].type.indexOf('image/') === 0) {
						var file = items[i].getAsFile();
						if (file) {
							event.preventDefault();
							handleImageFile(file);
							break;
						}
					}
				}
			});
		}

		composer.appendChild(form);

		// Contextual "Want me to bring in a team member?" — the bot offers it
		// mid-conversation, so it stays OUTSIDE the collapsible and is never
		// hidden when the bot raises it.
		composer.appendChild(buildBotHandoffRow());

		// COLLAPSIBLE CONTACT & HELP. The WhatsApp link and the staff "Talk to a
		// human" button are persistent but space-hungry — together they ate
		// roughly half the panel. Tuck them behind a toggle (collapsed by
		// default) so the conversation gets the room. The bot's own handoff
		// offer above, plus this toggle, keep the escape hatch discoverable.
		var extras = el('div', 'db-chat-extras');
		extras.id = 'db-chat-extras';
		// Escape hatch to a human, above the AI disclaimer. Quiet on purpose:
		// it must not compete with the composer for attention.
		extras.appendChild(buildHandoffRow());
		// Staff-inbox "Talk to a human" control — a second, unrelated escape
		// hatch, hidden unless handoffEnabled. See HUMAN HANDOFF (STAFF INBOX)
		// above for why it is safe to always build this and just keep it hidden.
		extras.appendChild(buildHumanHandoffRow());

		var toggle = el('button', 'db-chat-extras-toggle');
		toggle.type = 'button';
		toggle.setAttribute('aria-controls', 'db-chat-extras');
		toggle.setAttribute('aria-expanded', 'false');
		toggle.appendChild(el('span', 'db-chat-extras-toggle-label', 'Contact & help'));
		toggle.appendChild(el('span', 'db-chat-extras-toggle-chevron', '▾'));
		toggle.addEventListener('click', function () {
			var open = composer.classList.toggle('db-chat-extras-open');
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			try {
				window.localStorage.setItem(EXTRAS_KEY, open ? '1' : '0');
			} catch (e) { /* storage unavailable: this tab only */ }
			if (open) {
				scrollToEnd();
			}
		});

		// Restore the viewer's last choice; collapsed by default.
		var startOpen = false;
		try {
			startOpen = window.localStorage.getItem(EXTRAS_KEY) === '1';
		} catch (e) { /* storage unavailable: default collapsed */ }
		if (startOpen) {
			composer.classList.add('db-chat-extras-open');
			toggle.setAttribute('aria-expanded', 'true');
		}

		composer.appendChild(toggle);
		composer.appendChild(extras);
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

		// Keep wheel scrolling INSIDE the panel: the conversation log scrolls,
		// and the page behind never does. On mobile the body is already
		// position:fixed-locked (see lockScroll); this covers desktop, where it
		// is not. We drive the log's own scrollTop directly rather than relying
		// on the browser to route a native wheel into the shadow-DOM log —
		// that routing is unreliable across the shadow boundary and is what let
		// the page "bleed" and swallow the conversation's own scrolling. Ctrl+
		// wheel (pinch / browser zoom) is left alone.
		els.panel.addEventListener('wheel', function (e) {
			if (e.ctrlKey) {
				return;
			}
			var log = els.log;
			if (!log) {
				return;
			}
			var delta = e.deltaY;
			if (e.deltaMode === 1) {
				delta *= 16;                 // lines → approx px
			} else if (e.deltaMode === 2) {
				delta *= log.clientHeight;   // pages → px
			}
			if (log.contains(e.target) && log.scrollHeight > log.clientHeight) {
				// Instant, not the log's CSS smooth scroll — per-tick smooth
				// animation makes wheel scrolling feel floaty and laggy.
				var target = log.scrollTop + delta;
				try {
					log.scrollTo({ top: target, behavior: 'instant' });
				} catch (err) {
					log.scrollTop = target;
				}
			}
			// The widget consumes the wheel either way, so the page stays put.
			e.preventDefault();
		}, { passive: false });

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

	function addUserMessage(text, imageUrl) {
		var wrap = el('div', 'db-chat-msg db-chat-msg-user');
		var bubble = el('div', 'db-chat-bubble');
		// Show the photo the customer just sent, so their own message isn't a
		// bare caption. Ephemeral: the image lives in the DOM for this session
		// only, never in saved state or history.
		if (imageUrl) {
			var img = document.createElement('img');
			img.className = 'db-chat-bubble-image';
			img.src = imageUrl;
			img.alt = 'Photo you sent';
			if (text) {
				img.style.marginBottom = '6px';
			}
			bubble.appendChild(img);
		}
		if (text) {
			bubble.appendChild(document.createTextNode(text));
		}
		wrap.appendChild(bubble);
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
	 * One line of the cart summary below. Reuses the product-card row
	 * markup (`.db-chat-product` / `.db-chat-product-body` /
	 * `.db-chat-product-name` / `.db-chat-product-meta` /
	 * `.db-chat-product-price`) with no thumbnail and no "This one" button —
	 * a cart line has neither an image nor a pick action, it is view-only.
	 */
	function buildCartItemRow(item, qty) {
		var row = el('li', 'db-chat-product');
		var body = el('div', 'db-chat-product-body');

		var label = typeof item.label === 'string' ? item.label.trim() : '';
		var nameText = qty + ' × ' + item.name.trim() + (label ? ' (' + label + ')' : '');
		body.appendChild(el('p', 'db-chat-product-name', nameText));

		if (typeof item.line_price === 'string' && item.line_price.trim()) {
			var meta = el('div', 'db-chat-product-meta');
			// Pre-formatted MYR string from WooCommerce, same rule as product
			// cards: never built here.
			meta.appendChild(el('span', 'db-chat-product-price', item.line_price.trim()));
			body.appendChild(meta);
		}

		row.appendChild(body);
		return row;
	}

	/**
	 * Compact cart SUMMARY, rendered into the transcript when a `/chat`
	 * response carries a `cart` object with an `items` array (from a
	 * committed change or a `view_cart` tool call — see class-chat-endpoint
	 * `handle_request()`). Reuses the product-card container/list styles
	 * (`.db-chat-products` / `.db-chat-product`) and the page-link pattern
	 * (`.db-chat-page-link`, same-tab `<a>`, same as a product card's own
	 * link) for the "View cart" link.
	 *
	 * IMPORTANT — shape guard: `cart` is NOT a new key. The older guided-buy
	 * flow (class-lookup-engine.php `complete_purchase()`) has always sent
	 * its OWN, differently-shaped `cart` object on a successful buy:
	 * `{added, url, total, count}` — no `items`. That path is handled
	 * separately above (the `data.cart.added` cart-fragment refresh) and
	 * must keep working untouched. This function only ever renders when
	 * `cart.items` is actually an array, which the legacy shape never has,
	 * so the two never collide.
	 */
	function addCartSummary(cart) {
		if (!cart || typeof cart !== 'object') {
			return;
		}
		var items = Array.isArray(cart.items) ? cart.items : null;
		if (!items) {
			return;
		}

		var list = el('ul', 'db-chat-products');
		list.setAttribute('aria-label', 'Your cart');
		var count = 0;

		items.forEach(function (item) {
			if (count >= MAX_CART_ITEMS) {
				return;
			}
			if (!item || typeof item !== 'object') {
				return;
			}
			if (typeof item.name !== 'string' || !item.name.trim()) {
				return;
			}
			var qty = typeof item.qty === 'number' && item.qty > 0 && Math.floor(item.qty) === item.qty
				? item.qty
				: 0;
			if (!qty) {
				return;
			}
			list.appendChild(buildCartItemRow(item, qty));
			count += 1;
		});

		if (!count) {
			return;
		}

		var wrap = el('div', 'db-chat-msg db-chat-msg-bot');
		// Blank spacer so the summary lines up under the bubble above it,
		// same as product cards / page links / chips.
		wrap.appendChild(el('span', 'db-chat-msg-spacer'));
		var body = el('div', 'db-chat-msg-body');
		body.appendChild(list);

		if (typeof cart.total === 'string' && cart.total.trim()) {
			body.appendChild(el('p', 'db-chat-product-price', 'Total: ' + cart.total.trim()));
		}

		// After an item is added, offer the three next steps as buttons the
		// customer can tap — Checkout, Continue shopping, View cart — instead of
		// having to type. Deterministic: Checkout drives the checkout flow, so it
		// never depends on the model reading the customer's words.
		var actions = el('div', 'db-chat-cart-actions');

		var checkoutBtn = el('button', 'db-chat-cart-action db-chat-cart-action--primary', 'Checkout');
		checkoutBtn.type = 'button';
		checkoutBtn.addEventListener('click', function () {
			actions.remove();
			send('I want to checkout');
		});
		actions.appendChild(checkoutBtn);

		var continueBtn = el('button', 'db-chat-cart-action', 'Continue shopping');
		continueBtn.type = 'button';
		continueBtn.addEventListener('click', function () {
			actions.remove();
			if (els.input) {
				try {
					els.input.focus();
				} catch (e) { /* not focusable: harmless */ }
			}
		});
		actions.appendChild(continueBtn);

		var href = safeUrl(cart.cart_url);
		if (href) {
			// Same tab, same as a product card's own link — no target set.
			var viewLink = el('a', 'db-chat-cart-action', 'View cart');
			viewLink.setAttribute('href', href);
			actions.appendChild(viewLink);
		}

		body.appendChild(actions);

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

	/**
	 * Derive a sibling REST route from the chat route, the same way
	 * sessionUrl() does. Used only by the human-handoff fetches (POST
	 * /handoff, GET /messages), which are themselves only ever reached when
	 * handoffEnabled is true.
	 */
	function apiUrl(route) {
		if (typeof cfg.ajaxUrl !== 'string' || !cfg.ajaxUrl) {
			return '';
		}
		return cfg.ajaxUrl.replace(/\/chat(\/)?(?=$|[?&#])/, '/' + route + '$1');
	}

	/**
	 * The one place request headers are built for every authenticated fetch
	 * in this file — /chat, and (gated on handoffEnabled) /handoff and
	 * /messages — so the nonce header and the rate-limit token header can
	 * never drift apart between them. `withBody` adds the JSON content-type,
	 * which a GET must not send.
	 */
	function authHeaders(nonce, withBody) {
		var headers = {
			'Accept': 'application/json',
			'X-WP-Nonce': nonce
		};
		if (withBody) {
			headers['Content-Type'] = 'application/json';
		}
		// Abuse control only. This token goes nowhere else, is never used as
		// an analytics id, and dies with the tab.
		headers[TOKEN_HEADER] = getClientToken();
		return headers;
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
		// Round-trip whatever conversation id this tab already knows, so the
		// backend can hand the SAME conversation back once handoff is turned
		// on. Harmless when the feature is off: handle_session() only reads
		// this param inside its own handoff_enabled() check.
		if (conversationId) {
			url += (url.indexOf('?') === -1 ? '?' : '&') + 'conversation_id=' + encodeURIComponent(conversationId);
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
			applyHandoffFeature(data);
			applyContactGate(data);
			sessionNonce = data.nonce;
			sessionPromise = null;
			return sessionNonce;
		}).catch(function (error) {
			sessionPromise = null;
			// Even if the session round-trip failed, a gated guest should get the
			// form rather than a permanently locked composer. Treat unknown as
			// guest (the safe default): a logged-in customer would only ever have
			// been unlocked by a SUCCESSFUL session response anyway.
			if (contactGateEnabled && !contactGiven) {
				contactSessionResolved = true;
				refreshContactGate();
			}
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
		var headers = authHeaders(nonce, true);

		var body = {
			message: message,
			history: history.slice(-MAX_HISTORY_SENT),
			pending: pendingOrder
		};
		if (extra && extra.pick) {
			body.pick = extra.pick;
		}
		// A tapped size button: routes to the deterministic add-to-cart confirm.
		if (extra && extra.size_pick) {
			body.size_pick = extra.size_pick;
		}
		// The confirm-card decision. The server re-checks the staged proposal
		// and the literal message before any write — this is only a routing
		// signal, never trusted for the mutation itself.
		if (extra && extra.cart_confirm) {
			body.cart_confirm = extra.cart_confirm;
		}
		// Checkout-form field values (Phase 3). These travel ONLY here, never
		// in `message` or `history` — see the checkout-form submit handler,
		// which always sends a neutral message string alongside this.
		if (extra && extra.checkout_details) {
			body.checkout_details = extra.checkout_details;
		}
		// The logged-in tap-confirm's "yes, use my saved address" signal. No
		// PII of its own — the server already has the saved address.
		if (extra && extra.checkout_confirm_address) {
			body.checkout_confirm_address = extra.checkout_confirm_address;
		}
		// Checkout confirmation: the summary card's "Confirm & proceed to
		// payment" button, or the flag the widget adds when the customer types
		// an answer while the summary is on screen. The server gates the actual
		// proceed on the real checkout state — this is only a routing signal.
		if (extra && extra.checkout_confirm) {
			body.checkout_confirm = true;
		}
		if (extra && extra.checkout_confirm_context) {
			body.checkout_confirm_context = true;
		}
		// An attached photo: { media_type, data }. The server re-validates it
		// and forwards it to the model for this turn only — never stored.
		if (extra && extra.image) {
			body.image = extra.image;
		}
		// Only ever present once GET /session has round-tripped one back
		// (i.e. only when handoffEnabled). Off in production: this field is
		// simply absent, same request body as before this feature existed.
		if (handoffEnabled && conversationId) {
			body.conversation_id = conversationId;
		}
		// A guest's pre-chat name + email, so the server can name the
		// conversation in the inbox. Sent every turn while unpersisted is
		// harmless (the server sets it once), but contactFromGate is only ever
		// true for a guest's own submission — a signed-in customer's details
		// come from their account server-side and never ride the POST.
		if (handoffEnabled && contactFromGate && contactGiven && contactGiven.email) {
			body.contact_name = contactGiven.name || '';
			body.contact_email = contactGiven.email || '';
			body.contact_phone = contactGiven.phone || '';
		}

		return fetchWithTimeout(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: JSON.stringify(body)
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

		// Contact gate: nothing may be sent until a name + email is on file.
		// Belt-and-braces — the composer is disabled while gated, but a chip or
		// programmatic caller must not slip past either.
		if (contactGateEnabled && !contactGiven) {
			refreshContactGate();
			return;
		}

		var message = String(rawValue == null ? '' : rawValue).trim();

		// A staged photo rides this send. Capture it now (and clear the strip),
		// so a photo goes with or without a caption.
		var imageToSend = pendingImage;
		if (imageToSend) {
			extra = extra || {};
			extra.image = imageToSend;
			clearPendingImage();
		}

		if (!message && !imageToSend) {
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

		// If the checkout summary is awaiting confirmation and the customer
		// typed a plain reply (rather than tapping a card button), mark this
		// text as that confirmation so the server can proceed to payment
		// deterministically when it reads as a yes. One-shot: cleared on the
		// next send whatever it is.
		if (awaitingCheckoutConfirm) {
			awaitingCheckoutConfirm = false;
			if (!extra || (!extra.pick && !extra.cart_confirm && !extra.checkout_details && !extra.checkout_confirm_address && !extra.checkout_confirm && !extra.size_pick && !extra.image)) {
				extra = extra || {};
				extra.checkout_confirm_context = true;
			}
		}

		lastAttempt = message;
		lastAttemptExtra = null;
		if (extra && (extra.pick || extra.size_pick || extra.cart_confirm || extra.checkout_details || extra.checkout_confirm_address || extra.checkout_confirm || extra.checkout_confirm_context || extra.image)) {
			lastAttemptExtra = {};
			if (extra.image) {
				lastAttemptExtra.image = extra.image;
			}
			if (extra.pick) {
				lastAttemptExtra.pick = extra.pick;
			}
			if (extra.size_pick) {
				lastAttemptExtra.size_pick = extra.size_pick;
			}
			if (extra.cart_confirm) {
				lastAttemptExtra.cart_confirm = extra.cart_confirm;
			}
			if (extra.checkout_details) {
				lastAttemptExtra.checkout_details = extra.checkout_details;
			}
			if (extra.checkout_confirm_address) {
				lastAttemptExtra.checkout_confirm_address = extra.checkout_confirm_address;
			}
			if (extra.checkout_confirm) {
				lastAttemptExtra.checkout_confirm = true;
			}
			if (extra.checkout_confirm_context) {
				lastAttemptExtra.checkout_confirm_context = true;
			}
		}
		var sentExtra = lastAttemptExtra;
		// The customer's own words: the only thing the WhatsApp prefill may ever
		// carry, and only until a real order lookup marks the chat (see
		// noteOrderTools — the server's order_lookup signal, not the word "order").
		lastUserMessage = message;
		refreshHandoffHref();
		// Echo the customer's photo (and caption) back into their own bubble.
		var sentImageUrl = imageToSend ? ('data:' + imageToSend.media_type + ';base64,' + imageToSend.data) : null;
		addUserMessage(message, sentImageUrl);

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

	/**
	 * Pull `conversation_id` / `last_message_id` out of any /chat response
	 * (ack or normal reply) and fold them into local state. Gated on
	 * handoffEnabled — the backend only ever sends these fields once the
	 * feature is on, so this is a genuine no-op in production today.
	 */
	function syncHandoffFields(data) {
		if (!handoffEnabled || !data || typeof data !== 'object') {
			return;
		}
		if (typeof data.conversation_id === 'string' && data.conversation_id) {
			conversationId = data.conversation_id;
			try {
				window.localStorage.setItem(CONVERSATION_ID_KEY, conversationId);
			} catch (e) { /* storage unavailable: memory only for this tab */ }
		}
		var mid = parseInt(data.last_message_id, 10);
		if (!isNaN(mid) && mid > lastMessageId) {
			lastMessageId = mid;
		}
	}

	function handleResponse(result, sentMessage, sentExtra) {
		var data = result.data;

		// Before anything renders: if this turn went near the customer's
		// orders, the prefill is off from here on.
		noteOrderTools(data);
		// Round-trip conversation_id / last_message_id from every /chat
		// response — a no-op whenever handoffEnabled is false, since the
		// backend never sends either field in that case.
		syncHandoffFields(data);

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

		/*
		 * Handed off already: the backend answers with `{ ack: true, status,
		 * conversation_id, last_message_id }` and no `reply` at all while the
		 * conversation is waiting/human — the customer is talking to a
		 * person now, not the bot. Render nothing for this turn (no empty
		 * bubble, no "something went wrong") and just make sure polling is
		 * running.
		 */
		if (handoffEnabled && data && data.ack === true) {
			if (typeof data.status === 'string') {
				currentStatus = data.status;
			}
			setHumanHandoffButtonVisible(false);
			setBotHandoffButtonVisible(currentStatus === 'waiting' || currentStatus === 'human');
			if (currentStatus === 'waiting' || currentStatus === 'human') {
				startPolling();
			}
			return;
		}

		var reply = data && typeof data.reply === 'string' ? data.reply.trim() : '';
		if (!reply) {
			addNotice(TEXT.error, true, function () {
				send(sentMessage, sentExtra);
			});
			return;
		}

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

		// Phase 2 cart tools (propose/commit/view_cart). Both keys are
		// optional and absent on every response that never touched a cart
		// tool this turn, so this is a no-op on the common path. Summary
		// before confirm card: "here's your cart now" before "here's what
		// I'm about to change next" reads in the right order when a commit
		// and a fresh propose land in the same reply.
		addCartSummary(data ? data.cart : null);
		addSizePicker(data ? data.size_picker : null);
		addCartConfirm(data ? data.proposal : null);

		// Phase 3 checkout (RULING A response-key shapes — see
		// task-7-brief). Neither key is ever written to `transcript` or
		// `saveState`: a stale checkout step/summary must not survive a
		// reload, same rule as `proposal`/`cart` above.
		addCheckoutStep(data ? data.checkout : null, !!(data && data.checkout_link));
		addCheckoutLink(data ? data.checkout_link : null);

		// The assistant just pointed at the button — make it easy to find.
		if (replyOffersHandoff(reply, data)) {
			highlightHandoff();
		}

		// The lookup engine resolved an explicit "talk to a human" intent —
		// offer the contact step inline, right under this reply.
		if (handoffEnabled && data && data.escalate === true) {
			addEscalatePrompt();
		}

		history.push({ role: 'user', content: sentMessage });
		history.push({ role: 'assistant', content: reply });
		// In-memory only, capped at 10 turns (2 entries per turn).
		if (history.length > MAX_HISTORY_TURNS * 2) {
			history = history.slice(-(MAX_HISTORY_TURNS * 2));
		}

		var userEntry = { role: 'user', text: sentMessage };
		// Keep the photo with the entry so a reload re-renders it (persisted in
		// sessionStorage by saveState, which drops it first if space runs short).
		if (sentExtra && sentExtra.image && sentExtra.image.data && sentExtra.image.media_type) {
			userEntry.image = 'data:' + sentExtra.image.media_type + ';base64,' + sentExtra.image.data;
		}
		transcript.push(userEntry);
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

		// Launcher click is a real user gesture: prime the notification
		// AudioContext here so a later poll-driven tone is not blocked. Gated
		// on handoffEnabled so a production site with the feature off keeps
		// doing exactly what it always did here — no new AudioContext, no
		// behaviour change at all.
		if (handoffEnabled) {
			primeAudioContext();
		}

		// Lock the composer immediately if the contact gate is on and unmet, so
		// nothing can be typed before /session tells us who this is. The form
		// itself is shown from applyContactGate once the session resolves.
		refreshContactGate();

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

		// Resume polling if this tab already had a conversation waiting on
		// (or already with) a human when the panel was closed. No-op unless
		// handoffEnabled — see startPolling()'s own guard.
		if (handoffEnabled && conversationId && (currentStatus === 'waiting' || currentStatus === 'human')) {
			startPolling();
		}

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
		// Never poll while the panel is closed. Harmless when polling was
		// never running (handoffEnabled false, or no conversation yet).
		stopPolling();
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
