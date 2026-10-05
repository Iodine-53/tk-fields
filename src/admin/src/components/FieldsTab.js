/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * Fields tab: sortable field cards, type picker, and the field settings
 * editor. Type-specific settings render GENERICALLY from the /field-types
 * schema — no per-type forms are hardcoded here.
 *
 * v0.12.0 rebuild: searchable tabbed type picker, one recursive FieldRow
 * component (shared with the vanilla sub-field/layout editors via
 * window.TKFFieldRow), adaptive settings tabs past ~12 settings,
 * touched-gated validation errors rendered under their inputs, and a
 * first-run empty state.
 */
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Modal,
	Notice,
	SearchControl,
	SelectControl,
	TabPanel,
	TextareaControl,
	TextControl,
	ToggleControl,
	Spinner,
	Icon,
	Tooltip,
} from '@wordpress/components';
import { trash } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import TKControl from './TKControl';
import FieldRow from './FieldRow';

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

export function newFieldKey() {
	return (
		'f_' +
		Math.random().toString( 36 ).slice( 2, 10 ) +
		Date.now().toString( 36 ).slice( -4 )
	);
}

export function slugify( text ) {
	return ( text || '' )
		.toLowerCase()
		.trim()
		.replace( /[\s-]+/g, '_' )
		.replace( /[^a-z0-9_]/g, '' )
		.replace( /_+/g, '_' )
		.replace( /^_|_$/g, '' );
}

/** Keys rendered by the universal block — never duplicated from the schema. */
const UNIVERSAL_KEYS = new Set( [
	'label',
	'name',
	'required',
	'default',
	'placeholder',
	'instructions',
] );

/** Schema settings that belong on the "Rules" tab (validation). */
const RULE_KEYS = new Set( [ 'min', 'max', 'step', 'maxlength' ] );

/** Picker categories in tab order. */
const PICKER_TABS = [
	{ name: 'popular', title: __( 'Popular', 'tk-fields' ) },
	{ name: 'basic', title: __( 'Basic', 'tk-fields' ) },
	{ name: 'content', title: __( 'Content', 'tk-fields' ) },
	{ name: 'choice', title: __( 'Choice', 'tk-fields' ) },
	{ name: 'relational', title: __( 'Relational', 'tk-fields' ) },
	{ name: 'advanced', title: __( 'Advanced', 'tk-fields' ) },
	{ name: 'layout', title: __( 'Layout', 'tk-fields' ) },
];

const POPULAR_TYPES = [
	'text',
	'textarea',
	'image',
	'select',
	'checkbox',
	'relationship',
];

const ACF_NOTE_KEY = 'tkf_acf_migrant_note_dismissed';

export function validateField( field, siblings = [] ) {
	const errors = {};
	if ( ! ( field.label || '' ).trim() ) {
		errors.label = __( 'Label is required.', 'tk-fields' );
	}
	const name = field.name || '';
	if ( ! name ) {
		errors.name = __( 'Field name is required.', 'tk-fields' );
	} else if ( ! /^[a-z0-9_]+$/.test( name ) ) {
		errors.name = __(
			'Use only lowercase letters, numbers and underscores.',
			'tk-fields'
		);
	} else if (
		siblings.some( ( f ) => f.key !== field.key && f.name === name )
	) {
		errors.name = __(
			'This name is already used by another field in this group.',
			'tk-fields'
		);
	}
	return errors;
}

function normalizeOptions( options ) {
	if ( Array.isArray( options ) ) {
		return options;
	}
	if ( options && typeof options === 'object' ) {
		return Object.entries( options ).map( ( [ value, label ] ) => ( {
			value,
			label: String( label ),
		} ) );
	}
	return [];
}

/**
 * Repeater "Row summary field" options — derived live from the field's
 * current sub-fields (the group's pattern for sub-field-driven controls:
 * the PHP schema carries no static options for `collapsed`). Empty value
 * selects the first field; the summary is derived presentation state and
 * is never stored.
 */
export function repeaterSummaryOptions( subFields ) {
	const options = [
		{
			value: '',
			label: __( 'First field (default)', 'tk-fields' ),
		},
	];
	( Array.isArray( subFields ) ? subFields : [] ).forEach( ( sf ) => {
		if ( sf && sf.name ) {
			options.push( {
				value: sf.name,
				label: ( sf.label || sf.name ) + ' (' + sf.name + ')',
			} );
		}
	} );
	return options;
}

/* ------------------------------------------------------------------ */
/* Generic schema-driven setting control                               */
/* ------------------------------------------------------------------ */

