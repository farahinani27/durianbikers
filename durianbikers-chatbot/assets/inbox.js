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
the list auto-refresh; the transcript itself is only reloaded on demand
(select / reply / take / resolve), not on the 5s timer.
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

	var mount = null;
	var listEl = null;
	var detailEl = null;
	var pollTimer = null;

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

		var bubbles = state.messages
			.map( function ( m ) {
				return (
					'<div class="db-chat-inbox-msg db-chat-inbox-msg--' + esc( m.sender ) + '">' +
					'<div class="db-chat-inbox-msg-meta">' + esc( m.sender ) + ' &middot; ' + esc( fmtTime( m.created_at ) ) + '</div>' +
					'<div class="db-chat-inbox-msg-body">' + esc( m.body ) + '</div>' +
					'</div>'
				);
			} )
			.join( '' );

		detailEl.innerHTML =
			'<div class="db-chat-inbox-detail-head">' +
			'<div>' +
			'<strong>Conversation #' + esc( state.selectedId ) + '</strong> ' +
			'<span class="db-chat-inbox-badge db-chat-inbox-badge--' + esc( status ) + '">' + esc( STATUS_LABELS[ status ] || status ) + '</span>' +
			'</div>' +
			'<div class="db-chat-inbox-detail-actions">' +
			'<button type="button" class="button" data-action="take"' + ( resolved ? ' disabled' : '' ) + '>Take</button>' +
			'<button type="button" class="button" data-action="resolve"' + ( resolved ? ' disabled' : '' ) + '>Resolve</button>' +
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
			renderList();
		} );
	}

	function loadConversation( id ) {
		return api( '/conversation?id=' + encodeURIComponent( id ) ).then( function ( data ) {
			state.messages = data.messages || [];
			// Keep the row list's status in sync with what we just fetched.
			state.conversations = state.conversations.map( function ( row ) {
				return String( row.id ) === String( id ) ? data.conversation : row;
			} );
			renderList();
			renderDetail();
		} );
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

		btn.disabled = true;
		var request = 'take' === action ? api( '/take', { method: 'POST', body: JSON.stringify( { id: id } ) } ) : api( '/resolve', { method: 'POST', body: JSON.stringify( { id: id } ) } );

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

		renderDetail();
		loadList();

		pollTimer = window.setInterval( loadList, 5000 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
