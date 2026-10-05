/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * Group editor: header (title, status, save) + TabPanel with
 * Fields | Location | Presentation | Help tabs.
 */
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Notice,
	SelectControl,
	Spinner,
	TabPanel,
	TextControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import TKControl from './TKControl';
import FieldsTab, { validateField } from './FieldsTab';
import LocationTab from './LocationTab';
import PresentationTab from './PresentationTab';
import HelpTab from './HelpTab';

const blankGroup = () => ( {
	title: '',
	status: 'publish',
	location: [],
	location_match: 'all',
	fields: [],
	presentation: {
		position: 'normal',
		style: 'default',
		label_placement: 'top',
		instruction_placement: 'label',
	},
	exclude_from_ai: false,
} );

/**
 * Strip UI-only keys (prefixed with _) before sending to the REST API.
 */
function sanitizeForSave( group ) {
	const clean = JSON.parse(
		JSON.stringify( group, ( key, value ) =>
			key.startsWith( '_' ) ? undefined : value
		)
	);
	return clean;
}

function validateGroup( group ) {
	const problems = [];
	if ( ! ( group.title || '' ).trim() ) {
		problems.push( __( 'The group needs a title.', 'tk-fields' ) );
	}
	( group.fields || [] ).forEach( ( field ) => {
		const errs = validateField( field, group.fields );
		Object.values( errs ).forEach( ( msg ) => {
			problems.push(
				sprintf(
					/* translators: 1: field label or name, 2: error message */
					__( 'Field “%1$s”: %2$s', 'tk-fields' ),
					field.label || field.name || __( '(unnamed)', 'tk-fields' ),
					msg
				)
			);
		} );
	} );
	return problems;
}

const STATUS_OPTIONS = [
	{ value: 'publish', label: __( 'Published', 'tk-fields' ) },
	{ value: 'draft', label: __( 'Draft', 'tk-fields' ) },
];