function SettingControl( { setting, value, onChange } ) {
	switch ( setting.control ) {
		case 'toggle':
			return (
				<ToggleControl checked={ !! value } onChange={ onChange } />
			);
		case 'number':
			return (
				<TextControl
					type="number"
					value={ value ?? '' }
					onChange={ onChange }
				/>
			);
		case 'select':
			return (
				<SelectControl
					options={ normalizeOptions( setting.options ) }
					value={ value ?? '' }
					onChange={ onChange }
				/>
			);
		case 'multicheck': {
			// Multi-value checkbox list: options are value => label pairs and
			// the stored value is a string[]. Generic — any setting may use it.
			const options = normalizeOptions( setting.options );
			const current = Array.isArray( value ) ? value.map( String ) : [];
			const toggleOption = ( optValue, checked ) => {
				const v = String( optValue );
				onChange(
					checked
						? [ ...current, v ]
						: current.filter( ( x ) => x !== v )
				);
			};
			return (
				<div className="tkf-multicheck">
					{ options.map( ( { value: optValue, label } ) => {
						const v = String( optValue );
						return (
							<CheckboxControl
								key={ v }
								label={ label }
								checked={ current.includes( v ) }
								onChange={ ( checked ) =>
									toggleOption( optValue, checked )
								}
							/>
						);
					} ) }
				</div>
			);
		}
		case 'choices':
			return (
				<ChoicesEditor value={ value || {} } onChange={ onChange } />
			);
		case 'textarea':
			return (
				<TextareaControl
					value={ value ?? '' }
					onChange={ onChange }
					rows={ 4 }
				/>
			);
		case 'layouts': {
			// Restored from the built bundle: the classic vanilla layouts
			// editor renders here (flexible_content type).
			const LayoutsEditor = window.TKFLayoutsEditor;
			return LayoutsEditor ? (
				<LayoutsEditor value={ value } onChange={ onChange } />
			) : (
				<p className="tkf-muted">
					{ __( 'Layouts editor unavailable.', 'tk-fields' ) }
				</p>
			);
		}
		case 'subfields': {
			// Restored from the built bundle: the classic vanilla sub-fields
			// editor renders here (group type).
			const SubFieldsEditor = window.TKFSubFieldsEditor;
			return SubFieldsEditor ? (
				<SubFieldsEditor value={ value } onChange={ onChange } />
			) : (
				<p className="tkf-muted">
					{ __( 'Sub-fields editor unavailable.', 'tk-fields' ) }
				</p>
			);
		}
		case 'text':
		default:
			return (
				<TextControl value={ value ?? '' } onChange={ onChange } />
			);
	}
}

/* ------------------------------------------------------------------ */
/* Choices key/value editor (composite — wrapped once by TKControl)    */
/* ------------------------------------------------------------------ */

export function ChoicesEditor( { value, onChange } ) {
	const rows = useMemo(
		() => Object.entries( value || {} ),
		[ value ]
	);

	const setRows = ( nextRows ) => {
		const obj = {};
		nextRows.forEach( ( [ k, v ] ) => {
			if ( k ) {
				obj[ k ] = v;
			}
		} );
		onChange( obj );
	};

	const updateRow = ( index, side, val ) => {
		const next = rows.map( ( [ k, v ], i ) =>
			i === index ? ( side === 'key' ? [ val, v ] : [ k, val ] ) : [ k, v ]
		);
		setRows( next );
	};

	return (
		<div className="tkf-choices">
			<div className="tkf-choices__head" aria-hidden="true">
				<span>{ __( 'Value', 'tk-fields' ) }</span>
				<span>{ __( 'Label', 'tk-fields' ) }</span>
				<span />
			</div>
			{ rows.map( ( [ k, v ], i ) => (
				<div className="tkf-choices__row" key={ i }>
					<input
						type="text"
						className="tkf-choices__input"
						value={ k }
						aria-label={ sprintf(
							/* translators: %d: row number */
							__( 'Choice %d value', 'tk-fields' ),
							i + 1
						) }
						placeholder="value"
						onChange={ ( e ) => updateRow( i, 'key', e.target.value ) }
					/>
					<input
						type="text"
						className="tkf-choices__input"
						value={ v }
						aria-label={ sprintf(
							/* translators: %d: row number */
							__( 'Choice %d label', 'tk-fields' ),
							i + 1
						) }
						placeholder={ __( 'Label', 'tk-fields' ) }
						onChange={ ( e ) => updateRow( i, 'label', e.target.value ) }
					/>
					<Button
						variant="link"
						isDestructive
						aria-label={ sprintf(
							/* translators: %d: row number */
							__( 'Remove choice %d', 'tk-fields' ),
							i + 1
						) }
						onClick={ () => setRows( rows.filter( ( _, j ) => j !== i ) ) }
					>
						<Icon icon={ trash } size={ 16 } />
					</Button>
				</div>
			) ) }
			<Button
				variant="secondary"
				size="small"
				onClick={ () => setRows( [ ...rows, [ '', '' ] ] ) }
			>
				{ __( 'Add choice', 'tk-fields' ) }
			</Button>
		</div>
	);
}

/* ------------------------------------------------------------------ */
/* Conditional logic rule builder                                      */
/* ------------------------------------------------------------------ */

const LOGIC_OPERATORS = [
	{ value: '==', label: __( 'is equal to', 'tk-fields' ) },
	{ value: '!=', label: __( 'is not equal to', 'tk-fields' ) },
	{ value: 'empty', label: __( 'is empty', 'tk-fields' ) },
	{ value: '!empty', label: __( 'is not empty', 'tk-fields' ) },
];

