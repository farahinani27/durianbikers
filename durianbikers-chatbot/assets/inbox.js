/*
Durian Bikers Chatbot — admin "Chatbot Inbox" (wp-admin > Chatbot Inbox)
Text domain: durianbikers-chatbot

Vanilla JS, no build step, no third-party libraries. Mounted into
#db-chat-inbox-app (see includes/class-inbox-admin.php render_page()).

Expects wp_localize_script( 'db-chat-inbox', 'dbChatInbox', [...] ) with:
    root    REST base URL, e.g. https://.../wp-json/durianbikers/v1/inbox
    nonce   wp_create_nonce( 'wp_rest' )

Every fetch sends X-WP-Nonce + credentials:'same-origin' so the REST
permission_callback (current_user_can('manage_woocommerce')) sees the
logged-in admin session.

Layout: a left list of conversations (auto-refreshed every ~5s) and a right
pane with the selected conversation's transcript, a reply box, and
Take / Resolve buttons. Selecting a row stops it from being blown away by
the list auto-refresh. The open conversation's transcript is ALSO polled
every ~5s (see pollOpenConversation()): it re-renders and auto-scrolls only
when the newest message id actually changed, so an agent mid-read (or
mid-reply, in the textarea) isn't interrupted when nothing new arrived.

Bulk actions: every list row has a checkbox, and a toolbar above the list
(rendered once in boot(), OUTSIDE the list so the 5s re-render never wipes
it) offers Select all / Resolve selected / Delete selected. The selection
lives in state.checked so it survives each poll. There is no bulk endpoint:
runBulk() calls /resolve or /delete once per id, strictly one at a time.
*/

