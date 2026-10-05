/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * App shell: view routing, unsaved-changes guard, and global notices.
 *
 * Views: { name: 'list' } | { name: 'new' } | { name: 'edit', id }.
 * Navigation goes through `navigate()` so dirty forms are intercepted
 * with a confirm modal; `beforeunload` covers tab close / reload.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Button, Modal, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import GroupList from './GroupList';
import GroupEditor from './GroupEditor';

export default function App() {
	const [ view, setView ] = useState( { name: 'list' } );
	const [ dirty, setDirty ] = useState( false );
	const [ pendingNav, setPendingNav ] = useState( null );
	const [ notices, setNotices ] = useState( [] );
	const noticeId = useRef( 0 );

	const notify = useCallback( ( message, status = 'success' ) => {
		const id = ++noticeId.current;
		setNotices( ( prev ) => [ ...prev, { id, message, status } ] );
		if ( status === 'success' ) {
			window.setTimeout( () => {
				setNotices( ( prev ) => prev.filter( ( n ) => n.id !== id ) );
			}, 5000 );
		}
	}, [] );

	const dismissNotice = useCallback( ( id ) => {
		setNotices( ( prev ) => prev.filter( ( n ) => n.id !== id ) );
	}, [] );

	/** In-app navigation with unsaved-changes interception. */
	const navigate = useCallback(
		( next, opts = {} ) => {
			if ( dirty && ! opts.force ) {
				setPendingNav( next );
				return;
			}
			setView( next );
			// Scroll the app root into view on navigation.
			document
				.getElementById( 'tk-fields-admin-root' )
				?.scrollIntoView( { block: 'start' } );
		},
		[ dirty ]
	);

	// Warn on reload / tab close with unsaved changes.
	useEffect( () => {
		if ( ! dirty ) {
			return;
		}
		const handler = ( e ) => {
			e.preventDefault();
			e.returnValue = '';
		};
		window.addEventListener( 'beforeunload', handler );
		return () => window.removeEventListener( 'beforeunload', handler );
	}, [ dirty ] );

	const confirmPendingNav = () => {
		setDirty( false );
		setView( pendingNav );
		setPendingNav( null );
	};

	return (
		<div className="tkf-app">
			{ notices.length > 0 && (
				<div className="tkf-notices">
					{ notices.map( ( n ) => (
						<Notice
							key={ n.id }
							status={ n.status }
							onRemove={ () => dismissNotice( n.id ) }
						>
							{ n.message }
						</Notice>
					) ) }
				</div>
			) }

			{ view.name === 'list' && (
				<GroupList navigate={ navigate } notify={ notify } />
			) }
			{ view.name === 'new' && (
				<GroupEditor
					key="new"
					groupId={ null }
					navigate={ navigate }
					setDirty={ setDirty }
					notify={ notify }
				/>
			) }
			{ view.name === 'edit' && (
				<GroupEditor
					key={ `edit-${ view.id }` }
					groupId={ view.id }
					navigate={ navigate }
					setDirty={ setDirty }
					notify={ notify }
				/>
			) }

			{ pendingNav && (
				<Modal
					title={ __( 'Discard unsaved changes?', 'tk-fields' ) }
					onRequestClose={ () => setPendingNav( null ) }
					className="tkf-modal"
				>
					<p>
						{ __(
							'You have unsaved changes. Leaving now will discard them.',
							'tk-fields'
						) }
					</p>
					<div className="tkf-modal__actions">
						<Button
							variant="secondary"
							onClick={ () => setPendingNav( null ) }
						>
							{ __( 'Keep editing', 'tk-fields' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							onClick={ confirmPendingNav }
						>
							{ __( 'Discard changes', 'tk-fields' ) }
						</Button>
					</div>
				</Modal>
			) }
		</div>
	);
}