export function ConditionalLogicBuilder( { field, siblings, onChange } ) {
	const rules = field.conditional_logic || [];
	const enabled = rules.length > 0;

	const setEnabled = ( on ) => {
		onChange( on ? [ { field: '', operator: '==', value: '' } ] : [] );
	};

	const updateRule = ( index, patch ) => {
		onChange(
			rules.map( ( r, i ) => ( i === index ? { ...r, ...patch } : r ) )
		);
	};

	const siblingOptions = [
		{ value: '', label: __( 'Select a field…', 'tk-fields' ) },
		...siblings
			.filter( ( f ) => f.key !== field.key )
			.map( ( f ) => ( {
				value: f.key,
				label: `${ f.label || __( '(no label)', 'tk-fields' ) } (${ f.name || '—' })`,
			} ) ),
	];

	return (
		<div className="tkf-logic">
			<TKControl
				label={ __( 'Conditional logic', 'tk-fields' ) }
				tooltip={ __(
					'Show this field only when the rules below match. All rules must match (AND). Rules compare against the saved values of other fields in this group.',
					'tk-fields'
				) }
			>
				<ToggleControl
					checked={ enabled }
					onChange={ setEnabled }
				/>
			</TKControl>
			{ enabled && (
				<div className="tkf-logic__rules">
					<p className="tkf-muted">
						{ __( 'Show this field if…', 'tk-fields' ) }
					</p>
					{ rules.map( ( rule, i ) => (
						<div className="tkf-logic__row" key={ i }>
							<SelectControl
								options={ siblingOptions }
								value={ rule.field || '' }
								onChange={ ( v ) => updateRule( i, { field: v } ) }
								aria-label={ sprintf(
									/* translators: %d: rule number */
									__( 'Rule %d field', 'tk-fields' ),
									i + 1
								) }
							/>
							<SelectControl
								options={ LOGIC_OPERATORS }
								value={ rule.operator || '==' }
								onChange={ ( v ) => updateRule( i, { operator: v } ) }
								aria-label={ sprintf(
									/* translators: %d: rule number */
									__( 'Rule %d operator', 'tk-fields' ),
									i + 1
								) }
							/>
							{ rule.operator !== 'empty' &&
								rule.operator !== '!empty' && (
									<TextControl
										value={ rule.value || '' }
										onChange={ ( v ) => updateRule( i, { value: v } ) }
										placeholder={ __( 'Value…', 'tk-fields' ) }
										aria-label={ sprintf(
											/* translators: %d: rule number */
											__( 'Rule %d value', 'tk-fields' ),
											i + 1
										) }
									/>
								) }
							<Button
								variant="link"
								isDestructive
								aria-label={ sprintf(
									/* translators: %d: rule number */
									__( 'Remove rule %d', 'tk-fields' ),
									i + 1
								) }
								onClick={ () =>
									onChange( rules.filter( ( _, j ) => j !== i ) )
								}
							>
								<Icon icon={ trash } size={ 16 } />
							</Button>
						</div>
					) ) }
					<Button
						variant="secondary"
						size="small"
						onClick={ () =>
							onChange( [
								...rules,
								{ field: '', operator: '==', value: '' },
							] )
						}
					>
						{ __( 'Add rule', 'tk-fields' ) }
					</Button>
				</div>
			) }
		</div>
	);
}

/* ------------------------------------------------------------------ */
/* Field settings editor                                               */
/* ------------------------------------------------------------------ */

