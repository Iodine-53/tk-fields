/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * Content Types list screen (v0.15.0): table of content types with search,
 * add, edit, delete, JSON export and JSON import with a pre-import preview.
 */
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { Button, Modal, Notice, Spinner, TextControl, TextareaControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import TKControl from './TKControl';

function downloadJson( doc, filename ) {
	const blob = new Blob( [ JSON.stringify( doc, null, 2 ) ], {
		type: 'application/json',
	} );
	const url = URL.createObjectURL( blob );
	const a = document.createElement( 'a' );
	a.href = url;
	a.download = filename;
	document.body.appendChild( a );
	a.click();
	document.body.removeChild( a );
	window.setTimeout( () => URL.revokeObjectURL( url ), 1000 );
}

function Badges( { type } ) {
	const badges = [];
	if ( type.public ) {
		badges.push( __( 'Public', 'tk-fields' ) );
	} else {
		badges.push( __( 'Private', 'tk-fields' ) );
	}
	if ( type.show_in_rest ) {
		badges.push( 'REST' );
	}
	if ( type.has_archive ) {
		badges.push( __( 'Archive', 'tk-fields' ) );
	}
	if ( type.hierarchical ) {
		badges.push( __( 'Hierarchical', 'tk-fields' ) );
	}
	return <span className="tkf-muted">{ badges.join( ' · ' ) }</span>;
}

export default function ContentTypeList( { navigate, notify } ) {
	const [ types, setTypes ] = useState( null ); // null = loading
	const [ search, setSearch ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ deleting, setDeleting ] = useState( null );
	const [ deleteBusy, setDeleteBusy ] = useState( false );
	const [ importOpen, setImportOpen ] = useState( false );
	const [ importText, setImportText ] = useState( '' );
	const [ importPreview, setImportPreview ] = useState( null );
	const [ importBusy, setImportBusy ] = useState( false );
	const [ importReport, setImportReport ] = useState( null );
	const fileRef = useRef( null );

	const load = () => {
		setError( '' );
		api
			.listContentTypes()
			.then( ( data ) => setTypes( data.content_types || [] ) )
			.catch( ( err ) => {
				setError( err.message );
				setTypes( [] );
			} );
	};

	useEffect( load, [] );

	const filtered = useMemo( () => {
		if ( ! types ) {
			return [];
		}
		const q = search.trim().toLowerCase();
		if ( ! q ) {
			return types;
		}
		return types.filter(
			( t ) =>
				( t.plural || '' ).toLowerCase().includes( q ) ||
				( t.slug || '' ).toLowerCase().includes( q )
		);
	}, [ types, search ] );

	const confirmDelete = () => {
		if ( ! deleting ) {
			return;
		}
		setDeleteBusy( true );
		api
			.deleteContentType( deleting.id )
			.then( () => {
				notify(
					sprintf(
						/* translators: %s: content type label */
						__( 'Content type “%s” deleted. Its posts stay in the database.', 'tk-fields' ),
						deleting.plural
					)
				);
				setDeleting( null );
				load();
			} )
			.catch( ( err ) => notify( err.message, 'error' ) )
			.finally( () => setDeleteBusy( false ) );
	};

	const doExportAll = () => {
		api
			.exportContentTypes()
			.then( ( doc ) => {
				downloadJson( doc, 'tk-content-types.json' );
				notify( __( 'Content types exported.', 'tk-fields' ) );
			} )
			.catch( ( err ) => notify( err.message, 'error' ) );
	};

	const doExportOne = ( type ) => {
		api
			.exportContentTypes( [ type.id ] )
			.then( ( doc ) => {
				downloadJson( doc, `tk-content-type-${ type.slug }.json` );
				notify(
					sprintf(
						/* translators: %s: content type slug */
						__( 'Exported “%s”.', 'tk-fields' ),
						type.slug
					)
				);
			} )
			.catch( ( err ) => notify( err.message, 'error' ) );
	};

	const onFilePicked = ( e ) => {
		const file = e.target.files && e.target.files[ 0 ];
		if ( ! file ) {
			return;
		}
		// Reset first: setImportText() below may flush synchronously, running
		// the preview effect before onload returns. Resetting after the text
		// is set would wipe the freshly computed preview.
		setImportReport( null );
		setImportPreview( null );
		const reader = new FileReader();
		reader.onload = () => {
			setImportText( typeof reader.result === 'string' ? reader.result : '' );
		};
		reader.readAsText( file );
		e.target.value = '';
	};

	// Client-side pre-import preview: parse the document and flag obvious
	// conflicts against the already-loaded list BEFORE anything is sent.
	useEffect( () => {
		if ( ! importOpen || ! importText.trim() ) {
			setImportPreview( null );
			return;
		}
		let doc;
		try {
			doc = JSON.parse( importText );
		} catch ( e ) {
			setImportPreview( { error: __( 'Not valid JSON.', 'tk-fields' ) } );
			return;
		}
		if ( doc.format !== 'tk-content-types' || ! Array.isArray( doc.content_types ) ) {
			setImportPreview( {
				error: __( 'Not a TK Fields content-types export.', 'tk-fields' ),
			} );
			return;
		}
		const existing = new Set( ( types || [] ).map( ( t ) => t.slug ) );
		setImportPreview( {
			items: doc.content_types.map( ( item, i ) => {
				const slug = item && item.slug ? String( item.slug ) : `#${ i }`;
				return {
					label: item && item.plural ? String( item.plural ) : slug,
					slug,
					conflict: existing.has( slug ),
				};
			} ),
		} );
	}, [ importText, importOpen, types ] );

	const doImport = () => {
		let doc;
		try {
			doc = JSON.parse( importText );
		} catch ( e ) {
			notify( __( 'Not valid JSON.', 'tk-fields' ), 'error' );
			return;
		}
		setImportBusy( true );
		api
			.importContentTypes( doc )
			.then( ( report ) => {
				setImportReport( report );
				load();
			} )
			.catch( ( err ) => notify( err.message, 'error' ) )
			.finally( () => setImportBusy( false ) );
	};

	const formatDate = ( value ) => {
		if ( ! value ) {
			return '—';
		}
		const d = new Date( value.replace( ' ', 'T' ) );
		return isNaN( d ) ? value : d.toLocaleString();
	};

	return (
		<div className="tkf-list">
			<div className="tkf-list__toolbar">
				<div className="tkf-list__search">
					<TKControl label={ __( 'Search content types', 'tk-fields' ) }>
						<TextControl
							type="search"
							placeholder={ __( 'Search by label or slug…', 'tk-fields' ) }
							value={ search }
							onChange={ setSearch }
						/>
					</TKControl>
				</div>
				<Button variant="secondary" onClick={ doExportAll }>
					{ __( 'Export all (JSON)', 'tk-fields' ) }
				</Button>
				<Button
					variant="secondary"
					onClick={ () => {
						setImportText( '' );
						setImportReport( null );
						setImportPreview( null );
						setImportOpen( true );
					} }
				>
					{ __( 'Import (JSON)', 'tk-fields' ) }
				</Button>
				<Button
					variant="primary"
					className="tkf-list__add"
					onClick={ () => navigate( { name: 'new' } ) }
				>
					{ __( 'Add New', 'tk-fields' ) }
				</Button>
			</div>

			{ error && (
				<div className="tkf-error-box" role="alert">
					{ error }{ ' ' }
					<Button variant="link" onClick={ load }>
						{ __( 'Retry', 'tk-fields' ) }
					</Button>
				</div>
			) }

			{ types === null && (
				<div className="tkf-loading">
					<Spinner /> { __( 'Loading content types…', 'tk-fields' ) }
				</div>
			) }

			{ types !== null && types.length === 0 && (
				<div className="tkf-empty">
					<div className="tkf-empty__art" aria-hidden="true">
						<span className="dashicons dashicons-archive tkf-empty__icon" aria-hidden="true" />
					</div>
					<h2>{ __( 'No content types yet', 'tk-fields' ) }</h2>
					<p>
						{ __(
							'Content types are custom post types — “Movies”, “Portfolio”, “Events” — with their own admin menu, URLs and archive pages. No code needed.',
							'tk-fields'
						) }
					</p>
					<Button variant="primary" onClick={ () => navigate( { name: 'new' } ) }>
						{ __( 'Create your first content type', 'tk-fields' ) }
					</Button>
				</div>
			) }

			{ filtered.length > 0 && (
				<table className="tkf-table widefat striped">
					<thead>
						<tr>
							<th>{ __( 'Label', 'tk-fields' ) }</th>
							<th>{ __( 'Slug', 'tk-fields' ) }</th>
							<th>{ __( 'Posts', 'tk-fields' ) }</th>
							<th>{ __( 'Taxonomies', 'tk-fields' ) }</th>
							<th>{ __( 'Details', 'tk-fields' ) }</th>
							<th>{ __( 'Modified', 'tk-fields' ) }</th>
							<th className="tkf-table__actions">{ __( 'Actions', 'tk-fields' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ filtered.map( ( t ) => (
							<tr key={ t.id }>
								<td className="tkf-table__title">
									<button
										type="button"
										className="tkf-link-btn"
										onClick={ () => navigate( { name: 'edit', id: t.id } ) }
									>
										{ t.plural || __( '(no label)', 'tk-fields' ) }
									</button>
									{ ! t.registered && (
										<span className="tkf-muted">
											{ ' ' }
											{ __( '(not registered — slug conflict)', 'tk-fields' ) }
										</span>
									) }
								</td>
								<td><code>{ t.slug }</code></td>
								<td>{ t.post_count ?? 0 }</td>
								<td className="tkf-table__location">
									{ t.taxonomies && t.taxonomies.length
										? t.taxonomies.join( ', ' )
										: '—' }
								</td>
								<td><Badges type={ t } /></td>
								<td>{ formatDate( t.modified ) }</td>
								<td className="tkf-table__actions">
									<Button
										variant="link"
										onClick={ () => navigate( { name: 'edit', id: t.id } ) }
									>
										{ __( 'Edit', 'tk-fields' ) }
									</Button>
									<span aria-hidden="true"> | </span>
									<Button variant="link" onClick={ () => doExportOne( t ) }>
										{ __( 'Export', 'tk-fields' ) }
									</Button>
									<span aria-hidden="true"> | </span>
									<Button
										variant="link"
										className="tkf-link-btn--danger"
										onClick={ () => setDeleting( t ) }
									>
										{ __( 'Delete', 'tk-fields' ) }
									</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ types !== null && types.length > 0 && filtered.length === 0 && (
				<p className="tkf-muted">
					{ sprintf(
						/* translators: %s: search query */
						__( 'No content types match “%s”.', 'tk-fields' ),
						search.trim()
					) }
				</p>
			) }

			{ deleting && (
				<Modal
					title={ __( 'Delete content type?', 'tk-fields' ) }
					onRequestClose={ () => ( deleteBusy ? null : setDeleting( null ) ) }
					className="tkf-modal"
				>
					<p>
						{ sprintf(
							/* translators: %s: content type label */
							__(
								'“%s” will stop registering as a post type. Posts already created stay in the database and become accessible again if a type with the same slug is re-created.',
								'tk-fields'
							),
							deleting.plural
						) }
					</p>
					<div className="tkf-modal__actions">
						<Button
							variant="secondary"
							onClick={ () => setDeleting( null ) }
							disabled={ deleteBusy }
						>
							{ __( 'Cancel', 'tk-fields' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							isBusy={ deleteBusy }
							disabled={ deleteBusy }
							onClick={ confirmDelete }
						>
							{ __( 'Delete', 'tk-fields' ) }
						</Button>
					</div>
				</Modal>
			) }

			{ importOpen && (
				<Modal
					title={ __( 'Import content types', 'tk-fields' ) }
					onRequestClose={ () => ( importBusy ? null : setImportOpen( false ) ) }
					className="tkf-modal tkf-modal--wide"
				>
					{ ! importReport && (
						<>
							<TKControl
								label={ __( 'Export JSON', 'tk-fields' ) }
								tooltip={ __(
									'Paste a TK Fields content-types export, or pick the .json file. Existing slugs are never overwritten — conflicts are skipped and reported.',
									'tk-fields'
								) }
							>
								<TextareaControl
									rows={ 6 }
									value={ importText }
									onChange={ setImportText }
									placeholder='{"format":"tk-content-types","version":1,…}'
								/>
							</TKControl>
							<p>
								<Button variant="secondary" onClick={ () => fileRef.current?.click() }>
									{ __( 'Choose file…', 'tk-fields' ) }
								</Button>
								<input
									ref={ fileRef }
									type="file"
									accept=".json,application/json"
									style={ { display: 'none' } }
									onChange={ onFilePicked }
								/>
							</p>
							{ importPreview && importPreview.error && (
								<Notice status="error" isDismissible={ false }>
									{ importPreview.error }
								</Notice>
							) }
							{ importPreview && importPreview.items && (
								<div className="tkf-import-preview">
									<p>
										<strong>
											{ sprintf(
												/* translators: %d: number of content types */
												__( '%d content type(s) found:', 'tk-fields' ),
												importPreview.items.length
											) }
										</strong>
									</p>
									<ul>
										{ importPreview.items.map( ( item, i ) => (
											<li key={ i }>
												<code>{ item.slug }</code> — { item.label }{ ' ' }
												{ item.conflict && (
													<span className="tkf-muted">
														({ __( 'slug exists — will be skipped', 'tk-fields' ) })
													</span>
												) }
											</li>
										) ) }
									</ul>
								</div>
							) }
						</>
					) }

					{ importReport && (
						<div className="tkf-import-report">
							{ importReport.created.length > 0 && (
								<Notice status="success" isDismissible={ false }>
									{ sprintf(
										/* translators: %d: count */
										__( 'Created %d content type(s).', 'tk-fields' ),
										importReport.created.length
									) }
								</Notice>
							) }
							{ importReport.skipped.map( ( s, i ) => (
								<Notice key={ i } status="warning" isDismissible={ false }>
									{ s.label }: { s.reason }
								</Notice>
							) ) }
							{ importReport.warnings.map( ( w, i ) => (
								<Notice key={ `w${ i }` } status="warning" isDismissible={ false }>
									{ w }
								</Notice>
							) ) }
							{ importReport.created.length === 0 && importReport.skipped.length === 0 && (
								<Notice status="info" isDismissible={ false }>
									{ __( 'Nothing to import.', 'tk-fields' ) }
								</Notice>
							) }
						</div>
					) }

					<div className="tkf-modal__actions">
						<Button
							variant="secondary"
							onClick={ () => setImportOpen( false ) }
							disabled={ importBusy }
						>
							{ importReport ? __( 'Close', 'tk-fields' ) : __( 'Cancel', 'tk-fields' ) }
						</Button>
						{ ! importReport && (
							<Button
								variant="primary"
								isBusy={ importBusy }
								disabled={ importBusy || ! importPreview || !! importPreview.error }
								onClick={ doImport }
							>
								{ __( 'Import', 'tk-fields' ) }
							</Button>
						) }
					</div>
				</Modal>
			) }
		</div>
	);
}
