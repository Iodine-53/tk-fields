/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * Editor UI for tk/post-listing — the Gutenberg twin of the Elementor
 * TK Post Listing widget. Every inspector control carries a <Tooltip>
 * (non-negotiable). The canvas preview is a live ServerSideRender of
 * render.php, so what you see is the real frontend markup.
 */
( function () {
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var RangeControl = wp.components.RangeControl;
	var ColorPalette = wp.components.ColorPalette;
	var Button = wp.components.Button;
	var Tooltip = wp.components.Tooltip;
	var ServerSideRender = wp.serverSideRender;
	var __ = wp.i18n.__;

	var DATA = window.TK_POST_LISTING_DATA || { postTypes: [], fields: [] };

	// Every inspector control gets a <Tooltip> (non-negotiable). The control
	// sits inside a plain div so Tooltip's cloned handlers land on DOM, not
	// on the component.
	function withTip( tip, control ) {
		return el(
			Tooltip,
			{ text: tip },
			el( 'div', { className: 'tkf-tip-wrap' }, control )
		);
	}

	function orderbyOptions() {
		return [
			{ label: __( 'Date', 'tk-fields' ), value: 'date' },
			{ label: __( 'Last Modified', 'tk-fields' ), value: 'modified' },
			{ label: __( 'Title (A–Z)', 'tk-fields' ), value: 'title' },
			{ label: __( 'Slug', 'tk-fields' ), value: 'name' },
			{ label: __( 'Menu Order', 'tk-fields' ), value: 'menu_order' },
			{ label: __( 'Comment Count', 'tk-fields' ), value: 'comment_count' },
			{ label: __( 'Random', 'tk-fields' ), value: 'rand' },
		];
	}

	function ratioOptions() {
		return [
			{ label: __( '16:9 (widescreen)', 'tk-fields' ), value: '16/9' },
			{ label: __( '4:3', 'tk-fields' ), value: '4/3' },
			{ label: __( '3:2', 'tk-fields' ), value: '3/2' },
			{ label: __( '1:1 (square)', 'tk-fields' ), value: '1/1' },
			{ label: __( 'Natural (no crop)', 'tk-fields' ), value: 'auto' },
		];
	}

	function fieldOptions() {
		var opts = ( DATA.fields || [] ).map( function ( f ) {
			return { label: f.label, value: f.value };
		} );
		opts.unshift( { label: __( '— Choose a field —', 'tk-fields' ), value: '' } );
		return opts;
	}

	function postTypeOptions() {
		var opts = ( DATA.postTypes || [] ).map( function ( t ) {
			return { label: t.label, value: t.value };
		} );
		if ( ! opts.length ) {
			opts = [ { label: __( 'Posts (post)', 'tk-fields' ), value: 'post' } ];
		}
		return opts;
	}

	registerBlockType( 'tk/post-listing', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			// apiVersion 3 contract: the editor renders NO wrapper of its
			// own — the edit component must spread useBlockProps() onto
			// its root element, otherwise the block gets no data-block
			// chrome and canvas clicks can't select it.
			var blockProps = useBlockProps();

			function setFieldRow( index, patch ) {
				var rows = ( attributes.cardFields || [] ).slice();
				rows[ index ] = Object.assign( {}, rows[ index ], patch );
				setAttributes( { cardFields: rows } );
			}

			function removeFieldRow( index ) {
				var rows = ( attributes.cardFields || [] ).slice();
				rows.splice( index, 1 );
				setAttributes( { cardFields: rows } );
			}

			function addFieldRow() {
				var rows = ( attributes.cardFields || [] ).slice();
				rows.push( { fieldKey: '', fieldLabel: '' } );
				setAttributes( { cardFields: rows } );
			}

			var fieldRows = ( attributes.cardFields || [] ).map( function ( row, i ) {
				return el(
					'div',
					{ key: 'tkf-pl-row-' + i, className: 'tkf-pl-field-row' },
					withTip(
						__( 'The TK field to show on each card. Values come from each listed post — posts with nothing stored for this field skip the row.', 'tk-fields' ),
						el( SelectControl, {
							label: __( 'Field', 'tk-fields' ),
							value: row.fieldKey || '',
							options: fieldOptions(),
							onChange: function ( v ) { setFieldRow( i, { fieldKey: v } ); },
						} )
					),
					withTip(
						__( 'Optional label shown before the value. Leave empty to use the field’s own label.', 'tk-fields' ),
						el( TextControl, {
							label: __( 'Custom label', 'tk-fields' ),
							value: row.fieldLabel || '',
							placeholder: __( 'Field’s own label', 'tk-fields' ),
							onChange: function ( v ) { setFieldRow( i, { fieldLabel: v } ); },
						} )
					),
					el(
						Button,
						{ isDestructive: true, variant: 'link', onClick: function () { removeFieldRow( i ); } },
						__( 'Remove', 'tk-fields' )
					)
				);
			} );

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					{ key: 'tk-post-listing-inspector' },
					el(
						PanelBody,
						{ title: __( 'Query', 'tk-fields' ), initialOpen: true },
						withTip(
							__( 'Which content to list. TK Content Types you build in TK Fields appear here alongside posts and pages.', 'tk-fields' ),
							el( SelectControl, {
								label: __( 'Post Type', 'tk-fields' ),
								value: attributes.postType || 'post',
								options: postTypeOptions(),
								onChange: function ( v ) { setAttributes( { postType: v } ); },
							} )
						),
						withTip(
							__( 'How many cards show at once. With pagination on, this is the page size.', 'tk-fields' ),
							el( RangeControl, {
								label: __( 'Posts per page', 'tk-fields' ),
								value: attributes.postsPerPage == null ? 6 : attributes.postsPerPage,
								min: 1,
								max: 24,
								onChange: function ( v ) { setAttributes( { postsPerPage: v == null ? 6 : v } ); },
							} )
						),
						withTip(
							__( 'What the listing sorts by. Random is re-shuffled on every page load.', 'tk-fields' ),
							el( SelectControl, {
								label: __( 'Order by', 'tk-fields' ),
								value: attributes.orderby || 'date',
								options: orderbyOptions(),
								onChange: function ( v ) { setAttributes( { orderby: v } ); },
							} )
						),
						withTip(
							__( 'Sort direction. Has no effect when Order By is Random.', 'tk-fields' ),
							el( SelectControl, {
								label: __( 'Order', 'tk-fields' ),
								value: attributes.order || 'DESC',
								options: [
									{ label: __( 'Descending (newest first)', 'tk-fields' ), value: 'DESC' },
									{ label: __( 'Ascending (oldest first)', 'tk-fields' ), value: 'ASC' },
								],
								onChange: function ( v ) { setAttributes( { order: v } ); },
							} )
						),
						withTip(
							__( 'Skip this many posts from the start — e.g. 3 skips the posts already shown in a hero block above.', 'tk-fields' ),
							el( RangeControl, {
								label: __( 'Offset', 'tk-fields' ),
								value: attributes.offset == null ? 0 : attributes.offset,
								min: 0,
								max: 24,
								onChange: function ( v ) { setAttributes( { offset: v == null ? 0 : v } ); },
							} )
						),
						withTip(
							__( 'Leave the post this page is about out of the listing. Useful on single-post templates so the page never lists itself.', 'tk-fields' ),
							el( ToggleControl, {
								label: __( 'Exclude current post', 'tk-fields' ),
								checked: !! attributes.excludeCurrent,
								onChange: function ( v ) { setAttributes( { excludeCurrent: v } ); },
							} )
						)
					),
					el(
						PanelBody,
						{ title: __( 'Card', 'tk-fields' ), initialOpen: true },
						withTip(
							__( 'Grid shows cards in columns; List stacks horizontal cards with the image on the left.', 'tk-fields' ),
							el( SelectControl, {
								label: __( 'Layout', 'tk-fields' ),
								value: attributes.layout || 'grid',
								options: [
									{ label: __( 'Grid', 'tk-fields' ), value: 'grid' },
									{ label: __( 'List', 'tk-fields' ), value: 'list' },
								],
								onChange: function ( v ) { setAttributes( { layout: v } ); },
							} )
						),
						withTip(
							__( 'How many cards sit side by side on desktop screens.', 'tk-fields' ),
							el( RangeControl, {
								label: __( 'Columns (desktop)', 'tk-fields' ),
								value: attributes.columns == null ? 3 : attributes.columns,
								min: 1,
								max: 6,
								onChange: function ( v ) { setAttributes( { columns: v == null ? 3 : v } ); },
							} )
						),
						withTip(
							__( 'Columns on tablet-size screens.', 'tk-fields' ),
							el( RangeControl, {
								label: __( 'Columns (tablet)', 'tk-fields' ),
								value: attributes.columnsTablet == null ? 2 : attributes.columnsTablet,
								min: 1,
								max: 6,
								onChange: function ( v ) { setAttributes( { columnsTablet: v == null ? 2 : v } ); },
							} )
						),
						withTip(
							__( 'Columns on phones.', 'tk-fields' ),
							el( RangeControl, {
								label: __( 'Columns (mobile)', 'tk-fields' ),
								value: attributes.columnsMobile == null ? 1 : attributes.columnsMobile,
								min: 1,
								max: 6,
								onChange: function ( v ) { setAttributes( { columnsMobile: v == null ? 1 : v } ); },
							} )
						),
						withTip(
							__( 'Make every card in a row the same height, with the Read More link pinned to the bottom — so all Read More links sit at one level no matter how long each excerpt is.', 'tk-fields' ),
							el( ToggleControl, {
								label: __( 'Equalize card heights', 'tk-fields' ),
								checked: attributes.equalizeHeights !== false,
								onChange: function ( v ) { setAttributes( { equalizeHeights: v } ); },
							} )
						),
						withTip(
							__( 'Show the featured image at the top of each card. Cards without a featured image simply skip the image area.', 'tk-fields' ),
							el( ToggleControl, {
								label: __( 'Show image', 'tk-fields' ),
								checked: attributes.showImage !== false,
								onChange: function ( v ) { setAttributes( { showImage: v } ); },
							} )
						),
						withTip(
							__( 'The image box keeps this shape and crops to fill it. Natural shows the full image uncropped.', 'tk-fields' ),
							el( SelectControl, {
								label: __( 'Image aspect ratio', 'tk-fields' ),
								value: attributes.imageRatio || '16/9',
								options: ratioOptions(),
								onChange: function ( v ) { setAttributes( { imageRatio: v } ); },
							} )
						),
						withTip(
							__( 'Show the post title, linked to the post.', 'tk-fields' ),
							el( ToggleControl, {
								label: __( 'Show title', 'tk-fields' ),
								checked: attributes.showTitle !== false,
								onChange: function ( v ) { setAttributes( { showTitle: v } ); },
							} )
						),
						withTip(
							__( 'Show the publish date in the small meta row under the title.', 'tk-fields' ),
							el( ToggleControl, {
								label: __( 'Show date', 'tk-fields' ),
								checked: attributes.showMetaDate !== false,
								onChange: function ( v ) { setAttributes( { showMetaDate: v } ); },
							} )
						),
						withTip(
							__( 'Show the post’s categories/tags (from its public taxonomies) in the meta row, after the date.', 'tk-fields' ),
							el( ToggleControl, {
								label: __( 'Show terms', 'tk-fields' ),
								checked: attributes.showMetaTerms !== false,
								onChange: function ( v ) { setAttributes( { showMetaTerms: v } ); },
							} )
						),
						withTip(
							__( 'Show a short excerpt under the meta row. The line count comes from Excerpt Lines below.', 'tk-fields' ),
							el( ToggleControl, {
								label: __( 'Show excerpt', 'tk-fields' ),
								checked: attributes.showExcerpt !== false,
								onChange: function ( v ) { setAttributes( { showExcerpt: v } ); },
							} )
						),
						withTip(
							__( 'How many lines the excerpt may take — longer text is cut with an ellipsis so excerpts occupy uniform space. Set to 0 to hide the excerpt.', 'tk-fields' ),
							el( RangeControl, {
								label: __( 'Excerpt lines', 'tk-fields' ),
								value: attributes.excerptLines == null ? 3 : attributes.excerptLines,
								min: 0,
								max: 10,
								onChange: function ( v ) { setAttributes( { excerptLines: v == null ? 3 : v } ); },
							} )
						),
						withTip(
							__( 'Word count used when a post has no manual excerpt. Manual excerpts are always shown as written.', 'tk-fields' ),
							el( RangeControl, {
								label: __( 'Excerpt length (words)', 'tk-fields' ),
								value: attributes.excerptLength == null ? 20 : attributes.excerptLength,
								min: 5,
								max: 100,
								onChange: function ( v ) { setAttributes( { excerptLength: v == null ? 20 : v } ); },
							} )
						),
						withTip(
							__( 'Show the Read More link under each card.', 'tk-fields' ),
							el( ToggleControl, {
								label: __( 'Show Read More', 'tk-fields' ),
								checked: attributes.showReadMore !== false,
								onChange: function ( v ) { setAttributes( { showReadMore: v } ); },
							} )
						),
						withTip(
							__( 'Link text under each card. Leave empty for the default “Read more”.', 'tk-fields' ),
							el( TextControl, {
								label: __( 'Read More text', 'tk-fields' ),
								value: attributes.readMoreText || '',
								placeholder: __( 'Read more', 'tk-fields' ),
								onChange: function ( v ) { setAttributes( { readMoreText: v } ); },
							} )
						)
					),
					el(
						PanelBody,
						{ title: __( 'TK Fields', 'tk-fields' ), initialOpen: false },
						el(
							'p',
							{ className: 'tkf-pl-help' },
							__( 'Extra rows on each card — e.g. a “Price” or “Rating” field. Rows render only when the post actually has a value.', 'tk-fields' )
						),
						fieldRows,
						el(
							Button,
							{ variant: 'secondary', onClick: addFieldRow, className: 'tkf-pl-add' },
							__( 'Add field', 'tk-fields' )
						)
					),
					el(
						PanelBody,
						{ title: __( 'Pagination & Empty State', 'tk-fields' ), initialOpen: false },
						withTip(
							__( 'Show numbered prev/next links under the grid on the live page. Page 1 always shows inside the editor preview.', 'tk-fields' ),
							el( ToggleControl, {
								label: __( 'Numbered pagination', 'tk-fields' ),
								checked: !! attributes.showPagination,
								onChange: function ( v ) { setAttributes( { showPagination: v } ); },
							} )
						),
						withTip(
							__( 'Shown in a styled notice when the query returns nothing. Leave empty for the default message.', 'tk-fields' ),
							el( TextControl, {
								label: __( 'Empty message', 'tk-fields' ),
								value: attributes.emptyText || '',
								placeholder: __( 'No posts found — try adjusting filters.', 'tk-fields' ),
								onChange: function ( v ) { setAttributes( { emptyText: v } ); },
							} )
						)
					),
					el(
						PanelBody,
						{ title: __( 'Style', 'tk-fields' ), initialOpen: false },
						withTip(
							__( 'Card background. Empty keeps the plain designed surface.', 'tk-fields' ),
							el( 'div', { className: 'tkf-pl-color' },
								el( 'span', { className: 'tkf-pl-color-label' }, __( 'Card background', 'tk-fields' ) ),
								el( ColorPalette, {
									value: attributes.cardBackground || undefined,
									onChange: function ( v ) { setAttributes( { cardBackground: v || '' } ); },
								} )
							)
						),
						withTip(
							__( 'Corner rounding for the card (the image follows the top corners).', 'tk-fields' ),
							el( RangeControl, {
								label: __( 'Card corner radius', 'tk-fields' ),
								value: attributes.cardRadius == null ? 16 : attributes.cardRadius,
								min: 0,
								max: 32,
								onChange: function ( v ) { setAttributes( { cardRadius: v == null ? 16 : v } ); },
							} )
						),
						withTip(
							__( 'Inner spacing of the card body. The image stays full-bleed at the top.', 'tk-fields' ),
							el( RangeControl, {
								label: __( 'Card body padding', 'tk-fields' ),
								value: attributes.cardPadding == null ? 20 : attributes.cardPadding,
								min: 0,
								max: 48,
								onChange: function ( v ) { setAttributes( { cardPadding: v == null ? 20 : v } ); },
							} )
						),
						withTip(
							__( 'Title color. Empty inherits the theme text color.', 'tk-fields' ),
							el( 'div', { className: 'tkf-pl-color' },
								el( 'span', { className: 'tkf-pl-color-label' }, __( 'Title color', 'tk-fields' ) ),
								el( ColorPalette, {
									value: attributes.titleColor || undefined,
									onChange: function ( v ) { setAttributes( { titleColor: v || '' } ); },
								} )
							)
						),
						withTip(
							__( 'Meta row color. Empty keeps the default muted treatment.', 'tk-fields' ),
							el( 'div', { className: 'tkf-pl-color' },
								el( 'span', { className: 'tkf-pl-color-label' }, __( 'Meta color', 'tk-fields' ) ),
								el( ColorPalette, {
									value: attributes.metaColor || undefined,
									onChange: function ( v ) { setAttributes( { metaColor: v || '' } ); },
								} )
							)
						),
						withTip(
							__( 'Excerpt color. Empty inherits the theme text color.', 'tk-fields' ),
							el( 'div', { className: 'tkf-pl-color' },
								el( 'span', { className: 'tkf-pl-color-label' }, __( 'Excerpt color', 'tk-fields' ) ),
								el( ColorPalette, {
									value: attributes.excerptColor || undefined,
									onChange: function ( v ) { setAttributes( { excerptColor: v || '' } ); },
								} )
							)
						),
						withTip(
							__( 'Read-more link color. Empty uses the TK accent blue.', 'tk-fields' ),
							el( 'div', { className: 'tkf-pl-color' },
								el( 'span', { className: 'tkf-pl-color-label' }, __( 'Read More color', 'tk-fields' ) ),
								el( ColorPalette, {
									value: attributes.moreColor || undefined,
									onChange: function ( v ) { setAttributes( { moreColor: v || '' } ); },
								} )
							)
						),
						withTip(
							__( 'Read-more link color on hover.', 'tk-fields' ),
							el( 'div', { className: 'tkf-pl-color' },
								el( 'span', { className: 'tkf-pl-color-label' }, __( 'Read More hover color', 'tk-fields' ) ),
								el( ColorPalette, {
									value: attributes.moreHoverColor || undefined,
									onChange: function ( v ) { setAttributes( { moreHoverColor: v || '' } ); },
								} )
							)
						)
					)
				),
				el(
					'div',
					blockProps,
					el(
						'div',
						{ key: 'tk-post-listing-preview', className: 'tk-post-listing-editor' },
						el( ServerSideRender, {
							block: 'tk/post-listing',
							attributes: attributes,
							EmptyResponsePlaceholder: function () {
								return el( 'p', null, __( 'No posts found.', 'tk-fields' ) );
							},
						} )
					),
				),
			);
		},
		save: function () {
			return null;
		},
	} );
} )();
