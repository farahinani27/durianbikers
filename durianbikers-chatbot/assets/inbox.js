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
	};

	// Newest message id we've actually rendered, keyed by conversation id.
	// Lets pollOpenConversation() tell "nothing new" apart from "something
	// arrived" without re-rendering (and yanking scroll / the reply
	// textarea) on every 5s tick.
	var lastSeenMessageId = {};

	var mount = null;
	var listEl = null;
	var detailEl = null;
	var pollTimer = null;

	// Conversation ids seen on the previous /list poll. null until the first
	// load completes, so the very first poll only seeds this set rather than
	// treating every existing conversation as "new" and beeping on load.
	var knownIds = null;

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
		return fetch( root + path, opts ).then( function ( res ) {
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

	function lastPreview( row ) {
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
			return;
		}

		var html = state.conversations
			.map( function ( row ) {
				var status = row.status || 'bot';
				var label = STATUS_LABELS[ status ] || status;
				var active = String( row.id ) === String( state.selectedId ) ? ' is-active' : '';
				return (
					'<div class="db-chat-inbox-row' + active + '" data-id="' + esc( row.id ) + '">' +
					'<span class="db-chat-inbox-badge db-chat-inbox-badge--' + esc( status ) + '">' + esc( label ) + '</span>' +
					'<div class="db-chat-inbox-row-main">' +
					'<div class="db-chat-inbox-row-contact">' + esc( lastPreview( row ) ) + '</div>' +
					'<div class="db-chat-inbox-row-time">' + esc( fmtTime( row.updated_at ) ) + '</div>' +
					'</div>' +
					'</div>'
				);
			} )
			.join( '' );

		listEl.innerHTML = html;
	}

	function buildBubblesHtml() {
		return state.messages
			.map( function ( m ) {
				return (
					'<div class="db-chat-inbox-msg db-chat-inbox-msg--' + esc( m.sender ) + '">' +
					'<div class="db-chat-inbox-msg-meta">' + esc( m.sender ) + ' &middot; ' + esc( fmtTime( m.created_at ) ) + '</div>' +
					'<div class="db-chat-inbox-msg-body">' + esc( m.body ) + '</div>' +
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
			'<form class="db-chat-inbox-reply">' +
			'<textarea name="body" placeholder="Type a reply…" rows="3"' + ( resolved ? ' disabled' : '' ) + '></textarea>' +
			'<button type="submit" class="button button-primary"' + ( resolved ? ' disabled' : '' ) + '>Send reply</button>' +
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
			if ( 'suspended' === audioCtx.state && audioCtx.resume ) {
				audioCtx.resume();
			}
			var ctx = audioCtx;
			var now = ctx.currentTime;
			[ [ 880, now, 0.08 ], [ 1175, now + 0.09, 0.07 ] ].forEach( function ( tone ) {
				var osc = ctx.createOscillator();
				var gain = ctx.createGain();
				osc.type = 'sine';
				osc.frequency.setValueAtTime( tone[ 0 ], tone[ 1 ] );
				gain.gain.setValueAtTime( 0.0001, tone[ 1 ] );
				gain.gain.exponentialRampToValueAtTime( 0.2, tone[ 1 ] + 0.01 );
				gain.gain.exponentialRampToValueAtTime( 0.0001, tone[ 1 ] + tone[ 2 ] );
				osc.connect( gain );
				gain.connect( ctx.destination );
				osc.start( tone[ 1 ] );
				osc.stop( tone[ 1 ] + tone[ 2 ] + 0.02 );
			} );
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
		var nextKnown = {};
		var isNew = false;

		rows.forEach( function ( row ) {
			var id = String( row.id );
			nextKnown[ id ] = true;
			if ( null !== knownIds && 'waiting' === row.status && ! knownIds[ id ] ) {
				isNew = true;
			}
		} );

		knownIds = nextKnown;

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
		var row = e.target.closest( '.db-chat-inbox-row' );
		if ( ! row ) {
			return;
		}
		selectConversation( row.getAttribute( 'data-id' ) );
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

		var submitBtn = form.querySelector( 'button[type="submit"]' );
		submitBtn.disabled = true;
		textarea.disabled = true;

		var id = state.selectedId;

		api( '/reply', { method: 'POST', body: JSON.stringify( { id: id, body: body } ) } )
			.then( function () {
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
			'<div class="db-chat-inbox-list" id="db-chat-inbox-list"></div>' +
			'<div class="db-chat-inbox-detail" id="db-chat-inbox-detail"></div>' +
			'</div>';

		listEl = document.getElementById( 'db-chat-inbox-list' );
		detailEl = document.getElementById( 'db-chat-inbox-detail' );

		listEl.addEventListener( 'click', onListClick );
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