/** The 7 universal controls. Extracted so tabs and flat mode share them. */
function UniversalControls( { field, fieldTypes, onUpdate, markTouched } ) {
	const set = ( patch ) => onUpdate( patch );
	const typeOptions = Object.entries( fieldTypes || {} ).map(
		( [ value, def ] ) => ( { value, label: def.label || value } )
	);
	return (
		<>
			<TKControl
				label={ __( 'Label', 'tk-fields' ) }
				tooltip={ __(
					'The human-readable label shown next to the field in the editor.',
					'tk-fields'
				) }
				required
				error={ field._errors?.label }
			>
				<TextControl
					value={ field.label || '' }
					onChange={ ( label ) => set( { label } ) }
					onBlur={ markTouched }
					placeholder={ __( 'e.g. Hero Price', 'tk-fields' ) }
				/>
			</TKControl>
			<TKControl
				label={ __( 'Field name', 'tk-fields' ) }
				tooltip={ __(
					'The machine name used in template code: tk_get_field( \'name\' ). Auto-generated from the label; you can edit it. Lowercase letters, numbers and underscores only, unique within the group.',
					'tk-fields'
				) }
				required
				error={ field._errors?.name }
				help={
					field.name
						? sprintf(
								/* translators: %s: field name */
								__( 'Used as tk_get_field( \'%s\' ).', 'tk-fields' ),
								field.name
						  )
						: undefined
				}
			>
				<TextControl
					value={ field.name || '' }
					onChange={ ( name ) => set( { name, _nameTouched: true } ) }
					onBlur={ markTouched }
					className="code"
				/>
			</TKControl>
			<TKControl
				label={ __( 'Field type', 'tk-fields' ) }
				tooltip={ __(
					'Changing the type keeps the universal settings below but re-renders the type-specific options.',
					'tk-fields'
				) }
			>
				<SelectControl
					options={ typeOptions }
					value={ field.type }
					onChange={ ( type ) => {
						set( { type } );
						markTouched();
					} }
				/>
			</TKControl>
			<TKControl
				label={ __( 'Instructions', 'tk-fields' ) }
				tooltip={ __(
					'Helper text shown to content editors beneath the field.',
					'tk-fields'
				) }
			>
				<TextareaControl
					value={ field.instructions || '' }
					onChange={ ( instructions ) => set( { instructions } ) }
					rows={ 2 }
				/>
			</TKControl>
			<TKControl
				label={ __( 'Required', 'tk-fields' ) }
				tooltip={ __(
					'Editors must fill in this field before the post can be saved.',
					'tk-fields'
				) }
			>
				<ToggleControl
					checked={ !! field.required }
					onChange={ ( required ) => set( { required } ) }
				/>
			</TKControl>
			<TKControl
				label={ __( 'Default value', 'tk-fields' ) }
				tooltip={ __(
					'Pre-filled when a new post is created. Leave empty for no default.',
					'tk-fields'
				) }
			>
				<TextControl
					value={ field.default ?? '' }
					onChange={ ( v ) => set( { default: v } ) }
				/>
			</TKControl>
			<TKControl
				label={ __( 'Placeholder', 'tk-fields' ) }
				tooltip={ __(
					'Ghost text shown inside empty text inputs in the editor.',
					'tk-fields'
				) }
			>
				<TextControl
					value={ field.placeholder || '' }
					onChange={ ( placeholder ) => set( { placeholder } ) }
				/>
			</TKControl>
		</>
	);
}

