
( function () {
	'use strict';

	var api = new mw.Api();

	function showNotice( container, type, text ) {
		var existing = container.querySelector( '.mw-nexaboard-notice' );
		if ( existing ) existing.remove();

		var div = document.createElement( 'div' );
		div.className = 'mw-nexaboard-notice mw-nexaboard-notice-' + type;
		div.textContent = text;
		container.insertBefore( div, container.firstChild );

		if ( type === 'success' ) {
			setTimeout( function () { div.remove(); }, 4000 );
		}
	}

	function root() {
		return document.querySelector( '.mw-nexaboard' );
	}

	function fail( container, code, data ) {
		var msg = ( data && data.error && data.error.info ) || mw.msg( 'nexaboard-error-generic' );
		showNotice( container || root(), 'error', msg );
	}

	function setLoading( btn, loading ) {
		btn.disabled = loading;
		btn.style.opacity = loading ? '0.6' : '1';
	}

	function threadEl( threadId ) {
		return document.querySelector( '.mw-nexaboard-thread[data-thread-id="' + threadId + '"]' );
	}

	/**
	 * Reload, landing on a particular thread or message.
	 */
	function reloadTo( fragment ) {
		if ( fragment ) {
			window.location.hash = fragment;
		}
		window.location.reload();
	}

	function initNewMessageForm() {
		var openBtn   = document.getElementById( 'mw-nexaboard-open-form' );
		var form      = document.getElementById( 'mw-nexaboard-new-form' );
		var cancelBtn = document.getElementById( 'mw-nexaboard-cancel' );
		var submitBtn = document.getElementById( 'mw-nexaboard-submit' );

		if ( !openBtn || !form || !submitBtn ) return;

		openBtn.addEventListener( 'click', function () {
			form.style.display = form.style.display === 'none' ? 'block' : 'none';
			if ( form.style.display === 'block' ) {
				document.getElementById( 'mw-nexaboard-new-title' ).focus();
			}
		} );

		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', function () {
				form.style.display = 'none';
				document.getElementById( 'mw-nexaboard-new-title' ).value = '';
				document.getElementById( 'mw-nexaboard-new-body' ).value  = '';
			} );
		}

		submitBtn.addEventListener( 'click', function () {
			var boardUser = submitBtn.getAttribute( 'data-board-user' );
			var title    = document.getElementById( 'mw-nexaboard-new-title' ).value.trim();
			var body     = document.getElementById( 'mw-nexaboard-new-body' ).value.trim();

			if ( !title ) {
				showNotice( form, 'error', mw.msg( 'nexaboard-error-notitle' ) );
				return;
			}
			if ( !body ) {
				showNotice( form, 'error', mw.msg( 'nexaboard-error-nobody' ) );
				return;
			}

			setLoading( submitBtn, true );

			api.postWithToken( 'csrf', {
				action:   'nexaboardpost',
				boarduser: boardUser,
				title:    title,
				body:     body
			} ).then( function ( data ) {
				var result = data.nexaboardpost;
				if ( result && result.result === 'success' ) {
					reloadTo( 'nexaboard-thread-' + result.threadid );
				}
			} ).catch( function ( code, data ) {
				fail( form, code, data );
				setLoading( submitBtn, false );
			} );
		} );
	}

	// ---------------------------------------------------------------- replies

	function replyForm( threadId ) {
		return document.querySelector(
			'.mw-nexaboard-reply-form-wrap[data-thread-id="' + threadId + '"]'
		);
	}

	/**
	 * Open a thread's reply box, optionally aimed at one specific reply.
	 */
	function openReplyForm( threadId, parentId ) {
		var wrap = replyForm( threadId );
		if ( !wrap ) return;

		wrap.setAttribute( 'data-parent-id', parentId || '' );

		var note = wrap.querySelector( '.mw-nexaboard-replying-to' );
		if ( note ) {
			if ( parentId ) {
				note.textContent   = mw.msg( 'nexaboard-replying-to', parentId );
				note.style.display = 'block';
			} else {
				note.style.display = 'none';
			}
		}

		wrap.style.display = 'block';
		wrap.querySelector( '.mw-nexaboard-reply-input' ).focus();
	}

	function initReplyButtons() {
		document.querySelectorAll( '.mw-nexaboard-reply-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var threadId = btn.getAttribute( 'data-thread-id' );
				var wrap     = replyForm( threadId );
				if ( !wrap ) return;

				if ( wrap.style.display !== 'none' && !wrap.getAttribute( 'data-parent-id' ) ) {
					wrap.style.display = 'none';
					return;
				}
				openReplyForm( threadId, null );
			} );
		} );

		// Reply aimed at one particular reply.
		document.querySelectorAll( '.mw-nexaboard-reply-to-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				openReplyForm(
					btn.getAttribute( 'data-thread-id' ),
					btn.getAttribute( 'data-parent-id' )
				);
			} );
		} );

		document.querySelectorAll( '.mw-nexaboard-reply-cancel' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var wrap = replyForm( btn.getAttribute( 'data-thread-id' ) );
				if ( !wrap ) return;
				wrap.style.display = 'none';
				wrap.setAttribute( 'data-parent-id', '' );
				wrap.querySelector( '.mw-nexaboard-reply-input' ).value = '';
				var note = wrap.querySelector( '.mw-nexaboard-replying-to' );
				if ( note ) note.style.display = 'none';
			} );
		} );

		document.querySelectorAll( '.mw-nexaboard-reply-submit' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var threadId = btn.getAttribute( 'data-thread-id' );
				var wrap     = replyForm( threadId );
				var input    = wrap.querySelector( '.mw-nexaboard-reply-input' );
				var body     = input.value.trim();

				if ( !body ) return;

				var params = {
					action:   'nexaboardreply',
					threadid: parseInt( threadId, 10 ),
					body:     body
				};

				var parentId = wrap.getAttribute( 'data-parent-id' );
				if ( parentId ) {
					params.parentid = parseInt( parentId, 10 );
				}

				setLoading( btn, true );

				api.postWithToken( 'csrf', params ).then( function ( data ) {
					var res = data.nexaboardreply;
					reloadTo( res && res.msgid ? 'nexaboard-message-' + res.msgid : null );
				} ).catch( function ( code, data ) {
					fail( wrap, code, data );
					setLoading( btn, false );
				} );
			} );
		} );
	}

	function initToggleReplies() {
		document.querySelectorAll( '.mw-nexaboard-toggle-replies' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var threadId = btn.getAttribute( 'data-thread-id' );
				var replies  = document.querySelector(
					'.mw-nexaboard-replies[data-thread-id="' + threadId + '"]'
				);
				if ( !replies ) return;

				var visible = replies.style.display !== 'none';
				replies.style.display = visible ? 'none' : 'block';
				btn.textContent = visible
					? mw.msg( 'nexaboard-show-replies', btn.getAttribute( 'data-count' ) || '0' )
					: mw.msg( 'nexaboard-hide-replies' );
			} );
		} );
	}

	/**
	 * Collapse and expand a branch of the reply tree. The rail itself is the
	 * control, with a chip left behind to bring the branch back.
	 */
	function initReplyCollapse() {
		function setCollapsed( msgId, collapsed ) {
			var children = document.querySelector(
				'.mw-nexaboard-reply-children[data-msg-id="' + msgId + '"]'
			);
			if ( !children ) return;

			children.classList.toggle( 'is-collapsed', collapsed );

			var rail = children.querySelector( '.mw-nexaboard-thread-line' );
			if ( rail ) rail.setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );

			var chip = children.querySelector( '.mw-nexaboard-expand-chip' );
			if ( chip ) chip.style.display = collapsed ? 'inline-block' : 'none';
		}

		document.querySelectorAll( '.mw-nexaboard-thread-line' ).forEach( function ( rail ) {
			rail.addEventListener( 'click', function () {
				setCollapsed( rail.getAttribute( 'data-msg-id' ), true );
			} );
		} );

		document.querySelectorAll( '.mw-nexaboard-expand-chip' ).forEach( function ( chip ) {
			chip.addEventListener( 'click', function () {
				setCollapsed( chip.getAttribute( 'data-msg-id' ), false );
			} );
		} );
	}

	// ------------------------------------------------------------------ edit

	/**
	 * Swap a rendered message body for a textarea, and put it back on cancel.
	 */
	function initEditButtons() {
		document.querySelectorAll( '.mw-nexaboard-edit-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var msgId = parseInt( btn.getAttribute( 'data-msg-id' ), 10 );
				var isOp  = btn.getAttribute( 'data-is-op' ) === '1';
				var thread = threadEl( btn.getAttribute( 'data-thread-id' ) );
				if ( !thread ) return;

				var bodyEl = thread.querySelector(
					( isOp ? '.mw-nexaboard-thread-body' : '.mw-nexaboard-reply-body' )
					+ '[data-msg-id="' + msgId + '"]'
				);
				if ( !bodyEl || bodyEl.dataset.editing === '1' ) return;

				setLoading( btn, true );

				// Fetch the wikitext rather than reverse-engineering it from the
				// parsed HTML sitting in the page.
				api.get( {
					action:  'query',
					list:    'nexaboardmessages',
					mwmids:  msgId
				} ).catch( function () {
					return null;
				} ).then( function ( data ) {
					var source = null;
					try {
						source = data.query.nexaboardmessages[ 0 ].body;
					} catch ( e ) {
						source = null;
					}
					openEditor( bodyEl, msgId, isOp, thread, btn, source );
				} );
			} );
		} );
	}

	function openEditor( bodyEl, msgId, isOp, thread, btn, source ) {
		var original = bodyEl.innerHTML;
		bodyEl.dataset.editing = '1';

		var titleInput = null;
		var wrap = document.createElement( 'div' );
		wrap.className = 'mw-nexaboard-edit-form';

		if ( isOp ) {
			var titleEl = thread.querySelector( '.mw-nexaboard-thread-title' );
			var label   = document.createElement( 'label' );
			label.textContent = mw.msg( 'nexaboard-edit-title-label' ) + ' ';
			titleInput = document.createElement( 'input' );
			titleInput.type  = 'text';
			titleInput.className = 'mw-nexaboard-edit-title';
			titleInput.value = titleEl ? titleEl.textContent.trim() : '';
			label.appendChild( titleInput );
			wrap.appendChild( label );
		}

		var textarea = document.createElement( 'textarea' );
		textarea.className = 'mw-nexaboard-edit-input';
		// Falls back to the rendered text when the source could not be fetched.
		textarea.value = source !== null && source !== undefined
			? source
			: bodyEl.textContent.trim();
		wrap.appendChild( textarea );

		var actions = document.createElement( 'div' );
		actions.className = 'mw-nexaboard-edit-actions';

		var save   = document.createElement( 'button' );
		save.textContent = mw.msg( 'nexaboard-save-btn' );
		var cancel = document.createElement( 'button' );
		cancel.textContent = mw.msg( 'nexaboard-cancel-btn' );

		actions.appendChild( save );
		actions.appendChild( document.createTextNode( ' ' ) );
		actions.appendChild( cancel );
		wrap.appendChild( actions );

		bodyEl.innerHTML = '';
		bodyEl.appendChild( wrap );
		textarea.focus();

		function restore() {
			bodyEl.innerHTML = original;
			delete bodyEl.dataset.editing;
			setLoading( btn, false );
		}

		cancel.addEventListener( 'click', restore );

		save.addEventListener( 'click', function () {
			var body = textarea.value.trim();
			if ( !body ) {
				showNotice( wrap, 'error', mw.msg( 'nexaboard-error-nobody' ) );
				return;
			}

			var params = {
				action: 'nexaboardedit',
				msgid:  msgId,
				body:   body
			};
			if ( titleInput ) {
				if ( !titleInput.value.trim() ) {
					showNotice( wrap, 'error', mw.msg( 'nexaboard-error-notitle' ) );
					return;
				}
				params.title = titleInput.value.trim();
			}

			setLoading( save, true );

			api.postWithToken( 'csrf', params ).then( function () {
				reloadTo( 'nexaboard-message-' + msgId );
			} ).catch( function ( code, data ) {
				fail( wrap, code, data );
				setLoading( save, false );
			} );
		} );
	}

	// ----------------------------------------------------------- follow state

	function initFollowButtons() {
		document.querySelectorAll( '.mw-nexaboard-follow-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var threadId  = parseInt( btn.getAttribute( 'data-thread-id' ), 10 );
				var following = btn.getAttribute( 'data-following' ) === '1';

				setLoading( btn, true );

				// Same API-boolean rule as above: follow=0 would still read as true,
				// so unfollowing means leaving the parameter off entirely.
				var params = { action: 'nexaboardfollow', threadid: threadId };
				if ( !following ) {
					params.follow = 1;
				}

				api.postWithToken( 'csrf', params ).then( function () {
					var now = !following;
					btn.setAttribute( 'data-following', now ? '1' : '0' );
					btn.textContent = mw.msg(
						now ? 'nexaboard-unfollow-btn' : 'nexaboard-follow-btn'
					);
					setLoading( btn, false );
				} ).catch( function ( code, data ) {
					fail( null, code, data );
					setLoading( btn, false );
				} );
			} );
		} );
	}

	// ------------------------------------------------------- close and delete

	function initCloseButtons() {
		document.querySelectorAll( '.mw-nexaboard-close-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var threadId = parseInt( btn.getAttribute( 'data-thread-id' ), 10 );
				var reopen   = btn.getAttribute( 'data-reopen' ) === '1';

				var confirmMsg = reopen
					? mw.msg( 'nexaboard-reopen-confirm' )
					: mw.msg( 'nexaboard-close-confirm' );
				if ( !window.confirm( confirmMsg ) ) return;

				setLoading( btn, true );

				// An API boolean is true whenever the parameter is present, whatever
				// its value — sending reopen=0 would reopen, not close. Omit it.
				var params = { action: 'nexaboardclose', threadid: threadId };
				if ( reopen ) {
					params.reopen = 1;
				}

				api.postWithToken( 'csrf', params ).then( function () {
					// Closing changes which actions are available, so re-render
					// from the server rather than patching the DOM by hand.
					reloadTo( 'nexaboard-thread-' + threadId );
				} ).catch( function ( code, data ) {
					fail( null, code, data );
					setLoading( btn, false );
				} );
			} );
		} );
	}

	function deleteRequest( btn, params, confirmMsg, fragment ) {
		if ( confirmMsg && !window.confirm( confirmMsg ) ) return;

		setLoading( btn, true );

		params.action = 'nexaboarddelete';
		api.postWithToken( 'csrf', params ).then( function () {
			reloadTo( fragment );
		} ).catch( function ( code, data ) {
			fail( null, code, data );
			setLoading( btn, false );
		} );
	}

	function initThreadDeleteButtons() {
		document.querySelectorAll( '.mw-nexaboard-delete-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var threadId = parseInt( btn.getAttribute( 'data-thread-id' ), 10 );
				deleteRequest(
					btn, { threadid: threadId },
					mw.msg( 'nexaboard-delete-confirm' ), null
				);
			} );
		} );

		document.querySelectorAll( '.mw-nexaboard-undelete-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var threadId = parseInt( btn.getAttribute( 'data-thread-id' ), 10 );
				deleteRequest(
					btn, { threadid: threadId, undo: 1 },
					mw.msg( 'nexaboard-undelete-confirm' ), 'nexaboard-thread-' + threadId
				);
			} );
		} );

		document.querySelectorAll( '.mw-nexaboard-delete-replies-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var threadId = parseInt( btn.getAttribute( 'data-thread-id' ), 10 );
				deleteRequest(
					btn, { threadid: threadId, scope: 'replies' },
					mw.msg( 'nexaboard-delete-replies-confirm' ), 'nexaboard-thread-' + threadId
				);
			} );
		} );
	}

	function initMessageDeleteButtons() {
		document.querySelectorAll( '.mw-nexaboard-delete-msg-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var msgId = parseInt( btn.getAttribute( 'data-msg-id' ), 10 );
				deleteRequest(
					btn, { msgid: msgId },
					mw.msg( 'nexaboard-delete-msg-confirm' ), 'nexaboard-message-' + msgId
				);
			} );
		} );

		document.querySelectorAll( '.mw-nexaboard-undelete-msg-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var msgId = parseInt( btn.getAttribute( 'data-msg-id' ), 10 );
				deleteRequest(
					btn, { msgid: msgId, undo: 1 },
					mw.msg( 'nexaboard-undelete-msg-confirm' ), 'nexaboard-message-' + msgId
				);
			} );
		} );
	}

	// -------------------------------------------------------- thread selection

	/**
	 * One checkbox per thread, shared by the merge panel and the bulk bar so the
	 * page never grows two competing sets of selection controls.
	 */
	var selection = ( function () {
		var users = 0;

		function checkboxes() {
			return document.querySelectorAll( '.mw-nexaboard-select-check' );
		}

		return {
			build: function () {
				document.querySelectorAll( '.mw-nexaboard-thread' ).forEach( function ( el ) {
					if ( el.querySelector( '.mw-nexaboard-select-check' ) ) return;

					var id = el.getAttribute( 'data-thread-id' );

					// A bare checkbox in the corner of a box explains nothing, so
					// it ships inside a label whose text names the current action.
					var label = document.createElement( 'label' );
					label.className = 'mw-nexaboard-select-label';

					var box = document.createElement( 'input' );
					box.type      = 'checkbox';
					box.value     = id;
					box.className = 'mw-nexaboard-select-check';

					var text = document.createElement( 'span' );
					text.className = 'mw-nexaboard-select-text';

					label.appendChild( box );
					label.appendChild( text );
					el.insertBefore( label, el.firstChild );
				} );
			},

			/**
			 * @param {string} labelText What ticking a box will do, e.g. "Merge this thread"
			 */
			show: function ( labelText ) {
				users++;
				document.querySelectorAll( '.mw-nexaboard-thread' ).forEach( function ( el ) {
					el.classList.add( 'mw-nexaboard-selectable' );
				} );
				document.querySelectorAll( '.mw-nexaboard-select-text' ).forEach( function ( t ) {
					t.textContent = labelText;
				} );
				document.querySelectorAll( '.mw-nexaboard-select-check' ).forEach( function ( c ) {
					c.setAttribute( 'aria-label', labelText + ' #' + c.value );
				} );
			},

			hide: function () {
				users = Math.max( 0, users - 1 );
				if ( users > 0 ) return;
				document.querySelectorAll( '.mw-nexaboard-thread' ).forEach( function ( el ) {
					el.classList.remove( 'mw-nexaboard-selectable' );
				} );
			},

			ids: function () {
				return Array.prototype.filter.call( checkboxes(), function ( c ) {
					return c.checked;
				} ).map( function ( c ) {
					return parseInt( c.value, 10 );
				} );
			},

			setAll: function ( checked ) {
				Array.prototype.forEach.call( checkboxes(), function ( c ) {
					c.checked = checked;
				} );
			},

			onChange: function ( fn ) {
				Array.prototype.forEach.call( checkboxes(), function ( c ) {
					c.addEventListener( 'change', fn );
				} );
			}
		};
	}() );

	function initBulkPanel() {
		var toggle  = document.getElementById( 'mw-nexaboard-bulk-toggle' );
		var actions = document.getElementById( 'mw-nexaboard-bulk-actions' );
		if ( !toggle || !actions ) return;

		var count     = document.getElementById( 'mw-nexaboard-bulk-count' );
		var reason    = document.getElementById( 'mw-nexaboard-bulk-reason' );
		var delBtn    = document.getElementById( 'mw-nexaboard-bulk-delete' );
		var repBtn    = document.getElementById( 'mw-nexaboard-bulk-delete-replies' );
		var allBtn    = document.getElementById( 'mw-nexaboard-bulk-all' );
		var noneBtn   = document.getElementById( 'mw-nexaboard-bulk-none' );
		var cancelBtn = document.getElementById( 'mw-nexaboard-bulk-cancel' );

		var open = false;

		function refresh() {
			if ( count ) {
				count.textContent = mw.msg( 'nexaboard-bulk-count', selection.ids().length );
			}
		}

		toggle.addEventListener( 'click', function () {
			if ( open ) return;
			open = true;
			selection.show( mw.msg( 'nexaboard-bulk-select-label' ) );
			selection.onChange( refresh );
			actions.style.display = 'block';
			toggle.style.display  = 'none';
			refresh();
		} );

		function close() {
			if ( !open ) return;
			open = false;
			selection.setAll( false );
			selection.hide();
			actions.style.display = 'none';
			toggle.style.display  = '';
		}

		if ( cancelBtn ) cancelBtn.addEventListener( 'click', close );
		if ( allBtn ) {
			allBtn.addEventListener( 'click', function () { selection.setAll( true ); refresh(); } );
		}
		if ( noneBtn ) {
			noneBtn.addEventListener( 'click', function () { selection.setAll( false ); refresh(); } );
		}

		function bulkDelete( btn, scope, confirmKey ) {
			var ids = selection.ids();
			if ( !ids.length ) return;
			if ( !window.confirm( mw.msg( confirmKey, ids.length ) ) ) return;

			var params = {
				action:   'nexaboarddelete',
				threadid: ids.join( '|' ),
				reason:   reason ? reason.value.trim() : ''
			};
			if ( scope ) params.scope = scope;

			setLoading( btn, true );

			api.postWithToken( 'csrf', params ).then( function () {
				window.location.reload();
			} ).catch( function ( code, data ) {
				fail( actions, code, data );
				setLoading( btn, false );
			} );
		}

		if ( delBtn ) {
			delBtn.addEventListener( 'click', function () {
				bulkDelete( delBtn, null, 'nexaboard-bulk-confirm-threads' );
			} );
		}
		if ( repBtn ) {
			repBtn.addEventListener( 'click', function () {
				bulkDelete( repBtn, 'replies', 'nexaboard-bulk-confirm-replies' );
			} );
		}
	}

	// ------------------------------------------------------------------- move

	function initMoveMsgButtons() {
		var panel = document.getElementById( 'mw-nexaboard-move-panel' );
		if ( !panel ) return;

		var subject   = document.getElementById( 'mw-nexaboard-move-subject' );
		var target    = document.getElementById( 'mw-nexaboard-move-target' );
		var targetId  = document.getElementById( 'mw-nexaboard-move-target-id' );
		var reason    = document.getElementById( 'mw-nexaboard-move-reason' );
		var submitBtn = document.getElementById( 'mw-nexaboard-move-submit' );
		var cancelBtn = document.getElementById( 'mw-nexaboard-move-cancel' );

		var pending = null;

		function closePanel() {
			panel.style.display = 'none';
			pending = null;
			if ( targetId ) targetId.value = '';
			if ( reason ) reason.value = '';
		}

		document.querySelectorAll( '.mw-nexaboard-move-msg-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				pending = {
					msgId:    parseInt( btn.getAttribute( 'data-msg-id' ), 10 ),
					threadId: parseInt( btn.getAttribute( 'data-thread-id' ), 10 )
				};

				if ( subject ) {
					subject.textContent = mw.msg(
						'nexaboard-move-subject', pending.msgId, pending.threadId
					);
				}

				// Don't offer the thread the message already lives in.
				if ( target ) {
					Array.prototype.forEach.call( target.options, function ( opt ) {
						opt.disabled = parseInt( opt.value, 10 ) === pending.threadId;
					} );
					if ( target.selectedOptions.length && target.selectedOptions[ 0 ].disabled ) {
						for ( var i = 0; i < target.options.length; i++ ) {
							if ( !target.options[ i ].disabled ) {
								target.selectedIndex = i;
								break;
							}
						}
					}
				}

				panel.style.display = 'block';
				panel.scrollIntoView( { block: 'nearest' } );
			} );
		} );

		if ( cancelBtn ) cancelBtn.addEventListener( 'click', closePanel );

		submitBtn.addEventListener( 'click', function () {
			if ( !pending ) return;

			// A typed id wins over the dropdown, so threads on other pages of the
			// board stay reachable as destinations.
			var typed = targetId && targetId.value.trim() !== ''
				? parseInt( targetId.value.trim(), 10 )
				: null;
			var destId = typed || parseInt( target.value, 10 );

			if ( !destId || isNaN( destId ) ) {
				showNotice( panel, 'error', mw.msg( 'nexaboard-error-move-thread' ) );
				return;
			}
			if ( destId === pending.threadId ) {
				showNotice( panel, 'error', mw.msg( 'nexaboard-error-move-same' ) );
				return;
			}
			if ( !window.confirm( mw.msg( 'nexaboard-move-confirm' ) ) ) return;

			setLoading( submitBtn, true );

			api.postWithToken( 'csrf', {
				action:       'nexaboardmove',
				msgid:        pending.msgId,
				targetthread: destId,
				reason:       reason ? reason.value.trim() : ''
			} ).then( function () {
				reloadTo( 'nexaboard-message-' + pending.msgId );
			} ).catch( function ( code, data ) {
				fail( panel, code, data );
				setLoading( submitBtn, false );
			} );
		} );
	}

	// ------------------------------------------------------------------ merge

	// ------------------------------------------------ transfer to another board

	function initTransferPanel() {
		var panel = document.getElementById( 'mw-nexaboard-transfer-panel' );
		if ( !panel ) {
			return;
		}

		var subject = document.getElementById( 'mw-nexaboard-transfer-subject' );
		var target  = document.getElementById( 'mw-nexaboard-transfer-target' );
		var reason  = document.getElementById( 'mw-nexaboard-transfer-reason' );
		var submit  = document.getElementById( 'mw-nexaboard-transfer-submit' );
		var cancel  = document.getElementById( 'mw-nexaboard-transfer-cancel' );
		var pending = null;

		function close() {
			panel.style.display = 'none';
			pending = null;
			target.value = '';
			reason.value = '';
		}

		document.querySelectorAll( '.mw-nexaboard-transfer-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				pending = {
					threadId: parseInt( btn.getAttribute( 'data-thread-id' ), 10 ),
					title:    btn.getAttribute( 'data-thread-title' ) || ''
				};
				subject.textContent = mw.msg(
					'nexaboard-transfer-subject', pending.threadId, pending.title
				);
				panel.style.display = 'block';
				panel.scrollIntoView( { behavior: 'smooth', block: 'center' } );
				target.focus();
			} );
		} );

		cancel.addEventListener( 'click', close );

		submit.addEventListener( 'click', function () {
			if ( !pending ) {
				return;
			}

			var name = target.value.trim();
			if ( !name ) {
				showNotice( mw.msg( 'nexaboard-error-transfer-notarget' ), 'error' );
				target.focus();
				return;
			}

			if ( !window.confirm( mw.msg( 'nexaboard-transfer-confirm' ) ) ) {
				return;
			}

			setLoading( submit, true );

			api.postWithToken( 'csrf', {
				action:     'nexaboardtransfer',
				threadid:   pending.threadId,
				targetuser: name,
				reason:     reason.value.trim()
			} ).then( function () {
				// The thread now lives on another board, so there is nothing on this
				// page to scroll back to.
				reloadTo( null );
			} ).catch( function ( code, data ) {
				fail( null, code, data );
				setLoading( submit, false );
			} );
		} );
	}

	function initMergePanel() {
		var panel     = document.getElementById( 'mw-nexaboard-merge-panel' );
		var submitBtn = document.getElementById( 'mw-nexaboard-merge-submit' );
		if ( !panel || !submitBtn ) return;

		var cancelBtn = document.getElementById( 'mw-nexaboard-merge-cancel' );
		var target    = document.getElementById( 'mw-nexaboard-merge-target' );
		var reason    = document.getElementById( 'mw-nexaboard-merge-reason' );
		var trigger   = document.getElementById( 'mw-nexaboard-merge-trigger' );
		var count     = document.getElementById( 'mw-nexaboard-merge-count' );

		var open = false;

		function refresh() {
			if ( !count ) return;
			var targetId = parseInt( target.value, 10 );
			var n = selection.ids().filter( function ( id ) {
				return id !== targetId;
			} ).length;
			count.textContent = mw.msg( 'nexaboard-merge-count', n );
		}

		function close() {
			if ( !open ) return;
			open = false;
			selection.hide();
			panel.style.display = 'none';
		}

		if ( trigger ) {
			trigger.addEventListener( 'click', function () {
				if ( open ) {
					close();
					return;
				}
				open = true;
				selection.show( mw.msg( 'nexaboard-merge-select-label' ) );
				selection.onChange( refresh );
				panel.style.display = 'block';
				refresh();
			} );
		}

		if ( target ) target.addEventListener( 'change', refresh );

		if ( cancelBtn ) cancelBtn.addEventListener( 'click', close );

		submitBtn.addEventListener( 'click', function () {
			var targetId  = parseInt( target.value, 10 );
			var sourceIds = selection.ids().filter( function ( id ) {
				return id !== targetId;
			} );

			if ( !sourceIds.length ) {
				showNotice( panel, 'error', mw.msg( 'nexaboard-error-merge-self' ) );
				return;
			}
			if ( !window.confirm( mw.msg( 'nexaboard-merge-confirm' ) ) ) return;

			setLoading( submitBtn, true );

			api.postWithToken( 'csrf', {
				action:       'nexaboardmerge',
				targetthread: targetId,
				sourcethread: sourceIds.join( '|' ),
				reason:       reason ? reason.value.trim() : ''
			} ).then( function () {
				reloadTo( 'nexaboard-thread-' + targetId );
			} ).catch( function ( code, data ) {
				fail( panel, code, data );
				setLoading( submitBtn, false );
			} );
		} );
	}

	// ----------------------------------------------------------- deep linking

	/**
	 * Highlight whatever thread or reply the URL fragment points at, so a
	 * permalink or a notification deep-link makes it obvious where you landed.
	 */
	function initAnchorHighlight() {
		function highlight() {
			var hash = window.location.hash.replace( /^#/, '' );
			if ( !hash || hash.indexOf( 'nexaboard-' ) !== 0 ) return;

			var el = document.getElementById( hash );
			if ( !el ) return;

			// Open every collapsed branch between the target and the thread.
			var branch = el.parentElement;
			while ( branch && !branch.classList.contains( 'mw-nexaboard-replies' ) ) {
				if ( branch.classList.contains( 'mw-nexaboard-reply-children' ) ) {
					branch.classList.remove( 'is-collapsed' );
					var chip = branch.querySelector( '.mw-nexaboard-expand-chip' );
					if ( chip ) chip.style.display = 'none';
					var rail = branch.querySelector( '.mw-nexaboard-thread-line' );
					if ( rail ) rail.setAttribute( 'aria-expanded', 'true' );
				}
				branch = branch.parentElement;
			}

			// A deep-linked reply may sit inside a collapsed reply list.
			var replies = el.closest( '.mw-nexaboard-replies' );
			if ( replies && replies.style.display === 'none' ) {
				replies.style.display = 'block';
				var toggle = document.querySelector(
					'.mw-nexaboard-toggle-replies[data-thread-id="'
					+ replies.getAttribute( 'data-thread-id' ) + '"]'
				);
				if ( toggle ) toggle.textContent = mw.msg( 'nexaboard-hide-replies' );
			}

			document.querySelectorAll( '.mw-nexaboard-anchor-target' ).forEach( function ( prev ) {
				prev.classList.remove( 'mw-nexaboard-anchor-target' );
			} );
			el.classList.add( 'mw-nexaboard-anchor-target' );
			el.scrollIntoView( { block: 'center' } );
		}

		highlight();
		window.addEventListener( 'hashchange', highlight );
	}

	mw.hook( 'wikipage.content' ).add( function () {
		if ( !root() ) return;

		// Only worth building when something can actually use a selection.
		if (
			document.getElementById( 'mw-nexaboard-bulk' )
			|| document.getElementById( 'mw-nexaboard-merge-panel' )
		) {
			selection.build();
		}

		initNewMessageForm();
		initReplyButtons();
		initToggleReplies();
		initReplyCollapse();
		initEditButtons();
		initFollowButtons();
		initCloseButtons();
		initThreadDeleteButtons();
		initMessageDeleteButtons();
		initBulkPanel();
		initMoveMsgButtons();
		initMergePanel();
		initTransferPanel();
		initAnchorHighlight();
	} );

}() );
