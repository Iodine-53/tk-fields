/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * Content Type editor (v0.15.0): sections for Basic / Visibility / URLs /
 * Features / Taxonomies / Advanced. Every control goes through <TKControl>
 * so tooltips stay consistent with the field-group builder.
 */
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import {
	Button,
	Modal,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import TKControl from './TKControl';

const SUPPORTS_OPTIONS = [
	{ value: 'title', label: 'Title' },
	{ value: 'editor', label: 'Editor' },
	{ value: 'excerpt', label: 'Excerpt' },
	{ value: 'thumbnail', label: 'Featured image' },
	{ value: 'revisions', label: 'Revisions' },
	{ value: 'comments', label: 'Comments' },
	{ value: 'page-attributes', label: 'Page attributes' },
	{ value: 'custom-fields', label: 'Custom fields' },
	{ value: 'author', label: 'Author' },
];

const ICON_OPTIONS = [
	[ 'dashicons dashicons-archive', __( 'Boxes', 'tk-fields' ) ],
	[ 'dashicons dashicons-admin-post', __( 'Document', 'tk-fields' ) ],
	[ 'dashicons dashicons-media-document', __( 'File', 'tk-fields' ) ],
	[ 'dashicons dashicons-editor-paragraph', __( 'Text', 'tk-fields' ) ],
	[ 'dashicons dashicons-editor-textcolor', __( 'Type', 'tk-fields' ) ],
	[ 'dashicons dashicons-format-image', __( 'Image', 'tk-fields' ) ],
	[ 'dashicons dashicons-format-gallery', __( 'Gallery', 'tk-fields' ) ],
	[ 'dashicons dashicons-format-video', __( 'Video', 'tk-fields' ) ],
	[ 'dashicons dashicons-calendar', __( 'Calendar', 'tk-fields' ) ],
	[ 'dashicons dashicons-clock', __( 'Clock', 'tk-fields' ) ],
	[ 'dashicons dashicons-calendar-alt', __( 'Schedule', 'tk-fields' ) ],
	[ 'dashicons dashicons-star-filled', __( 'Star', 'tk-fields' ) ],
	[ 'dashicons dashicons-admin-site', __( 'Globe', 'tk-fields' ) ],
	[ 'dashicons dashicons-location-alt', __( 'Location', 'tk-fields' ) ],
	[ 'dashicons dashicons-tag', __( 'Tags', 'tk-fields' ) ],
	[ 'dashicons dashicons-admin-users', __( 'User', 'tk-fields' ) ],
	[ 'dashicons dashicons-layout', __( 'Layers', 'tk-fields' ) ],
	[ 'dashicons dashicons-grid-view', __( 'Grid', 'tk-fields' ) ],
	[ 'dashicons dashicons-category', __( 'Folder', 'tk-fields' ) ],
	[ 'dashicons dashicons-editor-table', __( 'Inbox', 'tk-fields' ) ],
	[ 'dashicons dashicons-admin-links', __( 'Link', 'tk-fields' ) ],
	[ 'dashicons dashicons-admin-page', __( 'Link alt', 'tk-fields' ) ],
	[ 'dashicons dashicons-email', __( 'Mail', 'tk-fields' ) ],
	[ 'dashicons dashicons-share', __( 'Share', 'tk-fields' ) ],
	[ 'dashicons dashicons-clipboard', __( 'Copy', 'tk-fields' ) ],
	[ 'dashicons dashicons-edit-large', __( 'Pen', 'tk-fields' ) ],
	[ 'dashicons dashicons-art', __( 'Palette', 'tk-fields' ) ],
	[ 'dashicons dashicons-calculator', __( 'Calculator', 'tk-fields' ) ],
	[ 'dashicons dashicons-yes-alt', __( 'Checkbox', 'tk-fields' ) ],
	[ 'dashicons dashicons-list-view', __( 'Radio', 'tk-fields' ) ],
	[ 'dashicons dashicons-welcome-widgets-menus', __( 'Browser', 'tk-fields' ) ],
	[ 'dashicons dashicons-info-outline', __( 'Info', 'tk-fields' ) ],
	[ 'dashicons dashicons-admin-settings', __( 'Settings', 'tk-fields' ) ],
	[ 'dashicons dashicons-lock', __( 'Lock', 'tk-fields' ) ],
	[ 'dashicons dashicons-minus', __( 'Minus', 'tk-fields' ) ],
];

const DEFAULTS = {
	slug: '',
	singular: '',
	plural: '',
	description: '',
	public: true,
	publicly_queryable: true,
	show_ui: true,
	show_in_nav_menus: true,
	show_in_rest: true,
	rest_base: '',
	has_archive: false,
	archive_slug: '',
	exclude_from_search: false,
	can_export: true,
	hierarchical: false,
	supports: [ 'title', 'editor' ],
	rewrite: true,
	rewrite_slug: '',
	with_front: true,
	query_var: true,
	query_var_value: '',
	capability_type: 'post',
	menu_position: 20,
	show_in_menu: true,
	menu_icon: '',
	taxonomies: [],
	delete_with_user: false,
};

function FieldError( { message } ) {
	if ( ! message ) {
		return null;
	}
	return (
		<p className="tkf-control__error" role="alert">
			{ message }
		</p>
	);
}

export default function ContentTypeEditor( { typeId, navigate, setDirty, notify } ) {
	const isNew = typeId === null;
	const [ def, setDef ] = useState( null ); // null = loading
	const [ originalSlug, setOriginalSlug ] = useState( '' );
	const [ slugLocked, setSlugLocked ] = useState( false );
	const [ slugCheck, setSlugCheck ] = useState( null ); // { valid, errors, warnings }
	const [ allTaxonomies, setAllTaxonomies ] = useState( [] );
	const [ saving, setSaving ] = useState( false );
	const [ saveError, setSaveError ] = useState( '' );
	const [ fieldErrors, setFieldErrors ] = useState( {} );
	const [ migrateOpen, setMigrateOpen ] = useState( false );
	const [ migrateBusy, setMigrateBusy ] = useState( false );
	const debounceRef = useRef( null );

	useEffect( () => {
		let cancelled = false;
		const done = ( loaded ) => {
			if ( cancelled ) {
				return;
			}
			setDef( loaded );
		};
		if ( isNew ) {
			done( { ...DEFAULTS } );
		} else {
			api
				.getContentType( typeId )
				.then( ( data ) => {
					const full = data.content_type;
					setOriginalSlug( full.slug );
					setSlugLocked( ( full.post_count || 0 ) > 0 );
					done( { ...DEFAULTS, ...full } );
				} )
				.catch( ( err ) => {
					notify( err.message, 'error' );
					navigate( { name: 'list' }, { force: true } );
				} );
		}
		api
			.listCtTaxonomies()
			.then( ( data ) => {
				if ( ! cancelled ) {
					setAllTaxonomies( data.taxonomies || [] );
				}
			} )
			.catch( () => {} );
		return () => {
			cancelled = true;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const update = ( patch ) => {
		setDef( ( prev ) => ( { ...prev, ...patch } ) );
		setDirty( true );
	};

	// Debounced live slug validation.
	useEffect( () => {
		if ( ! def ) {
			return;
		}
		window.clearTimeout( debounceRef.current );
		const slug = ( def.slug || '' ).trim();
		if ( ! slug ) {
			setSlugCheck( null );
			return;
		}
		debounceRef.current = window.setTimeout( () => {
			api
				.validateCtSlug( slug, isNew ? null : typeId )
				.then( setSlugCheck )
				.catch( () => setSlugCheck( null ) );
		}, 500 );
		return () => window.clearTimeout( debounceRef.current );
	}, [ def?.slug, isNew, typeId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Auto-suggest the slug from the plural label while the user types it
	// (only until they touch the slug field themselves).
	const slugTouched = useRef( false );
	useEffect( () => {
		if ( ! def || isNew === false && originalSlug ) {
			return;
		}
		if ( slugTouched.current ) {
			return;
		}
		const suggested = ( def.plural || '' )
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '_' )
			.replace( /^_+|_+$/g, '' )
			.slice( 0, 20 );
		if ( suggested !== def.slug ) {
			setDef( ( prev ) => ( { ...prev, slug: suggested } ) );
		}
	}, [ def?.plural ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const toggleSupport = ( value ) => {
		const supports = def.supports || [];
		update( {
			supports: supports.includes( value )
				? supports.filter( ( s ) => s !== value )
				: [ ...supports, value ],
		} );
	};

	const toggleTaxonomy = ( value ) => {
		const tax = def.taxonomies || [];
		update( {
			taxonomies: tax.includes( value )
				? tax.filter( ( s ) => s !== value )
				: [ ...tax, value ],
		} );
	};

	const slugChanged = ! isNew && def && def.slug !== originalSlug;

	const permalinkPreview = useMemo( () => {
		if ( ! def || ! def.public || ! def.rewrite ) {
			return '';
		}
		const base = ( def.rewrite_slug || def.slug || 'type' ).replace( /^\/|\/$/g, '' );
		const front = def.with_front ? '' : '';
		return `${ front }/${ base }/sample-entry/`;
	}, [ def ] );

	const save = () => {
		const errors = {};
		if ( ! ( def.singular || '' ).trim() ) {
			errors.singular = __( 'Singular label is required.', 'tk-fields' );
		}
		if ( ! ( def.plural || '' ).trim() ) {
			errors.plural = __( 'Plural label is required.', 'tk-fields' );
		}
		if ( ! ( def.slug || '' ).trim() ) {
			errors.slug = __( 'Slug is required.', 'tk-fields' );
		} else if ( slugCheck && ! slugCheck.valid ) {
			errors.slug = slugCheck.errors.join( ' ' );
		}
		if ( slugLocked && slugChanged ) {
			errors.slug = __(
				'The slug is locked because this type already has posts. Use “Rename slug” below to move posts to a new slug.',
				'tk-fields'
			);
		}
		setFieldErrors( errors );
		if ( Object.keys( errors ).length > 0 ) {
			return;
		}
		setSaving( true );
		setSaveError( '' );
		const payload = { ...def };
		delete payload.id;
		const call = isNew
			? api.createContentType( payload )
			: api.updateContentType( typeId, payload );
		call
			.then( ( data ) => {
				setDirty( false );
				notify(
					isNew
						? __( 'Content type created.', 'tk-fields' )
						: __( 'Content type saved.', 'tk-fields' )
				);
				if ( isNew && data.content_type && data.content_type.id ) {
					navigate( { name: 'edit', id: data.content_type.id }, { force: true } );
				} else {
					setOriginalSlug( def.slug );
					setSlugLocked( ( data.content_type.post_count || 0 ) > 0 );
					setDef( ( prev ) => ( { ...prev, ...data.content_type } ) );
				}
			} )
			.catch( ( err ) => setSaveError( err.message ) )
			.finally( () => setSaving( false ) );
	};

	const doMigrateSlug = () => {
		setMigrateBusy( true );
		api
			.migrateCtSlug( typeId, def.slug )
			.then( ( data ) => {
				setDirty( false );
				setMigrateOpen( false );
				setOriginalSlug( def.slug );
				setDef( ( prev ) => ( { ...prev, ...data.content_type } ) );
				setFieldErrors( {} );
				notify( __( 'Slug renamed and posts moved.', 'tk-fields' ) );
			} )
			.catch( ( err ) => notify( err.message, 'error' ) )
			.finally( () => setMigrateBusy( false ) );
	};

	if ( ! def ) {
		return (
			<div className="tkf-loading">
				<Spinner /> { __( 'Loading…', 'tk-fields' ) }
			</div>
		);
	}

	return (
		<div className="tkf-editor">
			<div className="tkf-editor__header">
				<Button
					variant="tertiary"
					onClick={ () => navigate( { name: 'list' } ) }
				>
					{ __( '← All Content Types', 'tk-fields' ) }
				</Button>
				<h1 className="tkf-editor__title">
					{ isNew
						? __( 'Add Content Type', 'tk-fields' )
						: sprintf(
								/* translators: %s: content type label */
								__( 'Edit “%s”', 'tk-fields' ),
								def.plural || def.slug
						  ) }
				</h1>
				<div className="tkf-editor__actions">
					<Button variant="secondary" onClick={ () => navigate( { name: 'list' } ) }>
						{ __( 'Cancel', 'tk-fields' ) }
					</Button>
					<Button variant="primary" isBusy={ saving } disabled={ saving } onClick={ save }>
						{ isNew
							? __( 'Create Content Type', 'tk-fields' )
							: __( 'Save Changes', 'tk-fields' ) }
					</Button>
				</div>
			</div>

			{ saveError && (
				<div className="tkf-error-box" role="alert">
					{ saveError }
				</div>
			) }

			{ /* ---------------- Basic ---------------- */ }
			<section className="tkf-section">
				<h2 className="tkf-section__title">{ __( 'Basic', 'tk-fields' ) }</h2>
				<div className="tkf-grid tkf-grid--2">
					<TKControl
						label={ __( 'Plural label', 'tk-fields' ) }
						required
						tooltip={ __(
							'The public name of the type, e.g. “Movies”. Shown in the admin menu and the archive heading.',
							'tk-fields'
						) }
					>
						<TextControl
							value={ def.plural }
							placeholder={ __( 'Movies', 'tk-fields' ) }
							onChange={ ( v ) => update( { plural: v } ) }
						/>
						<FieldError message={ fieldErrors.plural } />
					</TKControl>
					<TKControl
						label={ __( 'Singular label', 'tk-fields' ) }
						required
						tooltip={ __(
							'The singular name, e.g. “Movie”. Used in the “Add new” button and single labels.',
							'tk-fields'
						) }
					>
						<TextControl
							value={ def.singular }
							placeholder={ __( 'Movie', 'tk-fields' ) }
							onChange={ ( v ) => update( { singular: v } ) }
						/>
						<FieldError message={ fieldErrors.singular } />
					</TKControl>
				</div>
				<TKControl
					label={ __( 'Slug', 'tk-fields' ) }
					required
					tooltip={ __(
						'The machine name of the type: lowercase letters, numbers and underscores, up to 20 characters. Must not clash with a WordPress feature or another post type.',
						'tk-fields'
					) }
				>
					<TextControl
						value={ def.slug }
						disabled={ slugLocked && ! slugChanged }
						onChange={ ( v ) => {
							slugTouched.current = true;
							update( { slug: v } );
						} }
						help={
							slugLocked && ! slugChanged
								? __( 'Locked — this type already has posts.', 'tk-fields' )
								: ''
						}
					/>
					<FieldError message={ fieldErrors.slug } />
					{ slugCheck && slugCheck.errors.map( ( e, i ) => (
						<p key={ i } className="tkf-control__error" role="alert">{ e }</p>
					) ) }
					{ slugCheck && slugCheck.valid && slugCheck.warnings.map( ( w, i ) => (
						<Notice key={ i } status="warning" isDismissible={ false }>
							{ w }
						</Notice>
					) ) }
				</TKControl>
				{ ! isNew && (
					<p>
						<Button
							variant="link"
							disabled={ ! slugChanged }
							onClick={ () => setMigrateOpen( true ) }
						>
							{ __( 'Rename slug and move posts…', 'tk-fields' ) }
						</Button>
					</p>
				) }
				<TKControl
					label={ __( 'Description', 'tk-fields' ) }
					tooltip={ __(
						'Short description shown in the admin menu tooltip. Optional.',
						'tk-fields'
					) }
				>
					<TextareaControl
						rows={ 2 }
						value={ def.description }
						onChange={ ( v ) => update( { description: v } ) }
					/>
				</TKControl>
				<TKControl
					label={ __( 'Menu icon', 'tk-fields' ) }
					tooltip={ __(
						'The icon shown next to the type in the admin menu. Served from the plugin’s own icon font — no Dashicons dependency.',
						'tk-fields'
					) }
				>
					<div className="tkf-icon-grid" role="radiogroup" aria-label={ __( 'Menu icon', 'tk-fields' ) }>
						<button
							type="button"
							className={ `tkf-icon-grid__item${ def.menu_icon === '' ? ' is-selected' : '' }` }
							onClick={ () => update( { menu_icon: '' } ) }
							title={ __( 'Default', 'tk-fields' ) }
						>
							<span className="dashicons dashicons-admin-post" aria-hidden="true" />
						</button>
						{ ICON_OPTIONS.map( ( [ glyph, label ] ) => {
							const cls = glyph; // glyph is already the full 'dashicons dashicons-*' pair.
							return (
								<button
									key={ glyph }
									type="button"
									className={ `tkf-icon-grid__item${ def.menu_icon === cls ? ' is-selected' : '' }` }
									onClick={ () => update( { menu_icon: cls } ) }
									title={ label }
									aria-pressed={ def.menu_icon === cls }
								>
									<span className={ cls } aria-hidden="true" />
								</button>
							);
						} ) }
					</div>
				</TKControl>
			</section>

			{ /* ---------------- Visibility ---------------- */ }
			<section className="tkf-section">
				<h2 className="tkf-section__title">{ __( 'Visibility', 'tk-fields' ) }</h2>
				<div className="tkf-grid tkf-grid--2">
					<TKControl label={ __( 'Public', 'tk-fields' ) } tooltip={ __( 'Visible on the site and in the admin. Turn off for an internal type that is only managed programmatically.', 'tk-fields' ) }>
						<ToggleControl checked={ !! def.public } onChange={ ( v ) => update( { public: v } ) } />
					</TKControl>
					<TKControl label={ __( 'Publicly queryable', 'tk-fields' ) } tooltip={ __( 'Single entries can be viewed on the site. Usually matches “Public”.', 'tk-fields' ) }>
						<ToggleControl checked={ !! def.publicly_queryable } onChange={ ( v ) => update( { publicly_queryable: v } ) } />
					</TKControl>
					<TKControl label={ __( 'Show in admin UI', 'tk-fields' ) } tooltip={ __( 'Generates the edit screens for this type. Turn off only if entries are managed by code.', 'tk-fields' ) }>
						<ToggleControl checked={ !! def.show_ui } onChange={ ( v ) => update( { show_ui: v } ) } />
					</TKControl>
					<TKControl label={ __( 'Show in nav menus', 'tk-fields' ) } tooltip={ __( 'Offer the type’s entries in the Appearance → Menus editor.', 'tk-fields' ) }>
						<ToggleControl checked={ !! def.show_in_nav_menus } onChange={ ( v ) => update( { show_in_nav_menus: v } ) } />
					</TKControl>
					<TKControl label={ __( 'Show in REST API', 'tk-fields' ) } tooltip={ __( 'Expose the type in the WordPress REST API (and the block editor’s URL field). Needed for headless sites.', 'tk-fields' ) }>
						<ToggleControl checked={ !! def.show_in_rest } onChange={ ( v ) => update( { show_in_rest: v } ) } />
					</TKControl>
					<TKControl label={ __( 'Has archive', 'tk-fields' ) } tooltip={ __( 'Add a listing page for the type, e.g. yoursite.com/movies/.', 'tk-fields' ) }>
						<ToggleControl checked={ !! def.has_archive } onChange={ ( v ) => update( { has_archive: v } ) } />
					</TKControl>
					<TKControl label={ __( 'Exclude from search', 'tk-fields' ) } tooltip={ __( 'Hide entries of this type from front-end search results.', 'tk-fields' ) }>
						<ToggleControl checked={ !! def.exclude_from_search } onChange={ ( v ) => update( { exclude_from_search: v } ) } />
					</TKControl>
					<TKControl label={ __( 'Can export', 'tk-fields' ) } tooltip={ __( 'Include entries of this type in Tools → Export.', 'tk-fields' ) }>
						<ToggleControl checked={ !! def.can_export } onChange={ ( v ) => update( { can_export: v } ) } />
					</TKControl>
				</div>
				<div className="tkf-grid tkf-grid--2">
					<TKControl
						label={ __( 'REST base', 'tk-fields' ) }
						tooltip={ __( 'URL segment of the type’s REST routes, e.g. “movies”. Defaults to the slug. Cannot be “wp/v2” or any core route.', 'tk-fields' ) }
					>
						<TextControl
							value={ def.rest_base }
							placeholder={ def.slug || 'slug' }
							onChange={ ( v ) => update( { rest_base: v } ) }
						/>
						<FieldError message={ fieldErrors.rest_base } />
					</TKControl>
					{ def.has_archive && (
						<TKControl
							label={ __( 'Archive slug', 'tk-fields' ) }
							tooltip={ __( 'URL of the archive page. Defaults to the slug.', 'tk-fields' ) }
						>
							<TextControl
								value={ def.archive_slug }
								placeholder={ def.slug || 'slug' }
								onChange={ ( v ) => update( { archive_slug: v } ) }
							/>
						</TKControl>
					) }
				</div>
			</section>

			{ /* ---------------- URLs ---------------- */ }
			<section className="tkf-section">
				<h2 className="tkf-section__title">{ __( 'URLs', 'tk-fields' ) }</h2>
				<div className="tkf-grid tkf-grid--2">
					<TKControl label={ __( 'Pretty permalinks', 'tk-fields' ) } tooltip={ __( 'Generate readable URLs (e.g. /movies/titanic/) instead of query strings. Turning this off after launch breaks existing links.', 'tk-fields' ) }>
						<ToggleControl checked={ !! def.rewrite } onChange={ ( v ) => update( { rewrite: v } ) } />
					</TKControl>
					<TKControl label={ __( 'With front base', 'tk-fields' ) } tooltip={ __( 'Keep the blog prefix (e.g. /blog/) in front of the type’s URLs. Off gives clean URLs like /movies/titanic/.', 'tk-fields' ) }>
						<ToggleControl checked={ !! def.with_front } onChange={ ( v ) => update( { with_front: v } ) } disabled={ ! def.rewrite } />
					</TKControl>
				</div>
				{ def.rewrite && (
					<TKControl
						label={ __( 'Rewrite slug', 'tk-fields' ) }
						tooltip={ __( 'URL segment for single entries. Defaults to the type slug. Changing this after launch breaks existing links.', 'tk-fields' ) }
					>
						<TextControl
							value={ def.rewrite_slug }
							placeholder={ def.slug || 'slug' }
							onChange={ ( v ) => update( { rewrite_slug: v } ) }
						/>
					</TKControl>
				) }
				{ permalinkPreview !== '' && (
					<p className="tkf-muted">
						{ __( 'Preview:', 'tk-fields' ) }{ ' ' }
						<code>/{ permalinkPreview.replace( /^\//, '' ) }</code>
					</p>
				) }
			</section>

			{ /* ---------------- Features ---------------- */ }
			<section className="tkf-section">
				<h2 className="tkf-section__title">{ __( 'Features', 'tk-fields' ) }</h2>
				<div className="tkf-grid tkf-grid--3">
					{ SUPPORTS_OPTIONS.map( ( opt ) => {
						const locked = opt.value === 'title';
						return (
							<TKControl
								key={ opt.value }
								label={ opt.label }
								tooltip={
									locked
										? __( 'The title is always on — every entry needs a title.', 'tk-fields' )
										: __( 'Enable this editing feature for the type.', 'tk-fields' )
								}
							>
								<ToggleControl
									checked={ ( def.supports || [] ).includes( opt.value ) || locked }
									disabled={ locked }
									onChange={ () => toggleSupport( opt.value ) }
								/>
							</TKControl>
						);
					} ) }
					<TKControl label={ __( 'Hierarchical', 'tk-fields' ) } tooltip={ __( 'Allow parent/child entries like pages. Needed for the “Page attributes” feature to work.', 'tk-fields' ) }>
						<ToggleControl checked={ !! def.hierarchical } onChange={ ( v ) => update( { hierarchical: v } ) } />
					</TKControl>
				</div>
			</section>

			{ /* ---------------- Taxonomies ---------------- */ }
			<section className="tkf-section">
				<h2 className="tkf-section__title">{ __( 'Taxonomies', 'tk-fields' ) }</h2>
				<p className="tkf-muted">
					{ __( 'Attach built-in or custom taxonomies (categories, tags) to this type.', 'tk-fields' ) }
				</p>
				<div className="tkf-grid tkf-grid--3">
					{ allTaxonomies.map( ( tax ) => (
						<TKControl
							key={ tax.slug }
							label={ tax.label }
							tooltip={
								tax.hierarchical
									? __( 'Category-style taxonomy (hierarchical).', 'tk-fields' )
									: __( 'Tag-style taxonomy.', 'tk-fields' )
							}
						>
							<ToggleControl
								checked={ ( def.taxonomies || [] ).includes( tax.slug ) }
								onChange={ () => toggleTaxonomy( tax.slug ) }
							/>
						</TKControl>
					) ) }
				</div>
				{ allTaxonomies.length === 0 && (
					<p className="tkf-muted">{ __( 'No public taxonomies available.', 'tk-fields' ) }</p>
				) }
			</section>

			{ /* ---------------- Advanced ---------------- */ }
			<section className="tkf-section">
				<h2 className="tkf-section__title">{ __( 'Advanced', 'tk-fields' ) }</h2>
				<div className="tkf-grid tkf-grid--2">
					<TKControl
						label={ __( 'Menu position', 'tk-fields' ) }
						tooltip={ __( 'Position in the admin menu. 5 = below Posts, 20 = below Pages, 100 = near the bottom.', 'tk-fields' ) }
					>
						<TextControl
							type="number"
							min={ 5 }
							max={ 100 }
							value={ def.menu_position }
							onChange={ ( v ) => update( { menu_position: v } ) }
						/>
					</TKControl>
					<TKControl
						label={ __( 'Capability type', 'tk-fields' ) }
						tooltip={ __(
							'Which WordPress roles can manage this type: like posts (“post”) or like pages (“page”). Fine-grained capability mapping arrives in a later version.',
							'tk-fields'
						) }
					>
						<SelectControl
							value={ def.capability_type }
							options={ [
								{ value: 'post', label: __( 'Like posts', 'tk-fields' ) },
								{ value: 'page', label: __( 'Like pages', 'tk-fields' ) },
							] }
							onChange={ ( v ) => update( { capability_type: v } ) }
						/>
					</TKControl>
					<TKControl label={ __( 'Show in admin menu', 'tk-fields' ) } tooltip={ __( 'List the type in the admin menu. Turn off only if entries are managed elsewhere.', 'tk-fields' ) }>
						<ToggleControl checked={ !! def.show_in_menu } onChange={ ( v ) => update( { show_in_menu: v } ) } />
					</TKControl>
					<TKControl label={ __( 'Query variable', 'tk-fields' ) } tooltip={ __( 'Allow ?type-slug=entry URLs as an alternative to pretty permalinks.', 'tk-fields' ) }>
						<ToggleControl checked={ !! def.query_var } onChange={ ( v ) => update( { query_var: v } ) } />
					</TKControl>
				</div>
				<TKControl
					label={ __( 'Custom query var', 'tk-fields' ) }
					tooltip={ __( 'Override the ?query_var= name. Leave empty to use the slug.', 'tk-fields' ) }
				>
					<TextControl
						value={ def.query_var_value }
						disabled={ ! def.query_var }
						onChange={ ( v ) => update( { query_var_value: v } ) }
					/>
				</TKControl>
				<TKControl
					label={ __( 'Delete with user', 'tk-fields' ) }
					tooltip={ __( 'When a user is deleted, also delete their entries of this type. Off reassigns or leaves them.', 'tk-fields' ) }
				>
					<ToggleControl checked={ !! def.delete_with_user } onChange={ ( v ) => update( { delete_with_user: v } ) } />
				</TKControl>
			</section>

			<div className="tkf-editor__footer">
				<Button variant="secondary" onClick={ () => navigate( { name: 'list' } ) }>
					{ __( 'Cancel', 'tk-fields' ) }
				</Button>
				<Button variant="primary" isBusy={ saving } disabled={ saving } onClick={ save }>
					{ isNew
						? __( 'Create Content Type', 'tk-fields' )
						: __( 'Save Changes', 'tk-fields' ) }
				</Button>
			</div>

			{ migrateOpen && (
				<Modal
					title={ __( 'Rename slug?', 'tk-fields' ) }
					onRequestClose={ () => ( migrateBusy ? null : setMigrateOpen( false ) ) }
					className="tkf-modal"
				>
					<p>
						{ sprintf(
							/* translators: 1: old slug, 2: new slug */
							__(
								'This will move every post from “%1$s” to “%2$s” and update field locations. Old URLs will stop working — set up redirects if the site is live.',
								'tk-fields'
							),
							originalSlug,
							def.slug
						) }
					</p>
					<div className="tkf-modal__actions">
						<Button
							variant="secondary"
							onClick={ () => setMigrateOpen( false ) }
							disabled={ migrateBusy }
						>
							{ __( 'Cancel', 'tk-fields' ) }
						</Button>
						<Button
							variant="primary"
							isBusy={ migrateBusy }
							disabled={ migrateBusy }
							onClick={ doMigrateSlug }
						>
							{ __( 'Move posts', 'tk-fields' ) }
						</Button>
					</div>
				</Modal>
			) }
		</div>
	);
}
