/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * Small shared UI primitives.
 */
import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export function CopyButton( { text, label } ) {
	const [ copied, setCopied ] = useState( false );

	const onCopy = async () => {
		try {
			await navigator.clipboard.writeText( text );
		} catch ( e ) {
			// Clipboard API unavailable (permissions / insecure context) —
			// fall back to a temporary textarea + execCommand.
			const ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.setAttribute( 'readonly', '' );
			ta.style.position = 'absolute';
			ta.style.left = '-9999px';
			document.body.appendChild( ta );
			ta.select();
			try {
				document.execCommand( 'copy' );
			} catch ( err ) {
				// Give up silently; the user can select the code manually.
			}
			document.body.removeChild( ta );
		}
		setCopied( true );
		window.setTimeout( () => setCopied( false ), 1600 );
	};

	return (
		<Button
			variant="secondary"
			size="small"
			className="tkf-copy-btn"
			onClick={ onCopy }
			aria-label={ label || __( 'Copy code to clipboard', 'tk-fields' ) }
		>
			{ copied ? __( 'Copied!', 'tk-fields' ) : __( 'Copy', 'tk-fields' ) }
		</Button>
	);
}

export function CodeBlock( { code, language = 'php' } ) {
	return (
		<div className="tkf-code-block">
			<div className="tkf-code-block__bar">
				<span className="tkf-code-block__lang">{ language }</span>
				<CopyButton text={ code } />
			</div>
			<pre className="tkf-code-block__pre">
				<code>{ code }</code>
			</pre>
		</div>
	);
}

/**
 * Segmented "all / any" style switch.
 */
export function SegmentedControl( { label, options, value, onChange } ) {
	return (
		<div className="tkf-segmented" role="group" aria-label={ label }>
			{ options.map( ( opt ) => (
				<button
					key={ opt.value }
					type="button"
					className={
						'tkf-segmented__btn' +
						( opt.value === value ? ' tkf-segmented__btn--active' : '' )
					}
					aria-pressed={ opt.value === value }
					onClick={ () => onChange( opt.value ) }
				>
					{ opt.label }
				</button>
			) ) }
		</div>
	);
}

/**
 * Colored badge for a field type slug.
 *
 * v0.12.0: colors are PER CATEGORY, not per type (34 arbitrary hues was
 * noise; 6 category colors are scannable). The backend sends `category` on
 * every type; the map below mirrors it for callers that only have a slug.
 */
const CATEGORY_COLORS = {
	basic: '#3858e9',
	content: '#5b6b00',
	choice: '#007a9e',
	relational: '#8c5ce8',
	advanced: '#b85c00',
	layout: '#50575e',
};

const TYPE_CATEGORIES = {
	text: 'basic',
	textarea: 'basic',
	number: 'basic',
	email: 'basic',
	url: 'basic',
	password: 'basic',
	range: 'basic',
	checkbox: 'basic',
	date: 'basic',
	datetime: 'basic',
	time: 'basic',
	wysiwyg: 'content',
	image: 'content',
	file: 'content',
	gallery: 'content',
	oembed: 'content',
	link: 'content',
	icon: 'content',
	map: 'content',
	color: 'content',
	select: 'choice',
	radio: 'choice',
	button_group: 'choice',
	post_object: 'relational',
	page_link: 'relational',
	taxonomy: 'relational',
	user: 'relational',
	relationship: 'relational',
	clone: 'advanced',
	group: 'layout',
	flexible_content: 'layout',
	repeater: 'layout',
	message: 'layout',
	separator: 'layout',
	tab: 'layout',
};

export function FieldTypeBadge( { type, label, category } ) {
	const cat = category || TYPE_CATEGORIES[ type ] || 'basic';
	const color = CATEGORY_COLORS[ cat ] || '#50575e';
	return (
		<span
			className={ 'tkf-type-badge tkf-type-badge--' + cat }
			style={ { backgroundColor: color } }
			title={ cat }
		>
			{ label || type }
		</span>
	);
}

/**
 * Status pill for the group list.
 */
export function StatusPill( { status } ) {
	const isPublish = status === 'publish';
	return (
		<span
			className={
				'tkf-status-pill' +
				( isPublish ? ' tkf-status-pill--publish' : ' tkf-status-pill--draft' )
			}
		>
			{ isPublish ? __( 'Published', 'tk-fields' ) : __( 'Draft', 'tk-fields' ) }
		</span>
	);
}