function FieldSettings( { field, siblings, fieldTypes, onUpdate, markTouched } ) {
	const typeDef = ( fieldTypes || {} )[ field.type ] || {};
	const schemaSettings = ( typeDef.settings || [] ).filter(
		( s ) => s && s.key && ! UNIVERSAL_KEYS.has( s.key )
	);
	const schemaHasChoices = schemaSettings.some( ( s ) => s.key === 'choices' );

	const set = ( patch ) => onUpdate( patch );

	const schemaControl = ( setting ) => {
		// Repeater "Row summary field": the PHP schema carries no static
		// options — they are derived live from the field's sub-fields.
		const effective =
			setting.key === 'collapsed' && field.type === 'repeater'
				? {
						...setting,
						options: repeaterSummaryOptions( field.sub_fields ),
				  }
				: setting;
		return (
			<TKControl
				key={ setting.key }
				label={ setting.label || setting.key }
				tooltip={ setting.tooltip }
			>
				<SettingControl
					setting={ effective }
					value={ field[ setting.key ] }
					onChange={ ( v ) => set( { [ setting.key ]: v } ) }
				/>
			</TKControl>
		);
	};

	const choicesBlock = field.type === 'select' && ! schemaHasChoices && (
		<TKControl
			label={ __( 'Choices', 'tk-fields' ) }
			tooltip={ __(
				'Value / label pairs for the dropdown. The value is what gets stored.',
				'tk-fields'
			) }
		>
			<ChoicesEditor
				value={ field.choices || {} }
				onChange={ ( choices ) => set( { choices } ) }
			/>
		</TKControl>
	);

	const logicBlock = (
		<ConditionalLogicBuilder
			field={ field }
			siblings={ siblings }
			onChange={ ( conditional_logic ) => set( { conditional_logic } ) }
		/>
	);

	// Adaptive tabs: flat panel for simple fields, tabs past ~12 settings.
	// Conditional Logic always gets its own tab when tabs render.
	const useTabs = 7 + schemaSettings.length > 12;

	if ( ! useTabs ) {
		return (
			<div className="tkf-field-settings">
				<div className="tkf-field-settings__grid">
					<UniversalControls
						field={ field }
						fieldTypes={ fieldTypes }
						onUpdate={ onUpdate }
						markTouched={ markTouched }
					/>
				</div>
				{ schemaSettings.length > 0 && (
					<>
						<h4 className="tkf-field-settings__subhead">
							{ sprintf(
								/* translators: %s: field type label */
								__( '%s options', 'tk-fields' ),
								typeDef.label || field.type
							) }
						</h4>
						<div className="tkf-field-settings__grid">
							{ schemaSettings.map( schemaControl ) }
						</div>
					</>
				) }
				{ choicesBlock }
				{ logicBlock }
			</div>
		);
	}

	const functionalSettings = schemaSettings.filter(
		( s ) => ! RULE_KEYS.has( s.key )
	);
	const ruleSettings = schemaSettings.filter( ( s ) => RULE_KEYS.has( s.key ) );

	const tabs = [
		{ name: 'settings', title: __( 'Settings', 'tk-fields' ) },
		{ name: 'rules', title: __( 'Rules', 'tk-fields' ) },
		{ name: 'appearance', title: __( 'Appearance', 'tk-fields' ) },
		{ name: 'visibility', title: __( 'Visibility', 'tk-fields' ) },
	];

	return (
		<div className="tkf-field-settings">
			<TabPanel tabs={ tabs } initialTabName="settings">
				{ ( tab ) => (
					<div className="tkf-field-settings__tab">
						{ tab.name === 'settings' && (
							<>
								<div className="tkf-field-settings__grid">
									<TKControl
										label={ __( 'Label', 'tk-fields' ) }
										tooltip={ __(
											'The human-readable label shown next to the field in the editor.',
											'tk-fields'
										) }
										required
										error={ field._errors?.label }
									>
										<TextControl
											value={ field.label || '' }
											onChange={ ( label ) => set( { label } ) }
											onBlur={ markTouched }
											placeholder={ __( 'e.g. Hero Price', 'tk-fields' ) }
										/>
									</TKControl>
									<TKControl
										label={ __( 'Field name', 'tk-fields' ) }
										tooltip={ __(
											'The machine name used in template code: tk_get_field( \'name\' ). Auto-generated from the label; you can edit it. Lowercase letters, numbers and underscores only, unique within the group.',
											'tk-fields'
										) }
										required
										error={ field._errors?.name }
									>
										<TextControl
											value={ field.name || '' }
											onChange={ ( name ) => set( { name, _nameTouched: true } ) }
											onBlur={ markTouched }
											className="code"
										/>
									</TKControl>
									<TKControl
										label={ __( 'Field type', 'tk-fields' ) }
										tooltip={ __(
											'Changing the type keeps the universal settings below but re-renders the type-specific options.',
											'tk-fields'
										) }
									>
										<SelectControl
											options={ Object.entries( fieldTypes || {} ).map(
												( [ value, def ] ) => ( {
													value,
													label: def.label || value,
												} )
											) }
											value={ field.type }
											onChange={ ( type ) => {
												set( { type } );
												markTouched();
											} }
										/>
									</TKControl>
									<TKControl
										label={ __( 'Instructions', 'tk-fields' ) }
										tooltip={ __(
											'Helper text shown to content editors beneath the field.',
											'tk-fields'
										) }
									>
										<TextareaControl
											value={ field.instructions || '' }
											onChange={ ( instructions ) => set( { instructions } ) }
											rows={ 2 }
										/>
									</TKControl>
								</div>
								{ functionalSettings.length > 0 && (
									<>
										<h4 className="tkf-field-settings__subhead">
											{ sprintf(
												/* translators: %s: field type label */
												__( '%s options', 'tk-fields' ),
												typeDef.label || field.type
											) }
										</h4>
										<div className="tkf-field-settings__grid">
											{ functionalSettings.map( schemaControl ) }
										</div>
									</>
								) }
								{ choicesBlock }
							</>
						) }
						{ tab.name === 'rules' && (
							<div className="tkf-field-settings__grid">
								<TKControl
									label={ __( 'Required', 'tk-fields' ) }
									tooltip={ __(
										'Editors must fill in this field before the post can be saved.',
										'tk-fields'
									) }
								>
									<ToggleControl
										checked={ !! field.required }
										onChange={ ( required ) => set( { required } ) }
									/>
								</TKControl>
								<TKControl
									label={ __( 'Default value', 'tk-fields' ) }
									tooltip={ __(
										'Pre-filled when a new post is created. Leave empty for no default.',
										'tk-fields'
									) }
								>
									<TextControl
										value={ field.default ?? '' }
										onChange={ ( v ) => set( { default: v } ) }
									/>
								</TKControl>
								{ ruleSettings.map( schemaControl ) }
							</div>
						) }
						{ tab.name === 'appearance' && (
							<div className="tkf-field-settings__grid">
								<TKControl
									label={ __( 'Placeholder', 'tk-fields' ) }
									tooltip={ __(
										'Ghost text shown inside empty text inputs in the editor.',
										'tk-fields'
									) }
								>
									<TextControl
										value={ field.placeholder || '' }
										onChange={ ( placeholder ) => set( { placeholder } ) }
									/>
								</TKControl>
							</div>
						) }
						{ tab.name === 'visibility' && logicBlock }
					</div>
				) }
			</TabPanel>
		</div>
	);
}

/* ------------------------------------------------------------------ */
/* Field card (built on the shared FieldRow)                           */
/* ------------------------------------------------------------------ */

