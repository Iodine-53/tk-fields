/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * Group list screen: table of field groups with search, add, edit, delete.
 */
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import {
	Button,
	Modal,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import TKControl from './TKControl';
import { StatusPill } from './ui';
import AiImportModal from './AiImportModal';

/**
 * Human-readable summary of a group's location rules,
 * e.g. "Post type = Post, Page +1" or "Page template = X".
 */
export function locationSummary( location = [] ) {
	if ( ! location.length ) {
		return __( 'Everywhere', 'tk-fields' );
	}
	const paramLabels = {
		post_type: __( 'Post type', 'tk-fields' ),
		page_template: __( 'Page template', 'tk-fields' ),
	};
	const first = location[ 0 ];
	const paramLabel = paramLabels[ first.param ] || first.param;
	const op = first.operator === '!=' ? '≠' : '=';
	const text = `${ paramLabel } ${ op } ${ first.value }`;
	if ( location.length > 1 ) {
		return sprintf(
			/* translators: %1$s: first rule summary, %2$d: remaining rule count */
			__( '%1$s +%2$d more', 'tk-fields' ),
			text,
			location.length - 1
		);
	}
	return text;
}

export default function GroupList( { navigate, notify } ) {
	const [ groups, setGroups ] = useState( null ); // null = loading
	const [ details, setDetails ] = useState( {} ); // id -> full group (for location summary)
	const [ search, setSearch ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ deleting, setDeleting ] = useState( null ); // group pending delete confirm
	const [ deleteBusy, setDeleteBusy ] = useState( false );
	const [ importOpen, setImportOpen ] = useState( false );
	const [ importBusy, setImportBusy ] = useState( false );
	const [ importReport, setImportReport ] = useState( null );
	// No-ACF fallback: AI transformer prompt state.
	const [ aiPrompt, setAiPrompt ] = useState( '' );
	const [ aiPromptError, setAiPromptError ] = useState( '' );
	const [ aiPromptBusy, setAiPromptBusy ] = useState( false );
	const [ aiPromptCopied, setAiPromptCopied ] = useState( false );
	const promptRef = useRef( null );
	const [ aiImportOpen, setAiImportOpen ] = useState( false );

	const load = () => {
		setError( '' );
		api
			.listGroups()
			.then( ( data ) => {
				const list = data.groups || [];
				setGroups( list );
				// Fetch full groups lazily so the location column can
				// summarize rules. Failures degrade to "—", never block.
				list.forEach( ( g ) => {
					api
						.getGroup( g.id )
						.then( ( d ) => {
							if ( d.group ) {
								setDetails( ( prev ) => ( { ...prev, [ g.id ]: d.group } ) );
							}
						} )
						.catch( () => {} );
				} );
			} )
			.catch( ( err ) => {
				setError( err.message );
				setGroups( [] );
			} );
	};

	useEffect( load, [] );

	const filtered = useMemo( () => {
		if ( ! groups ) {
			return [];
		}
		const q = search.trim().toLowerCase();
		if ( ! q ) {
			return groups;
		}
		return groups.filter( ( g ) => ( g.title || '' ).toLowerCase().includes( q ) );
	}, [ groups, search ] );

	const confirmDelete = () => {
		if ( ! deleting ) {
			return;
		}
		setDeleteBusy( true );
		api
			.deleteGroup( deleting.id )
			.then( () => {
				notify( sprintf(
					/* translators: %s: group title */
					__( 'Field group “%s” deleted.', 'tk-fields' ),
					deleting.title
				) );
				setDeleting( null );
				load();
			} )
			.catch( ( err ) => {
				notify( err.message, 'error' );
			} )
			.finally( () => setDeleteBusy( false ) );
	};

	const formatDate = ( value ) => {
		if ( ! value ) {
			return '—';
		}
		const d = new Date( value.replace( ' ', 'T' ) );
		return isNaN( d ) ? value : d.toLocaleString();
	};

	// No-ACF fallback: fetch the server-generated transformer prompt.
	const loadAiPrompt = () => {
		setAiPromptBusy( true );
		setAiPromptError( '' );
		api
			.acfAiPrompt()
			.then( ( data ) => setAiPrompt( data.prompt || '' ) )
			.catch( ( err ) => setAiPromptError( err.message ) )
			.finally( () => setAiPromptBusy( false ) );
	};

	const copyAiPrompt = async () => {
		try {
			await navigator.clipboard.writeText( aiPrompt );
		} catch ( e ) {
			// Clipboard API unavailable (permissions) — fall back to
			// selecting the textarea so the user can copy manually.
			promptRef.current?.select?.();
			notify( __( 'Copy the prompt manually (clipboard access was blocked).', 'tk-fields' ), 'error' );
			return;
		}
		setAiPromptCopied( true );
		setTimeout( () => setAiPromptCopied( false ), 2000 );
	};

	return (
		<div className="tkf-list">
			<div className="tkf-list__toolbar">
				<div className="tkf-list__search">
					<TKControl label={ __( 'Search field groups', 'tk-fields' ) }>
						<TextControl
							type="search"
							placeholder={ __( 'Search by title…', 'tk-fields' ) }
							value={ search }
							onChange={ setSearch }
						/>
					</TKControl>
				</div>
				<Button
					variant="secondary"
					onClick={ () => {
						setImportReport( null );
						setImportOpen( true );
					} }
				>
					{ __( 'Import from ACF', 'tk-fields' ) }
				</Button>
				<Button
					variant="secondary"
					onClick={ () => setAiImportOpen( true ) }
					title={ __(
						'Generate a field group with an AI assistant: copy the prompt, paste the AI\'s JSON back, preview, and import.',
						'tk-fields'
					) }
				>
					{ __( 'Import from AI', 'tk-fields' ) }
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

			{ groups === null && (
				<div className="tkf-loading">
					<Spinner /> { __( 'Loading field groups…', 'tk-fields' ) }
				</div>
			) }

			{ groups !== null && groups.length === 0 && (
				<div className="tkf-empty">
					<div className="tkf-empty__art" aria-hidden="true">
						<span className="dashicons dashicons-category tkf-empty__icon" aria-hidden="true" />
					</div>
					<h2>{ __( 'No field groups yet', 'tk-fields' ) }</h2>
					<p>
						{ __(
							'Field groups bundle related fields together and decide where they appear — for example, an “Event details” group that shows on every Event post.',
							'tk-fields'
						) }
					</p>
					<Button variant="primary" onClick={ () => navigate( { name: 'new' } ) }>
						{ __( 'Create your first field group', 'tk-fields' ) }
					</Button>
				</div>
			) }

			{ filtered.length > 0 && (
				<table className="tkf-table widefat striped">
					<thead>
						<tr>
							<th>{ __( 'Title', 'tk-fields' ) }</th>
							<th>{ __( 'Status', 'tk-fields' ) }</th>
							<th>{ __( 'Fields', 'tk-fields' ) }</th>
							<th>{ __( 'Location', 'tk-fields' ) }</th>
							<th>{ __( 'Modified', 'tk-fields' ) }</th>
							<th className="tkf-table__actions">
								{ __( 'Actions', 'tk-fields' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ filtered.map( ( g ) => {
							const full = details[ g.id ];
							return (
								<tr key={ g.id }>
									<td className="tkf-table__title">
										<button
											type="button"
											className="tkf-link-btn"
											onClick={ () => navigate( { name: 'edit', id: g.id } ) }
										>
											{ g.title || __( '(no title)', 'tk-fields' ) }
										</button>
									</td>
									<td>
										<StatusPill status={ g.status } />
									</td>
									<td>{ g.field_count ?? 0 }</td>
									<td className="tkf-table__location">
										{ full ? locationSummary( full.location ) : '…' }
									</td>
									<td>{ formatDate( g.modified ) }</td>
									<td className="tkf-table__actions">
										<Button
											variant="link"
											onClick={ () => navigate( { name: 'edit', id: g.id } ) }
										>
											{ __( 'Edit', 'tk-fields' ) }
										</Button>
										<span aria-hidden="true"> | </span>
										<Button
											variant="link"
											className="tkf-link-btn--danger"
											onClick={ () => setDeleting( g ) }
										>
											{ __( 'Delete', 'tk-fields' ) }
										</Button>
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			) }

			{ groups !== null && groups.length > 0 && filtered.length === 0 && (
				<p className="tkf-muted">
					{ sprintf(
						/* translators: %s: search query */
						__( 'No field groups match “%s”.', 'tk-fields' ),
						search.trim()
					) }
				</p>
			) }

			{ deleting && (
				<Modal
					title={ __( 'Delete field group?', 'tk-fields' ) }
					onRequestClose={ () => ( deleteBusy ? null : setDeleting( null ) ) }
					className="tkf-modal"
				>
					<p>
						{ sprintf(
							/* translators: %s: group title */
							__(
								'“%s” and all of its fields will be permanently deleted. Values already saved on posts are left untouched.',
								'tk-fields'
							),
							deleting.title
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
							{ __( 'Delete permanently', 'tk-fields' ) }
						</Button>
					</div>
				</Modal>
			) }

			{ importOpen && (
				<Modal
					title={ __( 'Import from ACF', 'tk-fields' ) }
					onRequestClose={ () => ( importBusy ? null : setImportOpen( false ) ) }
					className="tkf-modal"
				>
					{ ! importReport && (
						<>
							<p>
								{ __(
									'How it works: TK Fields reads field groups straight from the ACF plugin on this site — no export file needed. Field names, types, choices, location rules and layouts are converted to native TK Fields groups. Anything that cannot be converted is skipped and listed in the report, with the reason.',
									'tk-fields'
								) }
							</p>
							<p className="tkf-muted">
								{ __(
									'Field values already saved on posts are NOT migrated — only the group definitions.',
									'tk-fields'
								) }
							</p>
							<div className="tkf-modal__actions">
								<Button
									variant="secondary"
									onClick={ () => setImportOpen( false ) }
									disabled={ importBusy }
								>
									{ __( 'Cancel', 'tk-fields' ) }
								</Button>
								<Button
									variant="primary"
									isBusy={ importBusy }
									disabled={ importBusy }
									onClick={ () => {
										setImportBusy( true );
										api
											.importAcf()
											.then( ( data ) => {
												setImportReport( data.report || {} );
												load();
											} )
											.catch( ( err ) => {
												notify( err.message, 'error' );
												setImportOpen( false );
											} )
											.finally( () => setImportBusy( false ) );
									} }
								>
									{ __( 'Import', 'tk-fields' ) }
								</Button>
							</div>
						</>
					) }
					{ importReport && (
						<>
							{ ( importReport.imported || [] ).length > 0 ? (
								<>
									<p>
										{ sprintf(
											/* translators: %d: group count */
											__(
												'Imported %d group(s):',
												'tk-fields'
											),
											importReport.imported.length
										) }
									</p>
									<ul className="tkf-import-report">
										{ importReport.imported.map( ( g ) => (
											<li key={ g.id || g.title }>
												<strong>{ g.title }</strong>
												{ typeof g.fields === 'number' && (
													<span className="tkf-muted">
														{ ' — ' }
														{ sprintf(
															/* translators: %d: field count */
															__( '%d field(s)', 'tk-fields' ),
															g.fields
														) }
													</span>
												) }
											</li>
										) ) }
									</ul>
								</>
							) : (
								<p>
									{ __( 'Nothing was imported.', 'tk-fields' ) }
								</p>
							) }
							{ importReport.acf_detected === false && (
								<div className="tkf-import-fallback">
									<h4>{ __( 'No ACF detected — import with AI instead', 'tk-fields' ) }</h4>
									<p>
										{ __(
											'The direct import needs the ACF plugin installed and active on this site. You can still convert an ACF JSON export file with an AI assistant acting as the transformer:',
											'tk-fields'
										) }
									</p>
									<ol className="tkf-import-steps">
										<li>{ __( 'Copy the transformer prompt below.', 'tk-fields' ) }</li>
										<li>
											{ __(
												'Paste it into ChatGPT, Claude, or Gemini, together with the contents of your ACF JSON export file (ACF → Tools → Export).',
												'tk-fields'
											) }
										</li>
										<li>
											{ __(
												'The AI replies with TK Fields JSON plus a list of anything it could not convert. Paste just the JSON part into “Import from AI” (the toolbar button) to preview and import it.',
												'tk-fields'
											) }
										</li>
									</ol>
									{ aiPromptError && (
										<p className="tkf-error-box" role="alert">
											{ aiPromptError }
										</p>
									) }
									{ ! aiPrompt && ! aiPromptError && (
										<Button
											variant="secondary"
											isBusy={ aiPromptBusy }
											disabled={ aiPromptBusy }
											onClick={ loadAiPrompt }
										>
											{ __( 'Load transformer prompt', 'tk-fields' ) }
										</Button>
									) }
									{ aiPrompt && (
										<>
											<TKControl
												label={ __( 'ACF → TK Fields transformer prompt', 'tk-fields' ) }
											>
												<textarea
													ref={ promptRef }
													readOnly
													rows={ 10 }
													className="tkf-textarea tkf-textarea--mono"
													value={ aiPrompt }
												/>
											</TKControl>
											<div className="tkf-modal__actions">
												<Button variant="secondary" onClick={ copyAiPrompt }>
													{ aiPromptCopied
														? __( 'Copied!', 'tk-fields' )
														: __( 'Copy prompt', 'tk-fields' ) }
												</Button>
											</div>
										</>
									) }
								</div>
							) }
							{ ( importReport.notes || [] ).length > 0 && (
								<>
									<h4>{ __( 'Notes', 'tk-fields' ) }</h4>
									<ul className="tkf-import-report tkf-import-report--notes">
										{ importReport.notes.map( ( note, i ) => (
											<li key={ i }>{ note }</li>
										) ) }
									</ul>
								</>
							) }
							<div className="tkf-modal__actions">
								<Button
									variant="secondary"
									onClick={ () => setImportOpen( false ) }
								>
									{ __( 'Close', 'tk-fields' ) }
								</Button>
							</div>
						</>
					) }
				</Modal>
			) }

			{ aiImportOpen && (
				<AiImportModal
					onClose={ () => setAiImportOpen( false ) }
					onImported={ load }
					notify={ notify }
				/>
			) }
		</div>
	);
}
