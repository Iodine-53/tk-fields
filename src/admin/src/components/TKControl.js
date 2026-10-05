/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * <TKControl> — the systematic tooltip wrapper.
 *
 * EVERY control in the app renders through this component. It owns the
 * label row (label text + keyboard-focusable Tooltip help-icon button) and
 * the help/error text layout below the control.
 *
 * Rules for app code:
 *  - Never pass `label=` to a @wordpress/components control; the wrapper
 *    owns the label. The child control receives an `id` (injected via
 *    cloneElement) so the <label htmlFor> association holds.
 *  - Composite controls (e.g. the choices key/value editor) are wrapped
 *    once at the top level; their inner sub-inputs use aria-label instead
 *    of nested TKControls.
 */
import { cloneElement, isValidElement } from '@wordpress/element';
import { useInstanceId } from '@wordpress/compose';
import { Tooltip, Icon } from '@wordpress/components';
import { help as helpIcon } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';

export default function TKControl( {
	label,
	tooltip,
	help,
	error,
	htmlFor,
	required = false,
	className = '',
	children,
} ) {
	const instanceId = useInstanceId( TKControl, 'tkf-control' );
	const controlId = htmlFor || instanceId;

	// Inject the id into the child control so <label htmlFor> works.
	// Children that are not elements (or already carry an id) are untouched.
	let child = children;
	if ( isValidElement( children ) && ! children.props.id ) {
		child = cloneElement( children, { id: controlId } );
	}

	return (
		<div
			className={ `tkf-control${ error ? ' tkf-control--error' : '' }${ className ? ` ${ className }` : '' }` }
		>
			<div className="tkf-control__label-row">
				<label className="tkf-control__label" htmlFor={ controlId }>
					{ label }
					{ required && (
						<span className="tkf-control__required" aria-hidden="true">
							{ ' *' }
						</span>
					) }
				</label>
				{ tooltip && (
					<Tooltip text={ tooltip } position="top center">
						<button
							type="button"
							className="tkf-control__help-btn"
							aria-label={ sprintf(
								/* translators: %s: control label */
								__( 'Help for: %s', 'tk-fields' ),
								label
							) }
						>
							<Icon icon={ helpIcon } size={ 16 } />
						</button>
					</Tooltip>
				) }
			</div>
			<div className="tkf-control__field">{ child }</div>
			{ error && (
				<p className="tkf-control__error" role="alert">
					{ error }
				</p>
			) }
			{ help && ! error && <p className="tkf-control__help-text">{ help }</p> }
		</div>
	);
}