function FieldCard( {
	field,
	index,
	total,
	fieldTypes,
	siblings,
	expanded,
	onToggleExpand,
	onUpdate,
	onDuplicate,
	onDelete,
	onMove,
	dragIndex,
	overIndex,
	onDragStart,
	onDragOver,
	onDrop,
	onDragEnd,
} ) {
	const typeDef = ( fieldTypes || {} )[ field.type ] || {};
	// v0.12.0: validation errors show only once the field is touched
	// (blur on label/name/type, or a save attempt) — no red on pristine fields.
	const errors = field._touched ? validateField( field, siblings ) : {};
	const errorCount = Object.keys( errors ).length;
	const fieldWithErrors = { ...field, _errors: errors };

	const markTouched = () => {
		if ( ! field._touched ) {
			onUpdate( { _touched: true } );
		}
	};

	// Auto-slug the name from the label until the user edits it manually.
	const handleLabelChange = ( label ) => {
		const patch = { label };
		if ( ! field._nameTouched ) {
			const slugged = slugify( label );
			if ( slugged ) {
				patch.name = slugged;
			}
		}
		onUpdate( patch );
	};

	return (
		<FieldRow
			field={ field }
			typeLabel={ typeDef.label }
			category={ typeDef.category }
			depth={ 0 }
			expanded={ expanded }
			onToggleExpand={ onToggleExpand }
			issues={ errorCount }
			index={ index }
			total={ total }
			onMoveUp={ () => onMove( index, index - 1 ) }
			onMoveDown={ () => onMove( index, index + 1 ) }
			onDuplicate={ onDuplicate }
			onDelete={ onDelete }
			drag={ {
				onDragStart: ( e ) => {
					e.dataTransfer.effectAllowed = 'move';
					onDragStart( index );
				},
				onDragOver: ( e ) => {
					e.preventDefault();
					e.dataTransfer.dropEffect = 'move';
					onDragOver( index );
				},
				onDrop: ( e ) => {
					e.preventDefault();
					onDrop( index );
				},
				onDragEnd,
			} }
			isDropTarget={ overIndex === index && dragIndex !== index }
		>
			<FieldSettings
				field={ fieldWithErrors }
				siblings={ siblings }
				fieldTypes={ fieldTypes }
				markTouched={ markTouched }
				onUpdate={ ( patch ) => {
					// Route label through the auto-slug handler.
					if ( 'label' in patch && ! ( '_nameTouched' in patch ) ) {
						const next = { ...patch };
						delete next.label;
						handleLabelChange( patch.label );
						if ( Object.keys( next ).length ) {
							onUpdate( next );
						}
						return;
					}
					onUpdate( patch );
				} }
			/>
		</FieldRow>
	);
}

/* ------------------------------------------------------------------ */
/* Type picker modal: search + category tabs + icon tiles              */
/* ------------------------------------------------------------------ */

function TypeTile( { type, def, onPick } ) {
	const label = def.label || type;
	// Dashicons slug from the REST type map (core dashicons-*, no bundled font).
	const icon = def.icon || 'star-filled';
	const category = def.category || 'basic';
	return (
		<Tooltip text={ def.description || label }>
			<button
				type="button"
				className={ 'tkf-type-tile tkf-type-tile--' + category }
				onClick={ () => onPick( type ) }
			>
				<span
					className={ 'tkf-type-tile__icon dashicons dashicons-' + icon }
					aria-hidden="true"
				/>
				<span className="tkf-type-tile__label">{ label }</span>
				{ def.block_editor_only && (
					<span className="tkf-type-tile__badge">
						{ __( 'Block editor only', 'tk-fields' ) }
					</span>
				) }
			</button>
		</Tooltip>
	);
}