export default function GroupEditor( { groupId, navigate, setDirty, notify } ) {
	const isNew = ! groupId;
	const [ group, setGroup ] = useState( null );
	const [ fieldTypes, setFieldTypes ] = useState( null );
	const [ fieldTypesLoading, setFieldTypesLoading ] = useState( true );
	const [ loading, setLoading ] = useState( true );
	const [ loadError, setLoadError ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ tabVersion, setTabVersion ] = useState( 0 );
	const [ saveAttempt, setSaveAttempt ] = useState( 0 );

	useEffect( () => {
		api
			.getFieldTypes()
			.then( ( data ) => setFieldTypes( data.types || {} ) )
			.catch( ( err ) => {
				notify( err.message, 'error' );
				setFieldTypes( {} );
			} )
			.finally( () => setFieldTypesLoading( false ) );
	}, [] );

	useEffect( () => {
		setLoading( true );
		setLoadError( '' );
		if ( isNew ) {
			setGroup( blankGroup() );
			setLoading( false );
			setDirty( false );
			return;
		}
		api
			.getGroup( groupId )
			.then( ( data ) => {
				setGroup( data.group );
				setDirty( false );
			} )
			.catch( ( err ) => setLoadError( err.message ) )
			.finally( () => setLoading( false ) );
	}, [ groupId ] );

	const update = ( patch ) => {
		setGroup( ( g ) => ( { ...g, ...patch } ) );
		setDirty( true );
	};

	const save = () => {
		if ( saving || ! group ) {
			return;
		}
		const problems = validateGroup( group );
		if ( problems.length ) {
			notify(
				problems.length === 1
					? problems[ 0 ]
					: sprintf(
							/* translators: %d: number of problems */
							__( '%d problems need fixing before saving:', 'tk-fields' ),
							problems.length
					  ) +
					  '\n• ' +
					  problems.slice( 0, 6 ).join( '\n• ' ) +
					  ( problems.length > 6
							? '\n' +
							  sprintf(
									/* translators: %d: remaining problem count */
									__( '…and %d more.', 'tk-fields' ),
									problems.length - 6
							  )
							: '' ),
				'error'
			);
			// Jump to the Fields tab so the inline errors are visible, and
			// mark every field touched so its validation errors render.
			setTabVersion( ( v ) => v + 1 );
			setSaveAttempt( ( a ) => a + 1 );
			return;
		}
		setSaving( true );
		const payload = sanitizeForSave( group );
		const req = isNew
			? api.createGroup( payload )
			: api.updateGroup( groupId, payload );
		req.then( ( data ) => {
			setGroup( data.group );
			setDirty( false );
			notify(
				isNew
					? __( 'Field group created.', 'tk-fields' )
					: __( 'Field group updated.', 'tk-fields' ),
				'success'
			);
			if ( isNew && data.group && data.group.id ) {
				// Stay in the editor, now bound to the saved group.
				// Force: dirty was just cleared above, but the navigate
				// closure still sees the stale dirty=true state.
				navigate( { name: 'edit', id: data.group.id }, { force: true } );
			}
		} )
			.catch( ( err ) => notify( err.message, 'error' ) )
			.finally( () => setSaving( false ) );
	};

	if ( loading ) {
		return (
			<div className="tkf-loading">
				<Spinner /> { __( 'Loading field group…', 'tk-fields' ) }
			</div>
		);
	}

	if ( loadError ) {
		return (
			<div>
				<Notice status="error" isDismissible={ false }>
					{ loadError }
				</Notice>
				<p>
					<Button variant="secondary" onClick={ () => navigate( { name: 'list' } ) }>
						{ __( 'Back to list', 'tk-fields' ) }
					</Button>
				</p>
			</div>
		);
	}

	const tabs = [
		{ name: 'fields', title: __( 'Fields', 'tk-fields' ), className: 'tkf-tab--fields' },
		{ name: 'location', title: __( 'Location', 'tk-fields' ), className: 'tkf-tab--location' },
		{
			name: 'presentation',
			title: __( 'Presentation', 'tk-fields' ),
			className: 'tkf-tab--presentation',
		},
		{ name: 'help', title: __( 'Help', 'tk-fields' ), className: 'tkf-tab--help' },
	];

	return (
		<div className="tkf-editor">
			<div className="tkf-editor__topbar">
				<Button
					variant="secondary"
					onClick={ () => navigate( { name: 'list' } ) }
				>
					{ __( '← Back to list', 'tk-fields' ) }
				</Button>
			</div>

			<div className="tkf-editor__header">
				<div className="tkf-editor__title">
					<TKControl
						label={
							isNew
								? __( 'New field group title', 'tk-fields' )
								: __( 'Field group title', 'tk-fields' )
						}
						tooltip={ __(
							'The admin label for this group, e.g. “Event details”.',
							'tk-fields'
						) }
						required
					>
						<TextControl
							value={ group.title || '' }
							onChange={ ( title ) => update( { title } ) }
							placeholder={ __( 'e.g. Event details', 'tk-fields' ) }
							className="tkf-editor__title-input"
						/>
					</TKControl>
				</div>
				<div className="tkf-editor__status">
					<TKControl
						label={ __( 'Status', 'tk-fields' ) }
						tooltip={ __(
							'Draft groups are not shown on edit screens.',
							'tk-fields'
						) }
					>
						<SelectControl
							options={ STATUS_OPTIONS }
							value={ group.status || 'publish' }
							onChange={ ( status ) => update( { status } ) }
						/>
					</TKControl>
				</div>
				<div className="tkf-editor__save">
					<Button
						variant="primary"
						onClick={ save }
						isBusy={ saving }
						disabled={ saving }
					>
						{ saving
							? __( 'Saving…', 'tk-fields' )
							: isNew
							? __( 'Create group', 'tk-fields' )
							: __( 'Update group', 'tk-fields' ) }
					</Button>
				</div>
			</div>

			<TabPanel
				key={ tabVersion }
				className="tkf-tabs"
				activeClass="tkf-tabs__tab--active"
				initialTabName="fields"
				tabs={ tabs }
			>
				{ ( tab ) => (
					<div className="tkf-tabs__panel">
						{ tab.name === 'fields' && (
							<FieldsTab
								fields={ group.fields || [] }
								fieldTypes={ fieldTypes }
								fieldTypesLoading={ fieldTypesLoading }
								onChange={ ( fields ) => update( { fields } ) }
								markDirty={ () => setDirty( true ) }
								notify={ notify }
								saveAttempt={ saveAttempt }
							/>
						) }
						{ tab.name === 'location' && (
							<LocationTab
								group={ group }
								onUpdate={ update }
								markDirty={ () => setDirty( true ) }
							/>
						) }
						{ tab.name === 'presentation' && (
							<PresentationTab
								group={ group }
								onUpdate={ update }
								markDirty={ () => setDirty( true ) }
							/>
						) }
						{ tab.name === 'help' && <HelpTab fieldTypes={ fieldTypes } /> }
					</div>
				) }
			</TabPanel>
		</div>
	);
}
