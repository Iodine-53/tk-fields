/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * Help tab: in-plugin help (non-negotiable) — field-type reference,
 * location-rule explainer, template usage with copyable snippets, and
 * ACF migration notes.
 */
import { __ } from '@wordpress/i18n';
import { CodeBlock } from './ui';

const READ_SNIPPET = `<?php
// Read a single field (formatted value).
$price = tk_get_field( 'hero_price' );

// Echo a field, escaped for display.
tk_the_field( 'site_tagline' );

// Read with an explicit post ID (defaults to the current post).
$email = tk_get_field( 'contact_email', $post_id );

// Write / delete programmatically.
tk_update_field( 'hero_price', 49.99 );
tk_delete_field( 'old_field' );
`;

const REPEATER_SNIPPET = `<?php
// Native TK repeater loop.
foreach ( tk_get_field( 'team_members' ) as $row ) {
    echo esc_html( $row['member_name'] );
    echo esc_url( $row['member_photo'] );
}

// Block-traversal style (mirrors the ACF have_rows pattern).
if ( tk_have_rows( 'team_members' ) ) :
    while ( tk_have_rows( 'team_members' ) ) : tk_the_row();
        tk_the_sub_field( 'member_name' );
    endwhile;
endif;
`;

const MIGRATION_SNIPPET = `<?php
// wp-config.php — or a must-use plugin. Opt-in only:
// never enable while ACF / Secure Custom Fields is active.
define( 'TK_FIELDS_ACF_COMPAT', true );

// With Migration Mode on, these just work:
$value  = get_field( 'hero_price' );
the_field( 'site_tagline' );
if ( have_rows( 'team_members' ) ) :
    while ( have_rows( 'team_members' ) ) : the_row();
        the_sub_field( 'member_name' );
    endwhile;
endif;
`;

const MIGRATION_ROWS = [
	[ 'get_field()', 'tk_get_field()' ],
	[ 'the_field()', 'tk_the_field()' ],
	[ 'have_rows()', 'tk_have_rows()' ],
	[ 'the_row()', 'tk_the_row()' ],
	[ 'get_sub_field()', 'tk_get_sub_field()' ],
	[ 'the_sub_field()', 'tk_the_sub_field()' ],
	[ 'update_field()', 'tk_update_field()' ],
	[ 'delete_field()', 'tk_delete_field()' ],
	[ 'get_fields()', 'tk_get_fields()' ],
	[ 'get_field_object()', 'tk_get_field_object()' ],
];

