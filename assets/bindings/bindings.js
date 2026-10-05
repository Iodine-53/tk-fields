/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * TK Fields — Block Bindings editor source (`tk-fields/field`).
 *
 * Client half of includes/class-block-bindings.php. Registers the editor-side
 * callbacks (getValues / setValues / canUserEditValue); the source's label
 * and usesContext are preloaded by core from the server registration, so
 * only `name` plus the callbacks are registered here.
 *
 * Classic script on wp.* globals — no build step, no bundled React.
 *
 * getValues() is called SYNCHRONOUSLY inside the block editor's useSelect, so
 * it cannot await apiFetch. Instead it reads from a tiny wp.data store that
 * acts as a reactive cache: missing fields are batch-fetched (debounced, one
 * request per post per tick) and the dispatch re-renders the blocks when the
 * values land.
 *
 * Unset fields resolve to null everywhere (same as the server's
 * get_value_callback). In the editor, a null value shows the block's original
 * content — exactly what the visitor sees on the frontend — instead of an
 * empty block.
 *
 * @package TK\Fields
 */

( function () {
	'use strict';

	var wp = window.wp || {};
	var blocks = wp.blocks;
	var apiFetch = wp.apiFetch;
	var data = wp.data;
	var i18n = wp.i18n;

	if (
		! blocks ||
		typeof blocks.registerBlockBindingsSource !== 'function' ||
		! apiFetch ||
		! data ||
		typeof data.createReduxStore !== 'function'
	) {
		// Editor too old for the Bindings API (needs WP 6.5+); the server
		// source still works on the frontend.
		return;
	}

	var __ = i18n && i18n.__ ? i18n.__ : function ( s ) { return s; };

	var SOURCE_NAME = 'tk-fields/field';
	var STORE_KEY = 'tk-fields/bindings-values';

	// ------------------------------------------------------------------
	// Reactive value cache (wp.data store).
	// state: { [postId]: { [field]: string|null } }
	// A missing key means "not loaded yet"; an explicit null means "unset".
	// ------------------------------------------------------------------

	var RECEIVE_FIELD_VALUES = 'RECEIVE_FIELD_VALUES';
	var INVALIDATE_FIELD_VALUES = 'INVALIDATE_FIELD_VALUES';

	var storeDescriptor = data.createReduxStore( STORE_KEY, {
		reducer: function ( state, action ) {
			state = state || {};
			switch ( action.type ) {
				case RECEIVE_FIELD_VALUES:
					return Object.assign( {}, state, {
						[ action.postId ]: Object.assign(
							{},
							state[ action.postId ],
							action.values
						),
					} );
				case INVALIDATE_FIELD_VALUES:
					if ( ! state[ action.postId ] ) {
						return state;
					}
					var next = Object.assign( {}, state[ action.postId ] );
					action.fields.forEach( function ( field ) {
						delete next[ field ];
					} );
					return Object.assign( {}, state, {
						[ action.postId ]: next,
					} );
				default:
					return state;
			}
		},
		actions: {
			receiveFieldValues: function ( postId, values ) {
				return {
					type: RECEIVE_FIELD_VALUES,
					postId: postId,
					values: values,
				};
			},
			invalidateFieldValues: function ( postId, fields ) {
				return {
					type: INVALIDATE_FIELD_VALUES,
					postId: postId,
					fields: fields,
				};
			},
		},
		selectors: {
			getFieldValue: function ( state, postId, field ) {
				var postValues = state[ postId ];
				return postValues ? postValues[ field ] : undefined;
			},
		},
	} );
	data.register( storeDescriptor );

	function selectStore() {
		return data.select( STORE_KEY );
	}

	function dispatchStore() {
		return data.dispatch( STORE_KEY );
	}

	// ------------------------------------------------------------------
	// Batched fetching. getValues() only queues; one debounced request per
	// post per tick carries every field the visible blocks need.
	// ------------------------------------------------------------------

	var pendingFields = {}; // postId -> [ field, ... ]
	var flushScheduled = false;

	function queueFieldFetch( postId, field ) {
		if ( ! postId || typeof field !== 'string' || ! field ) {
			return;
		}
		var list = pendingFields[ postId ];
		if ( ! list ) {
			list = [];
			pendingFields[ postId ] = list;
		}
		if ( list.indexOf( field ) === -1 ) {
			list.push( field );
		}
		if ( ! flushScheduled ) {
			flushScheduled = true;
			setTimeout( flushFieldQueue, 50 );
		}
	}

	function flushFieldQueue() {
		flushScheduled = false;
		var batch = pendingFields;
		pendingFields = {};

		Object.keys( batch ).forEach( function ( postId ) {
			var needed = batch[ postId ].filter( function ( field ) {
				return selectStore().getFieldValue( postId, field ) === undefined;
			} );
			if ( ! needed.length ) {
				return;
			}

			apiFetch( {
				path:
					'/tk/v1/values/' +
					encodeURIComponent( postId ) +
					'?fields=' +
					needed.map( encodeURIComponent ).join( ',' ),
			} ).then( function ( response ) {
				var values = ( response && response.values ) || {};
				var fresh = {};
				// Guarantee a cache entry for every requested field: a field
				// the server didn't return is treated as unset (null), never
				// left pending. Only fill fields that are STILL unloaded —
				// an optimistic write (or pump response) may have landed
				// while this fetch was in flight, and newer state must never
				// be overwritten with older (same corruption class the write
				// pump fixes).
				needed.forEach( function ( field ) {
					if ( selectStore().getFieldValue( postId, field ) === undefined ) {
						fresh[ field ] = Object.prototype.hasOwnProperty.call( values, field )
							? values[ field ]
							: null;
					}
				} );
				if ( Object.keys( fresh ).length ) {
					dispatchStore().receiveFieldValues( postId, fresh );
				}
			} ).catch( function () {
				// On failure resolve everything to null so the editor falls
				// back to the block's original content instead of hanging —
				// but only for fields nobody has written since.
				var fresh = {};
				needed.forEach( function ( field ) {
					if ( selectStore().getFieldValue( postId, field ) === undefined ) {
						fresh[ field ] = null;
					}
				} );
				if ( Object.keys( fresh ).length ) {
					dispatchStore().receiveFieldValues( postId, fresh );
				}
			} );
		} );
	}

	function bindingField( binding ) {
		var args = binding && binding.args;
		var field = args && args.field;
		return typeof field === 'string' && field ? field : null;
	}

	/**
	 * Normalize a binding's newValue to a storable string.
	 *
	 * Gutenberg's RichText hands its INTERNAL value object (a String-like
	 * with a `text` property — not a plain string) for the first edit of an
	 * empty bound block; every later edit arrives as a plain string.
	 * String-coercing the object serializes it to exactly the HTML the
	 * attribute expects, format tags included (verified: '<strong>Bold</strong>
	 * pasted' survives intact). The previous code mapped any non-primitive
	 * to null, so the first keystroke (or paste) into an empty bound block
	 * was POSTed as null and the write resolution wiped the user's input
	 * from the display — silent first-character data loss.
	 */
	function normalizeNewValue( value ) {
		if ( typeof value === 'string' ) {
			return value;
		}
		if ( typeof value === 'number' || typeof value === 'boolean' ) {
			return String( value );
		}
		if ( value && typeof value === 'object' ) {
			var coerced = '';
			try {
				coerced = String( value );
			} catch ( e ) {
				coerced = '';
			}
			if ( '[object Object]' === coerced && typeof value.text === 'string' ) {
				coerced = value.text;
			}
			return coerced;
		}
		return null;
	}

	function originalAttributeValue( select, clientId, attributeName ) {
		var fallback = null;
		try {
			var block = clientId
				? select( 'core/block-editor' ).getBlock( clientId )
				: null;
			var attributes = ( block && block.attributes ) || {};
			if ( typeof attributes[ attributeName ] !== 'undefined' ) {
				fallback = attributes[ attributeName ];
			}
		} catch ( e ) {
			// Store not ready yet; fall back to null.
		}
		return fallback;
	}

	// ------------------------------------------------------------------
	// Source callbacks.
	// ------------------------------------------------------------------

	/**
	 * Synchronous — called inside the editor's useSelect. Reads the cache;
	 * missing fields are queued for a batched fetch and the block's original
	 * content is shown meanwhile (and permanently when the field is unset).
	 */
	function getValues( args ) {
		var select = args.select;
		var context = args.context || {};
		var clientId = args.clientId;
		var bindings = args.bindings || {};
		var postId = context.postId;
		var values = {};

		Object.keys( bindings ).forEach( function ( attributeName ) {
			var field = bindingField( bindings[ attributeName ] );
			var fallback = originalAttributeValue( select, clientId, attributeName );

			if ( ! postId || ! field ) {
				values[ attributeName ] = fallback;
				return;
			}

			var cached = select( STORE_KEY ).getFieldValue( postId, field );
			if ( cached === undefined ) {
				queueFieldFetch( postId, field );
				values[ attributeName ] = fallback;
			} else {
				// null = unset: show the authored content, exactly like the
				// server render does when get_value_callback returns null.
				values[ attributeName ] = cached === null ? fallback : cached;
			}
		} );

		return values;
	}

	// ------------------------------------------------------------------
	// Serialized write pump. Strictly one in-flight POST per post; edits
	// that land while a request is away coalesce into `pending` and are
	// sent when the in-flight request completes.
	//
	// Why this exists: setValues() used to fire one POST per keystroke and
	// every response unconditionally overwrote the cache with its own
	// request's value. Under rapid typing the response for keystroke N
	// arrives after the optimistic update for keystroke N+2, yanking the
	// RichText value prop BACKWARD; the next onChange is then computed
	// against the yanked state and the stored value corrupts (duplicated
	// or reordered characters — e.g. "Dune: Part Two" stored as
	// "Dune: Part TwoDune: Part Two"). The pump guarantees:
	//   1. the server receives values in the order the user produced them
	//      (one in-flight request per post, next sent only after the
	//      previous response), and
	//   2. a response is applied to the cache only when no newer value is
	//      already queued — the cache never moves backward.
	// Every payload carries the FULL field value (never a delta), so
	// coalescing intermediate keystrokes loses nothing.
	// ------------------------------------------------------------------

	var writePump = {}; // postId -> { inFlight: bool, pending: { field: string } }

	// Server normalizations (e.g. trimmed whitespace) that arrived while the
	// user was typing. Applied when the user moves on (see the selection
	// subscription at the bottom), never mid-typing.
	var pendingNormalizations = {}; // postId -> { field: serverValue }

	function pumpState( postId ) {
		var st = writePump[ postId ];
		if ( ! st ) {
			st = { inFlight: false, pending: {} };
			writePump[ postId ] = st;
		}
		return st;
	}

	function stashNormalization( postId, field, serverValue ) {
		if ( ! pendingNormalizations[ postId ] ) {
			pendingNormalizations[ postId ] = {};
		}
		pendingNormalizations[ postId ][ field ] = serverValue;
	}

	function clearStash( postId, field ) {
		var map = pendingNormalizations[ postId ];
		if ( map ) {
			delete map[ field ];
		}
	}

	function pumpWrites( postId ) {
		var st = pumpState( postId );
		if ( st.inFlight ) {
			return;
		}
		var batch = st.pending;
		st.pending = {};
		var fields = Object.keys( batch );
		if ( ! fields.length ) {
			return;
		}
		// Newer input supersedes any deferred server normalization.
		fields.forEach( function ( field ) {
			clearStash( postId, field );
		} );
		st.inFlight = true;

		apiFetch( {
			path: '/tk/v1/values/' + encodeURIComponent( postId ),
			method: 'POST',
			data: { values: batch },
		} ).then( function ( response ) {
			st.inFlight = false;
			try {
				var updated = ( response && response.updated ) || {};
				var failed = ( response && response.failed ) || {};
				var refetch = [];

				fields.forEach( function ( field ) {
					if ( Object.prototype.hasOwnProperty.call( st.pending, field ) ) {
						// A newer value was queued while this request was
						// away; its own response will reconcile. Never apply
						// the stale one.
						return;
					}
					if ( Object.prototype.hasOwnProperty.call( failed, field ) ) {
						clearStash( postId, field );
						refetch.push( field );
					} else if ( Object.prototype.hasOwnProperty.call( updated, field ) ) {
						if ( updated[ field ] === batch[ field ] ) {
							// Server agrees with what we sent; the optimistic
							// cache is already correct. Nothing to do.
							return;
						}
						// Server normalized the value (e.g. trimmed
						// whitespace). Do NOT yank the display mid-typing —
						// stash the server truth and converge when the user
						// moves on (selection subscription below). The stored
						// value is already correct: every write carries the
						// full value, so the final keystroke lands verbatim.
						stashNormalization( postId, field, updated[ field ] );
					}
					// Otherwise: no news; the optimistic value stands.
				} );

				if ( refetch.length ) {
					// Rejected: show the stored value again.
					dispatchStore().invalidateFieldValues( postId, refetch );
					refetch.forEach( function ( field ) {
						queueFieldFetch( postId, field );
					} );
				}
			} finally {
				// Send anything that queued while we were away. In `finally`
				// so a handler bug can never wedge the pump (that would be
				// a new data-loss bug).
				pumpWrites( postId );
			}
		} ).catch( function () {
			st.inFlight = false;
			try {
				var refetch = fields.filter( function ( field ) {
					return ! Object.prototype.hasOwnProperty.call( st.pending, field );
				} );
				refetch.forEach( function ( field ) {
					clearStash( postId, field );
				} );
				if ( refetch.length ) {
					// Whole request failed and nothing newer is queued: fall
					// back to the stored value so the editor stays honest.
					dispatchStore().invalidateFieldValues( postId, refetch );
					refetch.forEach( function ( field ) {
						queueFieldFetch( postId, field );
					} );
				}
			} finally {
				pumpWrites( postId );
			}
		} );
	}

	/**
	 * Writes the user's edits back through the REST endpoint. Applies an
	 * optimistic update, then funnels the write through the serialized pump
	 * above — raw per-keystroke POSTs raced and corrupted values.
	 */
	function setValues( args ) {
		var context = args.context || {};
		var bindings = args.bindings || {};
		var postId = context.postId;
		if ( ! postId ) {
			return;
		}

		var values = {};
		Object.keys( bindings ).forEach( function ( attributeName ) {
			var binding = bindings[ attributeName ] || {};
			var field = bindingField( binding );
			if ( ! field ) {
				return;
			}
			if ( typeof binding.newValue === 'undefined' ) {
				return;
			}
			values[ field ] = binding.newValue;
		} );

		var fields = Object.keys( values );
		if ( ! fields.length ) {
			return;
		}

		// Optimistic update so the typed value appears immediately. This is
		// always the user's latest intent; the pump below guarantees the
		// cache never moves backward from here. newValue is normalized
		// because Gutenberg may hand a RichText value object (see
		// normalizeNewValue) — never store the raw object.
		var optimistic = {};
		fields.forEach( function ( field ) {
			optimistic[ field ] = normalizeNewValue( values[ field ] );
		} );
		dispatchStore().receiveFieldValues( postId, optimistic );

		// Queue through the serialized write pump instead of firing a raw
		// per-keystroke POST (those raced and corrupted values).
		var st = pumpState( postId );
		fields.forEach( function ( field ) {
			st.pending[ field ] = optimistic[ field ];
		} );
		pumpWrites( postId );
	}

	/**
	 * A bound block is editable only when there is a real post + field and
	 * the current user can update the post (mirrors the REST permission
	 * check, which requires `edit_post`).
	 */
	function canUserEditValue( args ) {
		var select = args.select;
		var context = args.context || {};
		var bindingArgs = args.args || {};

		if ( ! context.postId ) {
			return false;
		}
		if ( typeof bindingArgs.field !== 'string' || ! bindingArgs.field ) {
			return false;
		}
		return !! select( 'core' ).canUser( 'update', 'posts', context.postId );
	}

	blocks.registerBlockBindingsSource( {
		name: SOURCE_NAME,
		// label + usesContext are preloaded by core from the server
		// registration; passing them again would trigger an override warning.
		getValues: getValues,
		setValues: setValues,
		canUserEditValue: canUserEditValue,
	} );

	// ------------------------------------------------------------------
	// Deferred normalization reconcile. While the user is typing in a
	// bound block the editor shows exactly what they typed (server
	// normalizations stay stashed). Once the block selection moves on,
	// the display converges to server truth.
	// ------------------------------------------------------------------

	var lastSelectedClientId = null;

	function reconcileNormalizations() {
		var selected = null;
		try {
			selected = data.select( 'core/block-editor' ).getSelectedBlockClientId();
		} catch ( e ) {
			return;
		}
		if ( selected === lastSelectedClientId ) {
			return;
		}
		lastSelectedClientId = selected;

		Object.keys( pendingNormalizations ).forEach( function ( postId ) {
			var st = pumpState( postId );
			if ( st.inFlight ) {
				return; // its response will stash anew
			}
			var stashed = pendingNormalizations[ postId ];
			var apply = {};
			Object.keys( stashed ).forEach( function ( field ) {
				if ( Object.prototype.hasOwnProperty.call( st.pending, field ) ) {
					return; // newer input queued; its response will decide
				}
				apply[ field ] = stashed[ field ];
				delete stashed[ field ];
			} );
			if ( Object.keys( apply ).length ) {
				dispatchStore().receiveFieldValues( postId, apply );
			}
		} );
	}

	if ( data.subscribe ) {
		data.subscribe( reconcileNormalizations );
	}

	// Exported for tests / debugging only.
	window.tkFieldsBindings = {
		SOURCE_NAME: SOURCE_NAME,
		STORE_KEY: STORE_KEY,
	};
} )();
