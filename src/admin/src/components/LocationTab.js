/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * Location tab: where this field group appears.
 *
 * v0.12.0: rule groups — flat rules keep the Match all/any behaviour, and a
 * "+ Add rule group" escape hatch creates a boxed AND section for complex
 * conditions. Groups are stored as { rules: [...] } entries inside the
 * location array (see the REST sanitizer); a zero-rule group matches nothing.
 */
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	SelectControl,
	Spinner,
	TextControl,
	Icon,
} from '@wordpress/components';
import { trash } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import { getPostTypes } from '../api';
import TKControl from './TKControl';
import { SegmentedControl } from './ui';

const PARAMS = [
	{ value: 'post_type', label: __( 'Post type', 'tk-fields' ) },
	{ value: 'page_template', label: __( 'Page template', 'tk-fields' ) },
];

const OPERATORS = [
	{ value: '==', label: __( 'is equal to', 'tk-fields' ) },
	{ value: '!=', label: __( 'is not equal to', 'tk-fields' ) },
];

const isGroup = ( entry ) => entry && Array.isArray( entry.rules );

/** One rule row — used for flat rules and for rules inside a group. */
function RuleRow( {
	rule,
	label,
	onPatch,
	onRemove,
	postTypes,
	valueOptions,
	ptLoading,
} ) {
	return (
		<div className="tkf-rule-row">
			<div className="tkf-rule-row__controls">
				<SelectControl
					options={ PARAMS }
					value={ rule.param || 'post_type' }
					onChange={ ( param ) =>
						onPatch( {
							param,
							value: param === 'post_type' ? 'post' : '',
						} )
					}
					aria-label={ label + __( ' parameter', 'tk-fields' ) }
				/>
				<SelectControl
					options={ OPERATORS }
					value={ rule.operator || '==' }
					onChange={ ( operator ) => onPatch( { operator } ) }
					aria-label={ label + __( ' operator', 'tk-fields' ) }
				/>
				{ ( rule.param || 'post_type' ) === 'post_type' ? (
					ptLoading ? (
						<span className="tkf-loading tkf-loading--inline">
							<Spinner />
						</span>
					) : (
						<SelectControl
							options={ valueOptions }
							value={ rule.value || '' }
							onChange={ ( value ) => onPatch( { value } ) }
							aria-label={ label + __( ' post type', 'tk-fields' ) }
						/>
					)
				) : (
					<TextControl
						value={ rule.value || '' }
						onChange={ ( value ) => onPatch( { value } ) }
						placeholder="template-fullwidth.php"
						aria-label={ label + __( ' template file', 'tk-fields' ) }
					/>
				) }
			</div>
			<Button
				variant="link"
				isDestructive
				aria-label={ sprintf(
					/* translators: %s: rule label */
					__( 'Remove %s', 'tk-fields' ),
					label
				) }
				onClick={ onRemove }
			>
				<Icon icon={ trash } size={ 16 } />
			</Button>
		</div>
	);
}