function TypePickerModal( { fieldTypes, onPick, onClose } ) {
	const [ query, setQuery ] = useState( '' );
	const [ activeTab, setActiveTab ] = useState( 'popular' );
	const searchRef = useRef( null );
	const [ noteDismissed, setNoteDismissed ] = useState( () => {
		try {
			return window.localStorage.getItem( ACF_NOTE_KEY ) === '1';
		} catch ( e ) {
			return true;
		}
	} );

	// `/` focuses the search box while the picker is open.
	useEffect( () => {
		const onKey = ( e ) => {
			if (
				e.key === '/' &&
				! e.ctrlKey &&
				! e.metaKey &&
				! e.altKey &&
				document.activeElement !== searchRef.current &&
				!/^(INPUT|TEXTAREA)$/.test( document.activeElement?.tagName || '' )
			) {
				e.preventDefault();
				searchRef.current?.focus();
			}
		};
		document.addEventListener( 'keydown', onKey );
		return () => document.removeEventListener( 'keydown', onKey );
	}, [] );

	const types = useMemo( () => Object.entries( fieldTypes || {} ), [ fieldTypes ] );

	const matches = useMemo( () => {
		const q = query.trim().toLowerCase();
		if ( ! q ) {
			return null;
		}
		return types.filter( ( [ type, def ] ) => {
			const hay = [
				type,
				def.label || '',
				def.description || '',
				def.category || '',
				...( def.aliases || [] ),
			]
				.join( ' ' )
				.toLowerCase();
			return hay.includes( q );
		} );
	}, [ query, types ] );

	const tabTypes = useMemo( () => {
		if ( activeTab === 'popular' ) {
			return POPULAR_TYPES.map( ( t ) => [
				t,
				( fieldTypes || {} )[ t ],
			] ).filter( ( [ , def ] ) => !! def );
		}
		return types.filter(
			( [ , def ] ) => ( def.category || 'basic' ) === activeTab
		);
	}, [ activeTab, types, fieldTypes ] );

	const dismissNote = () => {
		setNoteDismissed( true );
		try {
			window.localStorage.setItem( ACF_NOTE_KEY, '1' );
		} catch ( e ) {
			// Storage unavailable — the note just shows again next time.
		}
	};

	const shown = matches || tabTypes;

	return (
		<Modal
			title={ __( 'Add field — choose a type', 'tk-fields' ) }
			onRequestClose={ onClose }
			className="tkf-modal tkf-type-picker"
		>
			{ ! noteDismissed && (
				<Notice
					status="info"
					isDismissible={ false }
					className="tkf-type-picker__note"
				>
					{ __(
						'Coming from ACF? Field names, types and JSON import/export work the same way here.',
						'tk-fields'
					) }{ ' ' }
					<Button variant="link" onClick={ dismissNote }>
						{ __( 'Got it', 'tk-fields' ) }
					</Button>
				</Notice>
			) }
			<SearchControl
				ref={ searchRef }
				value={ query }
				onChange={ setQuery }
				placeholder={ __( 'Search field types… ( / )', 'tk-fields' ) }
				label={ __( 'Search field types', 'tk-fields' ) }
				hideLabelFromVision
			/>
			{ query.trim() ? (
				<div className="tkf-type-picker__results">
					<p className="tkf-muted" role="status">
						{ shown.length === 0
							? sprintf(
									/* translators: %s: search query */
									__( 'No field types match “%s”.', 'tk-fields' ),
									query.trim()
							  )
							: sprintf(
									/* translators: %d: result count */
									__( '%d match(es)', 'tk-fields' ),
									shown.length
							  ) }
					</p>
					<div className="tkf-type-tiles">
						{ shown.map( ( [ type, def ] ) => (
							<TypeTile key={ type } type={ type } def={ def } onPick={ onPick } />
						) ) }
					</div>
				</div>
			) : (
				<TabPanel
					tabs={ PICKER_TABS }
					initialTabName="popular"
					onSelect={ setActiveTab }
					className="tkf-type-picker__tabs"
				>
					{ () => (
						<div className="tkf-type-tiles">
							{ tabTypes.map( ( [ type, def ] ) => (
								<TypeTile key={ type } type={ type } def={ def } onPick={ onPick } />
							) ) }
						</div>
					) }
				</TabPanel>
			) }
		</Modal>
	);
}

/* ------------------------------------------------------------------ */
/* Fields tab                                                          */
/* ------------------------------------------------------------------ */

