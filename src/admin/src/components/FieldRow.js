/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * FieldRow — the ONE recursive field-row component (v0.12.0).
 *
 * Used by the React builder for top-level fields (depth 0) and exposed as
 * window.TKFFieldRow for the classic vanilla editors (sub-fields depth 1,
 * layout sub-fields depth 2) — literally the same component, so nested rows
 * can never drift out of sync with top-level rows again (the old inline
 * grid in class-admin.php that truncated sub-field labels is gone).
 *
 * Props:
 *   field        { key, name, label, type }
 *   typeLabel    Human label for the type (defaults to the slug)
 *   category     Type category for the badge color (defaults to 'basic')
 *   depth        0 | 1 | 2 — indentation + tree line
 *   expanded     bool
 *   onToggleExpand()
 *   issues       number of validation issues to badge (caller gates on _touched)
 *   index, total — for up/down bounds
 *   onMoveUp, onMoveDown (optional)
 *   onDuplicate, onDelete (optional)
+ *   icon         optional dashicon slug — rendered as a small thumbnail
+ *                before the type badge (used by layout cards)
+ *   badges       optional array of strings — rendered as small chips after
+ *                the field name (used for layout min/max)
 *   drag         optional { onDragStart, onDragOver, onDrop, onDragEnd }
 *   isDropTarget bool
 *   children     the expanded settings body
 *
 * Every control carries a Tooltip (compact popover — see the tooltip CSS
 * fix in styles.css). The field name renders in monospace everywhere.
 */
import { Button, Dashicon, Tooltip } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { FieldTypeBadge } from './ui';

export default function FieldRow( props ) {
	const {
		field,
		typeLabel,
		category,
		depth = 0,
		expanded,
		onToggleExpand,
		issues = 0,
		index,
		total,
		onMoveUp,
		onMoveDown,
		onDuplicate,
		onDelete,
		icon,
		badges,
		drag,
		isDropTarget,
		children,
	} = props;

	const label = field.label || field.name;
	const dragProps = drag
		? {
				draggable: true,
				onDragStart: drag.onDragStart,
				onDragOver: drag.onDragOver,
				onDrop: drag.onDrop,
				onDragEnd: drag.onDragEnd,
		  }
		: {};

	return (
		<div
			className={
				'tkf-field-row tkf-field-row--depth-' +
				depth +
				( expanded ? ' is-expanded' : '' ) +
				( isDropTarget ? ' is-drop-target' : '' )
			}
			data-field-key={ field.key }
		>
			<div className="tkf-field-row__head" { ...dragProps }>
				{ drag && (
					<Tooltip text={ __( 'Drag to reorder', 'tk-fields' ) }>
						<span className="tkf-field-row__grip" aria-hidden="true">
							<Dashicon icon="menu" />
						</span>
					</Tooltip>
				) }
				{ icon && (
					<span className="tkf-field-row__thumb" aria-hidden="true">
						<Dashicon icon={ icon } />
					</span>
				) }
				<FieldTypeBadge
					type={ field.type }
					label={ typeLabel || field.type }
					category={ category }
				/>
				<Tooltip
					text={
						expanded
							? __( 'Collapse field settings', 'tk-fields' )
							: __( 'Expand field settings', 'tk-fields' )
					}
				>
					<button
						type="button"
						className="tkf-field-row__summary"
						onClick={ onToggleExpand }
						aria-expanded={ !! expanded }
					>
						<span className="tkf-field-row__label">{ label }</span>
						<code className="tkf-field-row__name">{ field.name }</code>
						{ ( badges || [] ).map( ( badge ) => (
							<span className="tkf-field-row__badge" key={ badge }>
								{ badge }
							</span>
						) ) }
					</button>
				</Tooltip>
				{ issues > 0 && (
					<Tooltip
						text={ sprintf(
							/* translators: %d: number of issues */
							__( '%d issue(s) — expand to fix', 'tk-fields' ),
							issues
						) }
					>
						<span className="tkf-field-row__issues" role="status">
							{ issues }
						</span>
					</Tooltip>
				) }
				<div className="tkf-field-row__actions">
					{ onMoveUp && (
						<Tooltip text={ __( 'Move up', 'tk-fields' ) }>
							<Button
								icon="arrow-up-alt2"
								size="small"
								disabled={ index === 0 }
								onClick={ onMoveUp }
								aria-label={ __( 'Move field up', 'tk-fields' ) }
							/>
						</Tooltip>
					) }
					{ onMoveDown && (
						<Tooltip text={ __( 'Move down', 'tk-fields' ) }>
							<Button
								icon="arrow-down-alt2"
								size="small"
								disabled={ index === total - 1 }
								onClick={ onMoveDown }
								aria-label={ __( 'Move field down', 'tk-fields' ) }
							/>
						</Tooltip>
					) }
					{ onDuplicate && (
						<Tooltip text={ __( 'Duplicate field', 'tk-fields' ) }>
							<Button
								icon="admin-page"
								size="small"
								onClick={ onDuplicate }
								aria-label={ __( 'Duplicate field', 'tk-fields' ) }
							/>
						</Tooltip>
					) }
					{ onDelete && (
						<Tooltip text={ __( 'Delete field', 'tk-fields' ) }>
							<Button
								icon="trash"
								size="small"
								isDestructive
								onClick={ onDelete }
								aria-label={ __( 'Delete field', 'tk-fields' ) }
							/>
						</Tooltip>
					) }
					<Tooltip
						text={
							expanded
								? __( 'Collapse', 'tk-fields' )
								: __( 'Expand', 'tk-fields' )
						}
					>
						<Button
							icon={ expanded ? 'arrow-up-alt2' : 'arrow-down-alt2' }
							size="small"
							onClick={ onToggleExpand }
							aria-label={
								expanded
									? __( 'Collapse field settings', 'tk-fields' )
									: __( 'Expand field settings', 'tk-fields' )
							}
						/>
					</Tooltip>
				</div>
			</div>
			{ expanded && (
				<div className="tkf-field-row__body">{ children }</div>
			) }
		</div>
	);
}
