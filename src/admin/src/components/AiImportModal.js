/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * AI Import modal: the inbound half of the AI round trip.
 *
 * Step 1 "template": shows the copy-paste prompt (schema + valid types +
 * worked example, generated server-side from the live field registry).
 * Step 2 "paste": the admin pastes the AI's JSON and validates it.
 * Step 3 "preview": parsed group preview with warnings and conflict
 * choice, then confirm creates the group via Group_Store.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Modal, RadioControl, Spinner, TextareaControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import TKControl from './TKControl';

export default function AiImportModal( { onClose, onImported, notify } ) {
	const [ step, setStep ] = useState( 'template' );
	const [ template, setTemplate ] = useState( null );
	const [ templateError, setTemplateError ] = useState( '' );
	const [ copied, setCopied ] = useState( false );
	const [ json, setJson ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ preview, setPreview ] = useState( null );
	const [ warnings, setWarnings ] = useState( [] );
	const [ payload, setPayload ] = useState( null );
	const [ conflict, setConflict ] = useState( 'create' );
	const [ created, setCreated ] = useState( null );
	const promptRef = useRef( null );

	useEffect( () => {
		api
			.aiImportTemplate()
			.then( ( data ) => setTemplate( data ) )
			.catch( ( err ) => setTemplateError( err.message ) );
	}, [] );

	const copyPrompt = async () => {
		const text = template?.prompt || '';
		try {
			await navigator.clipboard.writeText( text );
		} catch ( e ) {
			// Clipboard API unavailable (permissions) — fall back to
			// selecting the textarea so the user can copy manually.
			promptRef.current?.select?.();
			notify( __( 'Copy the prompt manually (clipboard access was blocked).', 'tk-fields' ), 'error' );
			return;
		}
		setCopied( true );
		setTimeout( () => setCopied( false ), 2000 );
	};

	const doValidate = () => {
		setBusy( true );
		setError( '' );
		api
			.aiImportValidate( json )
			.then( ( data ) => {
				setPreview( data.preview );
				setWarnings( data.warnings || [] );
				setPayload( data.payload );
				setConflict( 'create' );
				setStep( 'preview' );
			} )
			.catch( ( err ) => setError( err.message ) )
			.finally( () => setBusy( false ) );
	};

	const doConfirm = () => {
		setBusy( true );
		setError( '' );
		api
			.aiImportConfirm( payload, conflict )
			.then( ( data ) => {
				setCreated( data.group );
				setStep( 'done' );
				onImported();
			} )
			.catch( ( err ) => setError( err.message ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<Modal
			title={ __( 'Import from AI', 'tk-fields' ) }
			onRequestClose={ () => ( busy ? null : onClose() ) }
			className="tkf-modal tkf-modal--wide"
		>
			{ step === 'template' && (
				<>
					<p>
						{ __(
							'Describe the field group you want to an AI assistant, then paste its JSON back here. Start by copying the prompt below — it teaches the AI the exact format TK Fields expects.',
							'tk-fields'
						) }
					</p>
					{ templateError && <p className="tkf-error-box" role="alert">{ templateError }</p> }
					{ ! template && ! templateError && (
						<p className="tkf-loading">
							<Spinner /> { __( 'Loading template…', 'tk-fields' ) }
						</p>
					) }
					{ template && (
						<>
							<TKControl
								label={ __( 'AI prompt', 'tk-fields' ) }
								help={ __(
									'Paste this into ChatGPT, Claude, or Gemini, then describe the field group you want in plain language. The AI replies with JSON — bring that JSON back to step 2.',
									'tk-fields'
								) }
							>
								<textarea
									ref={ promptRef }
									readOnly
									rows={ 10 }
									className="tkf-textarea tkf-textarea--mono"
									value={ template.prompt }
								/>
							</TKControl>
							<div className="tkf-modal__actions">
								<Button variant="secondary" onClick={ () => onClose() } disabled={ busy }>
									{ __( 'Cancel', 'tk-fields' ) }
								</Button>
								<Button variant="secondary" onClick={ copyPrompt }>
									{ copied ? __( 'Copied!', 'tk-fields' ) : __( 'Copy prompt', 'tk-fields' ) }
								</Button>
								<Button variant="primary" onClick={ () => setStep( 'paste' ) }>
									{ __( 'I have the JSON →', 'tk-fields' ) }
								</Button>
							</div>
						</>
					) }
				</>
			) }

			{ step === 'paste' && (
				<>
					<p>
						{ __(
							'Paste the raw JSON object the AI returned. It is validated before anything is created — unknown field types and bad data are rejected with an explanation.',
							'tk-fields'
						) }
					</p>
					<TKControl
						label={ __( 'AI-generated JSON', 'tk-fields' ) }
						help={ __( 'The single JSON object the AI replied with. Nothing is saved until you confirm the preview.', 'tk-fields' ) }
					>
						<TextareaControl
							rows={ 12 }
							className="tkf-textarea--mono"
							value={ json }
							onChange={ setJson }
							placeholder='{"title": "Team Member", "fields": [ … ]}'
						/>
					</TKControl>
					{ error && <p className="tkf-error-box" role="alert">{ error }</p> }
					<div className="tkf-modal__actions">
						<Button variant="secondary" onClick={ () => setStep( 'template' ) } disabled={ busy }>
							{ __( '← Back', 'tk-fields' ) }
						</Button>
						<Button variant="primary" onClick={ doValidate } isBusy={ busy } disabled={ busy || ! json.trim() }>
							{ __( 'Validate & preview', 'tk-fields' ) }
						</Button>
					</div>
				</>
			) }

			{ step === 'preview' && preview && (
				<>
					<p>
						{ sprintf(
							/* translators: %s: group title */
							__( 'Ready to import "%s". Review it below — nothing has been created yet.', 'tk-fields' ),
							preview.title
						) }
					</p>
					{ warnings.length > 0 && (
						<div className="tkf-notice-box" role="status">
							{ warnings.map( ( w, i ) => (
								<p key={ i }>{ w }</p>
							) ) }
						</div>
					) }
					<table className="tkf-table">
						<thead>
							<tr>
								<th>{ __( 'Field', 'tk-fields' ) }</th>
								<th>{ __( 'Name', 'tk-fields' ) }</th>
								<th>{ __( 'Type', 'tk-fields' ) }</th>
								<th>{ __( 'Required', 'tk-fields' ) }</th>
								<th>{ __( 'Settings', 'tk-fields' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ preview.fields.map( ( f ) => (
								<tr key={ f.name }>
									<td>{ f.label }</td>
									<td><code>{ f.name }</code></td>
									<td><code>{ f.type }</code></td>
									<td>{ f.required ? __( 'Yes', 'tk-fields' ) : '—' }</td>
									<td className="tkf-muted">{ f.settings && f.settings.length ? f.settings.join( ', ' ) : '—' }</td>
								</tr>
							) ) }
						</tbody>
					</table>
					<p className="tkf-muted">
						{ preview.locations.length > 0
							? sprintf(
								/* translators: %s: location rules */
								__( 'Location: %s (%s match).', 'tk-fields' ),
								preview.locations.join( ', ' ),
								preview.location_match
							)
							: __( 'Location: no rules — the group will apply nowhere until you add location rules.', 'tk-fields' ) }
					</p>
					{ preview.title_exists && (
						<TKControl
							label={ __( 'A group with this title already exists', 'tk-fields' ) }
							help={ __( 'Titles do not have to be unique, but renaming avoids confusion in the group list.', 'tk-fields' ) }
						>
							<RadioControl
								selected={ conflict }
								options={ [
									{ label: __( 'Import anyway (duplicate title)', 'tk-fields' ), value: 'create' },
									{ label: __( 'Rename automatically (appends " (2)")', 'tk-fields' ), value: 'rename' },
								] }
								onChange={ setConflict }
							/>
						</TKControl>
					) }
					{ error && <p className="tkf-error-box" role="alert">{ error }</p> }
					<div className="tkf-modal__actions">
						<Button variant="secondary" onClick={ () => setStep( 'paste' ) } disabled={ busy }>
							{ __( '← Back', 'tk-fields' ) }
						</Button>
						<Button variant="primary" onClick={ doConfirm } isBusy={ busy } disabled={ busy }>
							{ __( 'Confirm import', 'tk-fields' ) }
						</Button>
					</div>
				</>
			) }

			{ step === 'done' && created && (
				<>
					<div className="tkf-notice-box tkf-notice-box--success" role="status">
						<p>
							{ sprintf(
								/* translators: %s: group title */
								__( 'Field group "%s" was created. You can edit it like any manually built group.', 'tk-fields' ),
								created.title
							) }
						</p>
					</div>
					<div className="tkf-modal__actions">
						<Button variant="primary" onClick={ () => onClose() }>
							{ __( 'Done', 'tk-fields' ) }
						</Button>
					</div>
				</>
			) }
		</Modal>
	);
}