export default function FieldsTab( {
	fields,
	fieldTypes,
	fieldTypesLoading,
	onChange,
	markDirty,
	notify,
	saveAttempt = 0,
} ) {
	const [ pickerOpen, setPickerOpen ] = useState( false );
	// Cards with validation errors start expanded so problems are visible
	// (e.g. after a failed save, which remounts the tab panel).
	const [ expandedKeys, setExpandedKeys ] = useState(
		() =>
			new Set(
				fields
					.filter(
						( f ) => Object.keys( validateField( f, fields ) ).length > 0
					)
					.map( ( f ) => f.key )
			)
	);
	const [ dragIndex, setDragIndex ] = useState( null );
	const [ overIndex, setOverIndex ] = useState( null );
	const [ deletingField, setDeletingField ] = useState( null );
	const listRef = useRef( null );

	// A save attempt marks every field touched so validation errors render
	// under their inputs (the save itself is blocked by validateGroup).
	useEffect( () => {
		if ( saveAttempt > 0 ) {
			onChange(
				fields.map( ( f ) =>
					f._touched ? f : { ...f, _touched: true }
				)
			);
			setExpandedKeys( ( prev ) => {
				const next = new Set( prev );
				fields.forEach( ( f ) => {
					if ( Object.keys( validateField( f, fields ) ).length > 0 ) {
						next.add( f.key );
					}
				} );
				return next;
			} );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ saveAttempt ] );

	const resetDrag = () => {
		setDragIndex( null );
		setOverIndex( null );
	};

	const move = ( from, to ) => {
		if ( to < 0 || to >= fields.length || from === to ) {
			return;
		}
		const next = [ ...fields ];
		const [ moved ] = next.splice( from, 1 );
		next.splice( to, 0, moved );
		onChange( next );
		markDirty();
	};

	const updateField = ( key, patch ) => {
		onChange(
			fields.map( ( f ) => ( f.key === key ? { ...f, ...patch } : f ) )
		);
		markDirty();
	};

	const uniqueName = ( base ) => {
		const taken = new Set( fields.map( ( f ) => f.name ) );
		let name = base || 'field';
		let i = 2;
		while ( taken.has( name ) ) {
			name = `${ base }_${ i++ }`;
		}
		return name;
	};

	const addField = ( type ) => {
		const key = newFieldKey();
		const field = {
			key,
			name: '',
			label: '',
			type,
			required: false,
			default: '',
			placeholder: '',
			instructions: '',
			choices: {},
			conditional_logic: [],
			min: '',
			max: '',
			step: '',
			maxlength: '',
		};
		onChange( [ ...fields, field ] );
		setExpandedKeys( ( prev ) => new Set( prev ).add( key ) );
		setPickerOpen( false );
		markDirty();
		// Scroll the new card into view after render.
		window.setTimeout( () => {
			const el = listRef.current?.querySelector( '[data-field-key="' + key + '"]' );
			el?.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}, 60 );
	};

	const duplicateField = ( key ) => {
		const src = fields.find( ( f ) => f.key === key );
		if ( ! src ) {
			return;
		}
		const clone = JSON.parse( JSON.stringify( src ) );
		clone.key = newFieldKey();
		clone.label = ( clone.label || '' ) + __( ' (copy)', 'tk-fields' );
		clone.name = uniqueName( ( clone.name || 'field' ) + '_copy' );
		delete clone._nameTouched;
		delete clone._errors;
		delete clone._touched;
		const idx = fields.findIndex( ( f ) => f.key === key );
		const next = [ ...fields ];
		next.splice( idx + 1, 0, clone );
		onChange( next );
		setExpandedKeys( ( prev ) => new Set( prev ).add( clone.key ) );
		markDirty();
	};

	const confirmDeleteField = () => {
		if ( ! deletingField ) {
			return;
		}
		onChange( fields.filter( ( f ) => f.key !== deletingField.key ) );
		setExpandedKeys( ( prev ) => {
			const next = new Set( prev );
			next.delete( deletingField.key );
			return next;
		} );
		notify(
			sprintf(
				/* translators: %s: field label */
				__( 'Field “%s” removed.', 'tk-fields' ),
				deletingField.label || deletingField.name
			)
		);
		setDeletingField( null );
		markDirty();
	};

	return (
		<div className="tkf-fields-tab">
			{ fieldTypesLoading && (
				<div className="tkf-loading">
					<Spinner /> { __( 'Loading field types…', 'tk-fields' ) }
				</div>
			) }

			<div className="tkf-fields-tab__list" ref={ listRef }>
				{ fields.map( ( field, index ) => (
					<FieldCard
						key={ field.key }
						field={ field }
						index={ index }
						total={ fields.length }
						fieldTypes={ fieldTypes }
						siblings={ fields }
						expanded={ expandedKeys.has( field.key ) }
						onToggleExpand={ () =>
							setExpandedKeys( ( prev ) => {
								const next = new Set( prev );
								if ( next.has( field.key ) ) {
									next.delete( field.key );
								} else {
									next.add( field.key );
								}
								return next;
							} )
						}
						onUpdate={ ( patch ) => updateField( field.key, patch ) }
						onDuplicate={ () => duplicateField( field.key ) }
						onDelete={ () => setDeletingField( field ) }
						onMove={ move }
						dragIndex={ dragIndex }
						overIndex={ overIndex }
						onDragStart={ setDragIndex }
						onDragOver={ setOverIndex }
						onDrop={ ( to ) => {
							move( dragIndex, to );
							resetDrag();
						} }
						onDragEnd={ resetDrag }
					/>
				) ) }
			</div>

			{ fields.length === 0 && ! fieldTypesLoading && (
				<div className="tkf-empty">
					<span className="dashicons dashicons-editor-table tkf-empty__icon" aria-hidden="true" />
					<h3>{ __( 'Add Your First Field', 'tk-fields' ) }</h3>
					<p>
						{ __(
							'Fields are the building blocks of this group — text, images, pickers and more. Choose a type to get started.',
							'tk-fields'
						) }
					</p>
					<Button
						variant="primary"
						onClick={ () => setPickerOpen( true ) }
					>
						{ __( 'Add Your First Field', 'tk-fields' ) }
					</Button>
					<p className="tkf-muted">
						{ sprintf(
							/* translators: %d: field type count */
							__( '%d field types to choose from.', 'tk-fields' ),
							Object.keys( fieldTypes || {} ).length
						) }
					</p>
				</div>
			) }

			{ fields.length > 0 && (
				<div className="tkf-fields-tab__add">
					<Button
						variant="secondary"
						onClick={ () => setPickerOpen( true ) }
						disabled={ fieldTypesLoading }
					>
						{ __( '+ Add Field', 'tk-fields' ) }
					</Button>
				</div>
			) }

			{ pickerOpen && (
				<TypePickerModal
					fieldTypes={ fieldTypes }
					onPick={ addField }
					onClose={ () => setPickerOpen( false ) }
				/>
			) }

			{ deletingField && (
				<Modal
					title={ __( 'Delete field?', 'tk-fields' ) }
					onRequestClose={ () => setDeletingField( null ) }
					className="tkf-modal"
				>
					<p>
						{ sprintf(
							/* translators: %s: field label */
							__(
								'“%s” will be removed from this group. Saved values for this field are left untouched on posts.',
								'tk-fields'
							),
							deletingField.label || deletingField.name
						) }
					</p>
					<div className="tkf-modal__actions">
						<Button
							variant="secondary"
							onClick={ () => setDeletingField( null ) }
						>
							{ __( 'Cancel', 'tk-fields' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							onClick={ confirmDeleteField }
						>
							{ __( 'Delete field', 'tk-fields' ) }
						</Button>
					</div>
				</Modal>
			) }
		</div>
	);
}