( function () {
	'use strict';

	if ( 'undefined' === typeof window.dbChatInbox ) {
		return;
	}

	var cfg = window.dbChatInbox;
	var root = cfg.root.replace( /\/$/, '' );

	var STATUS_LABELS = {
		waiting: 'Needs human',
		human: 'In progress',
		bot: 'Bot',
		resolved: 'Resolved',
	};

	var state = {
		conversations: [],
		selectedId: null,
		messages: [],
		// Bulk-selection checkboxes: conversation id (string) → true. Kept
		// here, not in the DOM, so a tick survives the 5s list re-render.
		checked: {},
		// True while runBulk() is working through its ids; bulkProgress is
		// the "Deleting 3/7…" text shown in place of the selected count.
		bulkBusy: false,
		bulkProgress: '',
	};

	// Newest message id we've actually rendered, keyed by conversation id.
	// Lets pollOpenConversation() tell "nothing new" apart from "something
	// arrived" without re-rendering (and yanking scroll / the reply
	// textarea) on every 5s tick.
	var lastSeenMessageId = {};

	// Per-open-conversation status last rendered, so the poll can tell a status
	// change (assign to bot / resolve) apart from a plain new message and
	// re-render the whole detail only when the status actually moved.
	var lastSeenStatus = {};

	var mount = null;
	var listEl = null;
	var detailEl = null;
	var toolbarEl = null;
	var pollTimer = null;

	// Per-conversation status seen on the previous /list poll (id → status).
	// null until the first load completes, so the very first poll only seeds
	// this map rather than beeping for every existing conversation on load.
	// Tracking the STATUS (not just presence) lets us beep when an existing
	// bot conversation flips to 'waiting' — the common "customer clicked Talk
	// to a human" case — not only for brand-new conversation ids.
	var knownStatus = null;

	// Lazily-created WebAudio context for the new-chat notification sound.
	var audioCtx = null;

	function newestMessageId( messages ) {
		var maxId = null;
		( messages || [] ).forEach( function ( m ) {
			var id = parseInt( m && m.id, 10 );
			if ( ! isNaN( id ) && ( null === maxId || id > maxId ) ) {
				maxId = id;
			}
		} );
		return maxId;
	}

	function api( path, opts ) {
		opts = opts || {};
		var headers = opts.headers || {};
		headers[ 'X-WP-Nonce' ] = cfg.nonce;
		if ( opts.body && ! headers[ 'Content-Type' ] ) {
			headers[ 'Content-Type' ] = 'application/json';
		}
		opts.headers = headers;
		opts.credentials = 'same-origin';
		// The list/conversation polls hit a STATIC url every 5s; without this a
		// cache layer (LiteSpeed) or the browser can serve a stale response, so
		// a newly-waiting chat never appears until a full page refresh (and the
		// new-chat sound never fires). Force a fresh fetch: never cache, and
		// append a cache-busting stamp so no intermediary can match a prior GET.
		opts.cache = 'no-store';
		var sep = path.indexOf( '?' ) === -1 ? '?' : '&';
		var bust = path + sep + '_ts=' + Date.now();
		return fetch( root + bust, opts ).then( function ( res ) {
			return res.json().then( function ( data ) {
				if ( ! res.ok ) {
					var msg = ( data && data.message ) ? data.message : 'Request failed';
					throw new Error( msg );
				}
				return data;
			} );
		} );
	}

	function esc( str ) {
		var div = document.createElement( 'div' );
		div.textContent = null === str || undefined === str ? '' : String( str );
		return div.innerHTML;
	}

	function fmtTime( mysql ) {
		if ( ! mysql ) {
			return '';
		}
		// created_at/updated_at are stored UTC ('mysql', true); treat as UTC.
		var iso = mysql.replace( ' ', 'T' ) + 'Z';
		var d = new Date( iso );
		if ( isNaN( d.getTime() ) ) {
			return mysql;
		}
		return d.toLocaleString();
	}

	function contactLabel( row ) {
		// Name first — the pre-chat contact gate captures it, and a person's
		// name is the most useful label in the list. Then the older fallbacks.
		if ( row.contact_name ) {
			return row.contact_name;
		}
		if ( row.contact_email ) {
			return row.contact_email;
		}
		if ( row.contact_phone ) {
			return row.contact_phone;
		}
		if ( row.contact_value ) {
			return row.contact_value;
		}
		return row.page_url ? row.page_url : '(no contact given)';
	}

	// A short snippet of the customer's most recent message (server sends up to
	// 200 chars as `preview`), trimmed to ~15 words so the row shows what they
	// asked — e.g. "hi, do you have…". Empty when nothing has been said yet.
	function messagePreview( row ) {
		var text = ( typeof row.preview === 'string' ? row.preview : '' ).replace( /\s+/g, ' ' ).trim();
		if ( ! text ) {
			return '';
		}
		var words = text.split( ' ' );
		if ( words.length > 15 ) {
			return words.slice( 0, 15 ).join( ' ' ) + '…';
		}
		return text;
	}

	/** Small "Customer contact" block for the detail view: only the fields
	 *  that are actually present, name/email/phone from the Sep 2026
	 *  contact-capture form. Falls back to the older single contact_value
	 *  field for conversations captured before it existed. */
	function contactBlock( convo ) {
		if ( ! convo ) {
			return '';
		}
		var rows = [];
		if ( convo.contact_name ) {
			rows.push( '<div class="db-chat-inbox-contact-row"><span class="db-chat-inbox-contact-label">Name</span><span class="db-chat-inbox-contact-value">' + esc( convo.contact_name ) + '</span></div>' );
		}
		if ( convo.contact_email ) {
			rows.push( '<div class="db-chat-inbox-contact-row"><span class="db-chat-inbox-contact-label">Email</span><span class="db-chat-inbox-contact-value">' + esc( convo.contact_email ) + '</span></div>' );
		}
		if ( convo.contact_phone ) {
			rows.push( '<div class="db-chat-inbox-contact-row"><span class="db-chat-inbox-contact-label">Phone</span><span class="db-chat-inbox-contact-value">' + esc( convo.contact_phone ) + '</span></div>' );
		}
		if ( ! rows.length && convo.contact_value ) {
			rows.push( '<div class="db-chat-inbox-contact-row"><span class="db-chat-inbox-contact-label">' + esc( convo.contact_type || 'Contact' ) + '</span><span class="db-chat-inbox-contact-value">' + esc( convo.contact_value ) + '</span></div>' );
		}
		if ( ! rows.length ) {
			return '';
		}
		return '<div class="db-chat-inbox-contact"><div class="db-chat-inbox-contact-heading">Customer contact</div>' + rows.join( '' ) + '</div>';
	}

	function renderList() {
		if ( 0 === state.conversations.length ) {
			listEl.innerHTML = '<div class="db-chat-inbox-empty">No conversations yet.</div>';
			renderToolbar();
			return;
		}

		var html = state.conversations
			.map( function ( row ) {
				var status = row.status || 'bot';
				var label = STATUS_LABELS[ status ] || status;
				var active = String( row.id ) === String( state.selectedId ) ? ' is-active' : '';
				var isChecked = state.checked[ String( row.id ) ] ? ' checked' : '';
				var boxDisabled = state.bulkBusy ? ' disabled' : '';
				var preview = messagePreview( row );
				return (
					'<div class="db-chat-inbox-row' + active + '" data-id="' + esc( row.id ) + '">' +
					'<label class="db-chat-inbox-check">' +
					'<input type="checkbox" class="db-chat-inbox-check-input" data-id="' + esc( row.id ) + '" aria-label="' + esc( 'Select conversation #' + row.id ) + '"' + isChecked + boxDisabled + '>' +
					'</label>' +
					'<span class="db-chat-inbox-badge db-chat-inbox-badge--' + esc( status ) + '">' + esc( label ) + '</span>' +
					'<div class="db-chat-inbox-row-main">' +
					'<div class="db-chat-inbox-row-contact">' + esc( contactLabel( row ) ) + '</div>' +
					( preview
						? '<div class="db-chat-inbox-row-preview">' + esc( preview ) + '</div>'
						: '<div class="db-chat-inbox-row-preview db-chat-inbox-row-preview--empty">' + esc( '(no message yet)' ) + '</div>' ) +
					'<div class="db-chat-inbox-row-time">' + esc( fmtTime( row.updated_at ) ) + '</div>' +
					'</div>' +
					'</div>'
				);
			} )
			.join( '' );

		listEl.innerHTML = html;
		renderToolbar();
	}

	// Allowed image types for a stored customer photo — mirrors the server's
	// sanitize_image() whitelist, so a bad media_type can't build a data: URL.
	var IMAGE_TYPES = { 'image/jpeg': 1, 'image/png': 1, 'image/webp': 1, 'image/gif': 1 };

	function messageImageHtml( m ) {
		if ( ! m || ! m.image_data || ! m.image_media_type || ! IMAGE_TYPES[ m.image_media_type ] ) {
			return '';
		}
		// base64 payload only, so it is safe inside the src attribute.
		var data = String( m.image_data ).replace( /[^A-Za-z0-9+/=]/g, '' );
		if ( ! data ) {
			return '';
		}
		return '<img class="db-chat-inbox-msg-image" src="data:' + m.image_media_type + ';base64,' + data + '" alt="Customer photo">';
	}

	/** The product cards that went out with a bot reply, stored per message.
	 *  Same fields the customer saw: name, price, stock and a link. Everything
	 *  is escaped; the link is dropped unless it is plain http(s). */
	function messageProductsHtml( m ) {
		if ( ! m || ! m.products ) {
			return '';
		}

		var cards;
		try {
			cards = 'string' === typeof m.products ? JSON.parse( m.products ) : m.products;
		} catch ( e ) {
			return '';
		}

		if ( ! cards || ! cards.length ) {
			return '';
		}

		var rows = cards
			.slice( 0, 4 )
			.map( function ( c ) {
				if ( ! c || ! c.name ) {
					return '';
				}
				var url = String( c.url || '' );
				var name = /^https?:\/\//i.test( url )
					? '<a href="' + esc( url ) + '" target="_blank" rel="noopener noreferrer">' + esc( c.name ) + '</a>'
					: esc( c.name );
				var price = c.price ? '<span class="db-chat-inbox-card-price">' + esc( c.price ) + '</span>' : '';
				var stock = '<span class="db-chat-inbox-card-stock' + ( c.in_stock ? ' is-in' : '' ) + '">' +
					( c.in_stock ? 'In stock' : 'Out of stock' ) + '</span>';
				return '<li class="db-chat-inbox-card">' + name + ' ' + price + ' ' + stock + '</li>';
			} )
			.join( '' );

		return rows ? '<ul class="db-chat-inbox-cards">' + rows + '</ul>' : '';
	}

	function buildBubblesHtml() {
		return state.messages
			.map( function ( m ) {
				return (
					'<div class="db-chat-inbox-msg db-chat-inbox-msg--' + esc( m.sender ) + '">' +
					'<div class="db-chat-inbox-msg-meta">' + esc( m.sender ) + ' &middot; ' + esc( fmtTime( m.created_at ) ) + '</div>' +
					'<div class="db-chat-inbox-msg-body">' + messageImageHtml( m ) + esc( m.body ) + messageProductsHtml( m ) + '</div>' +
					'</div>'
				);
			} )
			.join( '' );
	}

	/** Re-render ONLY the transcript bubbles inside the already-mounted
	 *  detail pane, and scroll it to the bottom. Used by the 5s poll so a
	 *  newly-arrived message shows up without touching the status badge,
	 *  Take/Resolve buttons, or the reply textarea (content/focus) around
	 *  it. No-op if the detail pane isn't showing this conversation. */
	function renderTranscript() {
		var transcriptEl = detailEl.querySelector( '.db-chat-inbox-transcript' );
		if ( ! transcriptEl ) {
			return;
		}
		transcriptEl.innerHTML = buildBubblesHtml() || '<div class="db-chat-inbox-placeholder">No messages yet.</div>';
		transcriptEl.scrollTop = transcriptEl.scrollHeight;
	}

	function renderDetail() {
		if ( ! state.selectedId ) {
			detailEl.innerHTML = '<div class="db-chat-inbox-placeholder">Select a conversation on the left.</div>';
			return;
		}

		var convo = state.conversations.filter( function ( row ) {
			return String( row.id ) === String( state.selectedId );
		} )[ 0 ];

		var status = convo ? convo.status : '';
		var resolved = 'resolved' === status;
		var botActive = 'bot' === status;
		// The AI owns a 'bot' conversation and a 'resolved' one is closed —
		// staff cannot reply into either, so the reply box is disabled.
		var replyDisabled = resolved || botActive;

		// "Also email this reply" — only meaningful when we have a contact email
		// AND the reply box is usable. Otherwise show why it can't be emailed.
		var hasEmail = !!( convo && convo.contact_email );
		var emailOptHtml = replyDisabled
			? ''
			: ( hasEmail
				? '<label class="db-chat-inbox-email-opt"><input type="checkbox" name="email_reply"> Also email this reply to ' + esc( convo.contact_email ) + '</label>'
				: '<span class="db-chat-inbox-email-none">No email on file — reply shows in the chat only</span>' );

		/*
		 * Single toggle instead of two static buttons: it always offers the
		 * OPPOSITE of the current state. 'waiting' (just handed off, not yet
		 * taken) reads as the human side — the bot is not answering it either
		 * way — so it offers "Assign to bot" same as 'human'. Hidden entirely
		 * once resolved: the conversation is over, there is nothing to assign.
		 */
		var toggleMode = botActive ? 'human' : 'bot';
		var toggleLabel = botActive ? 'Assign to human' : 'Assign to bot';
		var assignToggleHtml = resolved
			? ''
			: '<button type="button" class="button db-chat-inbox-assign-btn" data-action="assign" data-mode="' + toggleMode + '">' + esc( toggleLabel ) + '</button>';

		var bubbles = buildBubblesHtml();

		detailEl.innerHTML =
			'<div class="db-chat-inbox-detail-head">' +
			'<div>' +
			'<strong>Conversation #' + esc( state.selectedId ) + '</strong> ' +
			'<span class="db-chat-inbox-badge db-chat-inbox-badge--' + esc( status ) + '">' + esc( STATUS_LABELS[ status ] || status ) + '</span>' +
			'</div>' +
			'<div class="db-chat-inbox-detail-actions">' +
			assignToggleHtml +
			'<button type="button" class="button" data-action="resolve"' + ( resolved ? ' disabled' : '' ) + '>Resolve</button>' +
			'<button type="button" class="button db-chat-inbox-delete-btn" data-action="delete">Delete</button>' +
			'</div>' +
			'</div>' +
			contactBlock( convo ) +
			'<div class="db-chat-inbox-transcript">' + ( bubbles || '<div class="db-chat-inbox-placeholder">No messages yet.</div>' ) + '</div>' +
			( botActive
				? '<div class="db-chat-inbox-botnote">The AI assistant is handling this conversation. Assign it to a human to reply.</div>'
				: '' ) +
			'<form class="db-chat-inbox-reply">' +
			'<textarea name="body" placeholder="' + ( replyDisabled ? 'Replies are disabled — the AI assistant has this chat' : 'Type a reply…' ) + '" rows="3"' + ( replyDisabled ? ' disabled' : '' ) + '></textarea>' +
			'<div class="db-chat-inbox-reply-actions">' +
			emailOptHtml +
			'<button type="submit" class="button button-primary"' + ( replyDisabled ? ' disabled' : '' ) + '>Send reply</button>' +
			'</div>' +
			'</form>';

		var transcriptEl = detailEl.querySelector( '.db-chat-inbox-transcript' );
		if ( transcriptEl ) {
			transcriptEl.scrollTop = transcriptEl.scrollHeight;
		}
	}

	function loadList() {
		return api( '/list' ).then( function ( data ) {
			state.conversations = data.conversations || [];
			detectNewWaiting( state.conversations );
			renderList();
			updateMenuBadge();
		} );
	}

	function loadConversation( id ) {
		return api( '/conversation?id=' + encodeURIComponent( id ) ).then( function ( data ) {
			state.messages = data.messages || [];
			// Baseline for the poll's "did anything new arrive" check below.
			lastSeenMessageId[ id ] = newestMessageId( state.messages );
			lastSeenStatus[ id ] = data.conversation ? data.conversation.status : undefined;
			// Keep the row list's status in sync with what we just fetched.
			state.conversations = state.conversations.map( function ( row ) {
				return String( row.id ) === String( id ) ? data.conversation : row;
			} );
			renderList();
			renderDetail();
		} );
	}

	/** Called on every ~5s poll tick alongside loadList(). Refreshes the
	 *  currently-open conversation's messages and, ONLY if the newest
	 *  message id actually changed (a new agent/customer/system message
	 *  arrived), re-renders the transcript and scrolls to the bottom. When
	 *  nothing changed it does nothing further — no re-render, no scroll,
	 *  and the reply textarea (content, focus, cursor position) is left
	 *  completely alone. No-op when no conversation is open. */
	function pollOpenConversation() {
		if ( ! state.selectedId ) {
			return;
		}
		var id = state.selectedId;
		api( '/conversation?id=' + encodeURIComponent( id ) )
			.then( function ( data ) {
				// The agent may have selected a different (or no) conversation
				// while this request was in flight — don't apply stale data.
				if ( String( state.selectedId ) !== String( id ) ) {
					return;
				}

				var messages = data.messages || [];
				state.messages = messages;
				state.conversations = state.conversations.map( function ( row ) {
					return String( row.id ) === String( id ) ? data.conversation : row;
				} );

				// A status change (e.g. the customer tapped "Talk to the AI
				// assistant" → 'bot', or Resolve) must re-render the whole
				// detail so the assign toggle and the reply box's enabled/
				// disabled state follow it live. A plain new message only needs
				// the transcript, which leaves any in-progress reply draft
				// untouched.
				var newStatus = data.conversation ? data.conversation.status : undefined;
				if ( newStatus !== lastSeenStatus[ id ] ) {
					lastSeenStatus[ id ] = newStatus;
					lastSeenMessageId[ id ] = newestMessageId( messages );
					renderDetail();
					return;
				}

				var newestId = newestMessageId( messages );
				var previous = Object.prototype.hasOwnProperty.call( lastSeenMessageId, id ) ? lastSeenMessageId[ id ] : null;

				if ( newestId !== previous ) {
					lastSeenMessageId[ id ] = newestId;
					renderTranscript();
				}
			} )
			.catch( function () {
				// Transient polling failure: silently try again next tick,
				// same as the widget's own message poll does.
			} );
	}

	/**
	 * Best-effort two-tone beep for a newly-arrived waiting conversation.
	 * Lazily creates the AudioContext (admin has already interacted with the
	 * page by the time any poll runs) and never lets a WebAudio failure
	 * (blocked autoplay, unsupported browser, etc.) break polling.
	 */
	function playNewChatSound() {
		try {
			var Ctx = window.AudioContext || window.webkitAudioContext;
			if ( ! Ctx ) {
				return;
			}
			if ( ! audioCtx ) {
				audioCtx = new Ctx();
			}
			var schedule = function () {
				try {
					var ctx = audioCtx;
					var now = ctx.currentTime;
					[ [ 880, now, 0.08 ], [ 1175, now + 0.09, 0.07 ] ].forEach( function ( tone ) {
						var osc = ctx.createOscillator();
						var gain = ctx.createGain();
						osc.type = 'sine';
						osc.frequency.setValueAtTime( tone[ 0 ], tone[ 1 ] );
						gain.gain.setValueAtTime( 0.0001, tone[ 1 ] );
						gain.gain.exponentialRampToValueAtTime( 0.25, tone[ 1 ] + 0.01 );
						gain.gain.exponentialRampToValueAtTime( 0.0001, tone[ 1 ] + tone[ 2 ] );
						osc.connect( gain );
						gain.connect( ctx.destination );
						osc.start( tone[ 1 ] );
						osc.stop( tone[ 1 ] + tone[ 2 ] + 0.02 );
					} );
				} catch ( e ) { /* best-effort */ }
			};
			// resume() is async: scheduling before it resolves plays into a
			// still-suspended context and is silently dropped. Wait for it.
			if ( 'suspended' === audioCtx.state && audioCtx.resume ) {
				audioCtx.resume().then( schedule ).catch( function () {} );
			} else {
				schedule();
			}
		} catch ( e ) {
			// Audio is best-effort only: never let it break the inbox.
		}
	}

	/**
	 * Compare this poll's rows against the previous poll's known ids. Plays
	 * the notification sound when a conversation with status 'waiting' shows
	 * up whose id was not present last time — i.e. a brand new handoff, not a
	 * status change on a conversation the admin already knows about. No-op
	 * (just seeds knownIds) on the very first load.
	 */
	function detectNewWaiting( rows ) {
		var nextStatus = {};
		var isNew = false;

		rows.forEach( function ( row ) {
			var id = String( row.id );
			var prev = knownStatus ? knownStatus[ id ] : undefined;
			nextStatus[ id ] = row.status;
			// Beep when a conversation BECOMES 'waiting': either brand new, or
			// an existing bot chat the customer just handed to a human. The
			// first poll (knownStatus === null) only seeds the map, silently.
			if ( null !== knownStatus && 'waiting' === row.status && 'waiting' !== prev ) {
				isNew = true;
			}
		} );

		knownStatus = nextStatus;

		if ( isNew ) {
			playNewChatSound();
		}
	}

	/** Best-effort: keep the wp-admin left-menu unread bubble in sync live. */
	function updateMenuBadge() {
		try {
			var count = state.conversations.filter( function ( row ) {
				return 'waiting' === row.status;
			} ).length;
			var badge = document.querySelector( '#toplevel_page_db-chat-inbox .pending-count' );
			if ( badge ) {
				badge.textContent = String( count );
			}
		} catch ( e ) {
			// Best-effort only.
		}
	}

	function selectConversation( id ) {
		state.selectedId = id;
		renderList();
		renderDetail();
		loadConversation( id );
	}

	function onListClick( e ) {
		// A checkbox tick is handled by onListChange; don't also open the row.
		if ( e.target.closest( '.db-chat-inbox-check' ) ) {
			return;
		}
		var row = e.target.closest( '.db-chat-inbox-row' );
		if ( ! row ) {
			return;
		}
		selectConversation( row.getAttribute( 'data-id' ) );
	}

	/** A row checkbox toggled: record it in state.checked (survives the poll). */
	function onListChange( e ) {
		var box = e.target.closest( '.db-chat-inbox-check-input' );
		if ( ! box ) {
			return;
		}
		var id = String( box.getAttribute( 'data-id' ) );
		if ( box.checked ) {
			state.checked[ id ] = true;
		} else {
			delete state.checked[ id ];
		}
		renderToolbar();
	}

	/** The "Select all" checkbox: tick/untick every conversation in the list. */
	function onToolbarChange( e ) {
		if ( ! e.target.closest( '.db-chat-inbox-selectall-input' ) || state.bulkBusy ) {
			return;
		}
		if ( e.target.checked ) {
			state.conversations.forEach( function ( row ) {
				state.checked[ String( row.id ) ] = true;
			} );
		} else {
			state.checked = {};
		}
		renderList();
	}

	/** Resolve selected / Delete selected. */
	function onToolbarClick( e ) {
		var btn = e.target.closest( '.db-chat-inbox-bulk' );
		if ( ! btn || state.bulkBusy ) {
			return;
		}
		var action = btn.getAttribute( 'data-bulk' );
		var ids = selectedIds();
		if ( ! ids.length ) {
			return;
		}
		if ( 'delete' === action &&
			! window.confirm( 'Delete ' + ids.length + ' conversation' + ( ids.length > 1 ? 's' : '' ) + ' permanently? This cannot be undone.' ) ) {
			return;
		}
		runBulk( action, ids );
	}

	/** Checked ids that are still in the current (possibly filtered) list. */
	function selectedIds() {
		var present = {};
		state.conversations.forEach( function ( row ) {
			present[ String( row.id ) ] = true;
		} );
		return Object.keys( state.checked ).filter( function ( id ) {
			return present[ id ];
		} );
	}

	/** Toolbar above the list: Select all, count/progress, bulk buttons.
	 *  Rendered into toolbarEl (OUTSIDE the list) so the 5s poll never wipes it. */
	function renderToolbar() {
		if ( ! toolbarEl ) {
			return;
		}

		if ( state.bulkBusy ) {
			toolbarEl.innerHTML =
				'<span class="db-chat-inbox-toolbar-count">' + esc( state.bulkProgress || 'Working…' ) + '</span>';
			return;
		}

		var total = state.conversations.length;
		var n = selectedIds().length;
		var allChecked = total > 0 && n === total;
		var disabled = n ? '' : ' disabled';

		toolbarEl.innerHTML =
			'<label class="db-chat-inbox-selectall">' +
			'<input type="checkbox" class="db-chat-inbox-selectall-input"' + ( allChecked ? ' checked' : '' ) + ( total ? '' : ' disabled' ) + '> Select all' +
			'</label>' +
			'<span class="db-chat-inbox-toolbar-count">' + ( n ? n + ' selected' : '' ) + '</span>' +
			'<span class="db-chat-inbox-toolbar-actions">' +
			'<button type="button" class="button db-chat-inbox-bulk" data-bulk="resolve"' + disabled + '>Resolve selected</button>' +
			'<button type="button" class="button db-chat-inbox-bulk db-chat-inbox-bulk--danger" data-bulk="delete"' + disabled + '>Delete selected</button>' +
			'</span>';
	}

	/** Apply a bulk action one id at a time (no bulk endpoint). Skips failures
	 *  and reports the count at the end; clears the open pane if it was deleted. */
	function runBulk( action, ids ) {
		var isDelete = 'delete' === action;
		var path = isDelete ? '/delete' : '/resolve';
		var verb = isDelete ? 'Deleting' : 'Resolving';
		var total = ids.length;
		var failed = 0;

		state.bulkBusy = true;
		renderList(); // disables the row checkboxes and shows progress

		function step( i ) {
			if ( i >= total ) {
				state.bulkBusy = false;
				state.bulkProgress = '';
				state.checked = {};
				return loadList().then( function () {
					if ( isDelete && state.selectedId && ! inList( state.selectedId ) ) {
						state.selectedId = null;
						renderDetail();
					}
					renderToolbar();
					if ( failed ) {
						window.alert( failed + ' of ' + total + ' could not be ' + ( isDelete ? 'deleted' : 'resolved' ) + '.' );
					}
				} );
			}

			state.bulkProgress = verb + ' ' + ( i + 1 ) + '/' + total + '…';
			renderToolbar();

			return api( path, { method: 'POST', body: JSON.stringify( { id: ids[ i ] } ) } )
				.then( function () {
					delete state.checked[ String( ids[ i ] ) ];
				} )
				.catch( function () {
					failed += 1;
				} )
				.then( function () {
					return step( i + 1 );
				} );
		}

		step( 0 );
	}

	/** Is this conversation id still present in the loaded list? */
	function inList( id ) {
		return state.conversations.some( function ( row ) {
			return String( row.id ) === String( id );
		} );
	}

	function onDetailClick( e ) {
		var btn = e.target.closest( 'button[data-action]' );
		if ( ! btn || ! state.selectedId ) {
			return;
		}
		var action = btn.getAttribute( 'data-action' );
		var id = state.selectedId;

		if ( 'delete' === action ) {
			if ( ! window.confirm( 'Delete this conversation permanently? This cannot be undone.' ) ) {
				return;
			}
			btn.disabled = true;
			api( '/delete', { method: 'POST', body: JSON.stringify( { id: id } ) } )
				.then( function () {
					state.selectedId = null;
					renderDetail();
					return loadList();
				} )
				.catch( function ( err ) {
					window.alert( err.message || 'Could not delete this conversation.' );
					btn.disabled = false;
				} );
			return;
		}

		var request;
		if ( 'assign' === action ) {
			var mode = btn.getAttribute( 'data-mode' );
			request = api( '/assign', { method: 'POST', body: JSON.stringify( { id: id, mode: mode } ) } );
		} else if ( 'resolve' === action ) {
			request = api( '/resolve', { method: 'POST', body: JSON.stringify( { id: id } ) } );
		} else {
			return;
		}

		btn.disabled = true;
		request
			.then( function () {
				return Promise.all( [ loadList(), loadConversation( id ) ] );
			} )
			.catch( function ( err ) {
				window.alert( err.message || 'Something went wrong.' );
			} )
			.then( function () {
				btn.disabled = false;
			} );
	}

	function onReplySubmit( e ) {
		e.preventDefault();
		if ( ! state.selectedId ) {
			return;
		}
		var form = e.target;
		var textarea = form.querySelector( 'textarea[name="body"]' );
		var body = textarea.value.trim();
		if ( '' === body ) {
			return;
		}

		var emailBox = form.querySelector( 'input[name="email_reply"]' );
		var wantEmail = !!( emailBox && emailBox.checked );

		var submitBtn = form.querySelector( 'button[type="submit"]' );
		submitBtn.disabled = true;
		textarea.disabled = true;

		var id = state.selectedId;

		api( '/reply', { method: 'POST', body: JSON.stringify( { id: id, body: body, email: wantEmail } ) } )
			.then( function ( res ) {
				// Tell the operator plainly whether the email actually went out.
				if ( wantEmail ) {
					if ( res && res.emailed ) {
						window.alert( 'Reply sent and emailed to the customer.' );
					} else if ( res && ! res.has_email ) {
						window.alert( 'Reply sent. No email on file for this customer, so it was not emailed.' );
					} else {
						window.alert( 'Reply sent, but the email could not be sent. Check the mail settings.' );
					}
				}
				return Promise.all( [ loadList(), loadConversation( id ) ] );
			} )
			.catch( function ( err ) {
				window.alert( err.message || 'Could not send reply.' );
				submitBtn.disabled = false;
				textarea.disabled = false;
			} );
	}

	function boot() {
		mount = document.getElementById( 'db-chat-inbox-app' );
		if ( ! mount ) {
			return;
		}

		mount.innerHTML =
			'<div class="db-chat-inbox">' +
			'<div class="db-chat-inbox-left">' +
			'<div class="db-chat-inbox-toolbar" id="db-chat-inbox-toolbar"></div>' +
			'<div class="db-chat-inbox-list" id="db-chat-inbox-list"></div>' +
			'</div>' +
			'<div class="db-chat-inbox-detail" id="db-chat-inbox-detail"></div>' +
			'</div>';

		listEl = document.getElementById( 'db-chat-inbox-list' );
		detailEl = document.getElementById( 'db-chat-inbox-detail' );
		toolbarEl = document.getElementById( 'db-chat-inbox-toolbar' );

		listEl.addEventListener( 'click', onListClick );
		listEl.addEventListener( 'change', onListChange );
		toolbarEl.addEventListener( 'click', onToolbarClick );
		toolbarEl.addEventListener( 'change', onToolbarChange );
		detailEl.addEventListener( 'click', onDetailClick );
		detailEl.addEventListener( 'submit', function ( e ) {
			if ( e.target && e.target.classList.contains( 'db-chat-inbox-reply' ) ) {
				onReplySubmit( e );
			}
		} );

		// Prime the notification AudioContext on the admin's first interaction.
		// A context first created inside the poll callback (not a user gesture)
		// is born 'suspended' and stays silent no matter how many times it is
		// resumed later; creating and resuming it from a real gesture is what
		// lets the poll-driven new-chat beep actually play. Idempotent.
		var primeAudio = function () {
			try {
				var Ctx = window.AudioContext || window.webkitAudioContext;
				if ( ! Ctx ) {
					return;
				}
				if ( ! audioCtx ) {
					audioCtx = new Ctx();
				}
				if ( 'suspended' === audioCtx.state && audioCtx.resume ) {
					audioCtx.resume();
				}
			} catch ( e ) { /* audio is best-effort only */ }
		};
		document.addEventListener( 'pointerdown', primeAudio );
		document.addEventListener( 'keydown', primeAudio );

		renderDetail();
		loadList();

		pollTimer = window.setInterval( function () {
			loadList();
			pollOpenConversation();
		}, 5000 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