export default function HelpTab( { fieldTypes } ) {
	const types = Object.entries( fieldTypes || {} );

	return (
		<div className="tkf-help-tab">
			<section className="tkf-help__section">
				<h2>{ __( 'Field type reference', 'tk-fields' ) }</h2>
				<p className="tkf-muted">
					{ __(
						'The field types available in this installation. Types marked “(core)” ship with TK Fields; others may be registered by extensions.',
						'tk-fields'
					) }
				</p>
				{ types.length === 0 && (
					<p className="tkf-muted">
						{ __( 'Field types are still loading…', 'tk-fields' ) }
					</p>
				) }
				<dl className="tkf-help__types">
					{ types.map( ( [ type, def ] ) => (
						<div className="tkf-help__type" key={ type }>
							<dt>
								<code>{ type }</code>
								<span className="tkf-help__type-label">
									{ def.label }
								</span>
							</dt>
							<dd>{ def.description }</dd>
						</div>
					) ) }
				</dl>
			</section>

			<section className="tkf-help__section">
				<h2>{ __( 'Location rules', 'tk-fields' ) }</h2>
				<p>
					{ __(
						'Location rules decide which edit screens show this field group. A rule has three parts: a parameter (what to check), an operator (is equal to / is not equal to), and a value.',
						'tk-fields'
					) }
				</p>
				<ul className="tkf-help__list">
					<li>
						<strong>{ __( 'Post type', 'tk-fields' ) }</strong>
						{ ' — ' }
						{ __(
							'shows the group on posts, pages, or any custom post type.',
							'tk-fields'
						) }
					</li>
					<li>
						<strong>{ __( 'Page template', 'tk-fields' ) }</strong>
						{ ' — ' }
						{ __(
							'matches the template filename assigned to the page (e.g. template-fullwidth.php).',
							'tk-fields'
						) }
					</li>
				</ul>
				<p>
					{ __(
						'With “Match all rules”, every rule must match. With “Match any rule”, the group appears when at least one rule matches. A group with no rules appears everywhere.',
						'tk-fields'
					) }
				</p>
			</section>

			<section className="tkf-help__section">
				<h2>{ __( 'Using fields in templates', 'tk-fields' ) }</h2>
				<p>
					{ __(
						'Every field is read by its field name — the machine name you set in the field settings.',
						'tk-fields'
					) }
				</p>
				<CodeBlock code={ READ_SNIPPET } />
				<h3>{ __( 'Repeater loops', 'tk-fields' ) }</h3>
				<CodeBlock code={ REPEATER_SNIPPET } />
			</section>

			<section className="tkf-help__section">
				<h2>{ __( 'Repeater fields', 'tk-fields' ) }</h2>
				<p>
					{ __(
						'A repeater is a repeatable set of sub-fields: editors add, reorder, and remove rows, and each row holds one value per sub-field. Rows are stored as native blocks — row order is presentation only, and every row keeps a stable ID behind the scenes.',
						'tk-fields'
					) }
				</p>
				<ul className="tkf-help__list">
					<li>
						<strong>{ __( 'Min / max rows', 'tk-fields' ) }</strong>
						{ ' — ' }
						{ __( 'row-count bounds enforced when the post is saved.', 'tk-fields' ) }
					</li>
					<li>
						<strong>{ __( 'Button label', 'tk-fields' ) }</strong>
						{ ' — ' }
						{ __( 'the “Add Row” button text (defaults to “Add Row”).', 'tk-fields' ) }
					</li>
					<li>
						<strong>{ __( 'Layout', 'tk-fields' ) }</strong>
						{ ' — ' }
						{ __( 'list or grid presentation of the rows in the editor.', 'tk-fields' ) }
					</li>
					<li>
						<strong>{ __( 'Row summary field', 'tk-fields' ) }</strong>
						{ ' — ' }
						{ __( 'which sub-field labels a collapsed row; display only, never stored.', 'tk-fields' ) }
					</li>
					<li>
						<strong>{ __( 'Sub-fields', 'tk-fields' ) }</strong>
						{ ' — ' }
						{ __( 'the fields inside each row, edited inline like a group’s children.', 'tk-fields' ) }
					</li>
				</ul>
				<p>
					{ __(
						'Repeaters nest two levels deep (e.g. course → modules → lessons). A third level is rejected with an error, never silently dropped. Clone and flexible content fields cannot be used as sub-fields. The repeater requires the block editor, same as flexible content.',
						'tk-fields'
					) }
				</p>
				<h3>{ __( 'Reading rows in templates', 'tk-fields' ) }</h3>
				<p>
					{ __(
						'Use the template loop: tk_have_rows() opens the loop over the field’s rows, tk_the_row() advances to the next row, and tk_get_sub_field() (or tk_the_sub_field() to echo) reads a sub-field from the current row. See “Repeater loops” above for the full snippet.',
						'tk-fields'
					) }
				</p>
				<h3>{ __( 'AI import keys', 'tk-fields' ) }</h3>
				<p>
					{ __(
						'When importing a field group from AI-generated JSON, a repeater accepts: sub_fields (recursive), min, max, button_label, layout (“list” or “grid” — ACF’s table, row, and block layouts are mapped onto these), and collapsed (ACF’s collapsed setting — which sub-field drives the row summary).',
						'tk-fields'
					) }
				</p>
			</section>

			<section className="tkf-help__section">
				<h2>{ __( 'Migrating from ACF', 'tk-fields' ) }</h2>
				<p>
					{ __(
						'TK Fields ships an opt-in compatibility shim (“Migration Mode”) that defines the familiar ACF template functions on top of TK Fields storage. It is never loaded by default: if ACF or Secure Custom Fields is active, the shim refuses to load and shows an admin notice instead of risking a fatal error.',
						'tk-fields'
					) }
				</p>
				<CodeBlock code={ MIGRATION_SNIPPET } />
				<h3>{ __( 'Function mapping', 'tk-fields' ) }</h3>
				<table className="tkf-table widefat striped tkf-help__map">
					<thead>
						<tr>
							<th>{ __( 'ACF (shim)', 'tk-fields' ) }</th>
							<th>{ __( 'Native TK Fields', 'tk-fields' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ MIGRATION_ROWS.map( ( [ acf, tk ] ) => (
							<tr key={ acf }>
								<td>
									<code>{ acf }</code>
								</td>
								<td>
									<code>{ tk }</code>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
				<p className="tkf-muted">
					{ __(
						'Note: the shim bridges theme template calls only. Field-group definitions are managed here in TK Fields, not in PHP.',
						'tk-fields'
					) }
				</p>
			</section>
		</div>
	);
}