export default function LocationTab( { group, onUpdate, markDirty } ) {
	const location = group.location || [];
	const [ postTypes, setPostTypes ] = useState( null );
	const [ ptError, setPtError ] = useState( '' );

	useEffect( () => {
		getPostTypes()
			.then( setPostTypes )
			.catch( ( err ) => setPtError( err.message ) );
	}, [] );

	const set = ( patch ) => {
		onUpdate( patch );
		markDirty();
	};

	const setLocation = ( next ) => set( { location: next } );

	/* --- flat rules --- */
	const updateFlatRule = ( index, patch ) => {
		setLocation(
			location.map( ( r, i ) =>
				i === index && ! isGroup( r ) ? { ...r, ...patch } : r
			)
		);
	};

	const removeFlatRule = ( index ) => {
		setLocation( location.filter( ( _, i ) => i !== index ) );
	};

	const addFlatRule = () => {
		setLocation( [
			...location,
			{ param: 'post_type', operator: '==', value: 'post' },
		] );
	};

	/* --- rule groups --- */
	const addGroup = () => {
		setLocation( [
			...location,
			{ rules: [ { param: 'post_type', operator: '==', value: 'post' } ] },
		] );
	};

	const removeGroup = ( index ) => {
		setLocation( location.filter( ( _, i ) => i !== index ) );
	};

	const updateGroupRule = ( groupIndex, ruleIndex, patch ) => {
		setLocation(
			location.map( ( r, i ) =>
				i === groupIndex && isGroup( r )
					? {
							...r,
							rules: r.rules.map( ( sub, j ) =>
								j === ruleIndex ? { ...sub, ...patch } : sub
							),
					  }
					: r
			)
		);
	};

	const addGroupRule = ( groupIndex ) => {
		setLocation(
			location.map( ( r, i ) =>
				i === groupIndex && isGroup( r )
					? {
							...r,
							rules: [
								...r.rules,
								{ param: 'post_type', operator: '==', value: 'post' },
							],
					  }
					: r
			)
		);
	};

	const removeGroupRule = ( groupIndex, ruleIndex ) => {
		setLocation(
			location.map( ( r, i ) =>
				i === groupIndex && isGroup( r )
					? {
							...r,
							rules: r.rules.filter( ( _, j ) => j !== ruleIndex ),
					  }
					: r
			)
		);
	};

	const valueOptions = ( postTypes || [] ).map( ( t ) => ( {
		value: t.slug,
		label: `${ t.label } (${ t.slug })`,
	} ) );

	const ruleProps = {
		postTypes,
		valueOptions,
		ptLoading: postTypes === null,
	};

	let flatRuleNumber = 0;
	let groupNumber = 0;

	return (
		<div className="tkf-location-tab">
			<p className="tkf-tab-intro">
				{ __(
					'Decide where this field group appears in the editor. Rules can require all of them to match, or any one of them.',
					'tk-fields'
				) }
			</p>

			<TKControl
				label={ __( 'Match', 'tk-fields' ) }
				tooltip={ __(
					'“All” shows the group only when every rule and rule group matches. “Any” shows it when at least one rule or rule group matches. Rules inside a group always use AND.',
					'tk-fields'
				) }
			>
				<SegmentedControl
					label={ __( 'Rule matching mode', 'tk-fields' ) }
					value={ group.location_match || 'all' }
					onChange={ ( location_match ) => set( { location_match } ) }
					options={ [
						{ value: 'all', label: __( 'Match all rules', 'tk-fields' ) },
						{ value: 'any', label: __( 'Match any rule', 'tk-fields' ) },
					] }
				/>
			</TKControl>

			<div className="tkf-location-tab__rules">
				{ location.map( ( entry, i ) => {
					if ( isGroup( entry ) ) {
						groupNumber += 1;
						const n = groupNumber;
						return (
							<div className="tkf-rule-group" key={ 'g' + i }>
								<div className="tkf-rule-group__head">
									<strong>
										{ sprintf(
											/* translators: %d: group number */
											__( 'Rule group %d', 'tk-fields' ),
											n
										) }
									</strong>
									<span className="tkf-muted">
										{ __(
											'all rules inside must match',
											'tk-fields'
										) }
									</span>
									<Button
										variant="link"
										isDestructive
										aria-label={ sprintf(
											/* translators: %d: group number */
											__( 'Delete rule group %d', 'tk-fields' ),
											n
										) }
										onClick={ () => removeGroup( i ) }
									>
										<Icon icon={ trash } size={ 16 } />
									</Button>
								</div>
								<div className="tkf-rule-group__body">
									{ entry.rules.map( ( rule, j ) => (
										<RuleRow
											key={ j }
											rule={ rule }
											label={ sprintf(
												/* translators: %1$d: group number, %2$d: rule number */
												__(
													'Rule group %1$d rule %2$d',
													'tk-fields'
												),
												n,
												j + 1
											) }
											onPatch={ ( patch ) =>
												updateGroupRule( i, j, patch )
											}
											onRemove={ () => removeGroupRule( i, j ) }
											{ ...ruleProps }
										/>
									) ) }
									{ entry.rules.length === 0 && (
										<p className="tkf-muted">
											{ __(
												'This group has no rules, so it matches nothing. Add a rule below or delete the group.',
												'tk-fields'
											) }
										</p>
									) }
								</div>
								<Button
									variant="secondary"
									size="small"
									onClick={ () => addGroupRule( i ) }
								>
									{ __( '+ Add rule', 'tk-fields' ) }
								</Button>
							</div>
						);
					}
					flatRuleNumber += 1;
					const n = flatRuleNumber;
					return (
						<RuleRow
							key={ 'r' + i }
							rule={ entry }
							label={ sprintf(
								/* translators: %d: rule number */
								__( 'Rule %d', 'tk-fields' ),
								n
							) }
							onPatch={ ( patch ) => updateFlatRule( i, patch ) }
							onRemove={ () => removeFlatRule( i ) }
							{ ...ruleProps }
						/>
					);
				} ) }
				{ ptError && (
					<p className="tkf-control__error" role="alert">
						{ ptError }
					</p>
				) }
			</div>

			<div className="tkf-location-tab__actions">
				<Button variant="secondary" onClick={ addFlatRule }>
					{ __( '+ Add rule', 'tk-fields' ) }
				</Button>
				<Button variant="secondary" onClick={ addGroup }>
					{ __( '+ Add rule group', 'tk-fields' ) }
				</Button>
			</div>

			<div className="tkf-callout">
				<strong>{ __( 'Page templates', 'tk-fields' ) }:</strong>{ ' ' }
				{ __(
					'enter the template filename as WordPress stores it (e.g. template-fullwidth.php or page-templates/fullwidth.php). A template picker ships in v2.',
					'tk-fields'
				) }
			</div>
		</div>
	);
}
