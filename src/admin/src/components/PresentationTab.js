/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * Presentation tab: metabox position/style and label/instruction placement.
 */
import { SelectControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import TKControl from './TKControl';

const POSITION_OPTIONS = [
	{ value: 'normal', label: __( 'Normal — after the content editor', 'tk-fields' ) },
	{ value: 'side', label: __( 'Side — in the sidebar', 'tk-fields' ) },
	{ value: 'high', label: __( 'High — after the title', 'tk-fields' ) },
];

const STYLE_OPTIONS = [
	{ value: 'default', label: __( 'Standard metabox', 'tk-fields' ) },
	{ value: 'seamless', label: __( 'Seamless — no metabox frame', 'tk-fields' ) },
];

const LABEL_PLACEMENT_OPTIONS = [
	{ value: 'top', label: __( 'Above the fields', 'tk-fields' ) },
	{ value: 'left', label: __( 'Beside the fields', 'tk-fields' ) },
];

const INSTRUCTION_PLACEMENT_OPTIONS = [
	{ value: 'label', label: __( 'Below the labels', 'tk-fields' ) },
	{ value: 'field', label: __( 'Below the fields', 'tk-fields' ) },
];

export default function PresentationTab( { group, onUpdate, markDirty } ) {
	const presentation = group.presentation || {};
	const set = ( key, value ) => {
		onUpdate( { presentation: { ...presentation, [ key ]: value } } );
		markDirty();
	};

	return (
		<div className="tkf-presentation-tab">
			<p className="tkf-tab-intro">
				{ __(
					'Control how this field group is displayed on the edit screen.',
					'tk-fields'
				) }
			</p>

			<div className="tkf-settings-grid">
				<TKControl
					label={ __( 'Position', 'tk-fields' ) }
					tooltip={ __(
						'Where the field group metabox appears on the edit screen.',
						'tk-fields'
					) }
				>
					<SelectControl
						options={ POSITION_OPTIONS }
						value={ presentation.position || 'normal' }
						onChange={ ( v ) => set( 'position', v ) }
					/>
				</TKControl>

				<TKControl
					label={ __( 'Style', 'tk-fields' ) }
					tooltip={ __(
						'Seamless removes the metabox frame so fields sit directly on the page — useful for full-width layouts.',
						'tk-fields'
					) }
				>
					<SelectControl
						options={ STYLE_OPTIONS }
						value={ presentation.style || 'default' }
						onChange={ ( v ) => set( 'style', v ) }
					/>
				</TKControl>

				<TKControl
					label={ __( 'Label placement', 'tk-fields' ) }
					tooltip={ __(
						'Whether field labels render above the inputs or beside them.',
						'tk-fields'
					) }
				>
					<SelectControl
						options={ LABEL_PLACEMENT_OPTIONS }
						value={ presentation.label_placement || 'top' }
						onChange={ ( v ) => set( 'label_placement', v ) }
					/>
				</TKControl>

				<TKControl
					label={ __( 'Instruction placement', 'tk-fields' ) }
					tooltip={ __(
						'Whether the helper instructions render below the label or below the input.',
						'tk-fields'
					) }
				>
					<SelectControl
						options={ INSTRUCTION_PLACEMENT_OPTIONS }
						value={ presentation.instruction_placement || 'label' }
						onChange={ ( v ) => set( 'instruction_placement', v ) }
					/>
				</TKControl>

				<TKControl
					label={ __( 'Exclude from AI export', 'tk-fields' ) }
					tooltip={ __(
						'Phase 5 will add an AI context export (field definitions as structured data for AI assistants). Enable this to keep this group’s fields out of that export — for internal or sensitive fields.',
						'tk-fields'
					) }
				>
					<ToggleControl
						checked={ !! group.exclude_from_ai }
						onChange={ ( exclude_from_ai ) => {
							onUpdate( { exclude_from_ai } );
							markDirty();
						} }
					/>
				</TKControl>
			</div>
		</div>
	);
}
