/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * TK Fields — classic-editor glue (vanilla JS, no dependencies).
 *
 * 1. Page-link field: toggles the post/term/url panes by the kind select,
 *    and the per-taxonomy term selects by the taxonomy select. Inputs in
 *    hidden panes are disabled so they never submit.
 * 2. Gallery field: opens the native wp.media frame, writes the ordered
 *    attachment-ID list into the hidden input, and re-renders thumbnails.
 */
(function () {
	'use strict';

	function setPaneInputsDisabled(pane, disabled) {
		var inputs = pane.querySelectorAll('select, input, textarea, button');
		for (var i = 0; i < inputs.length; i++) {
			// The kind controller lives outside the panes; the taxonomy
			// controller is handled explicitly in syncPageLinkTerms().
			if (inputs[i].hasAttribute('data-tkf-pagelink-kind')) {
				continue;
			}
			inputs[i].disabled = disabled;
		}
	}

	function syncPageLink(wrap) {
		var kindSelect = wrap.querySelector('[data-tkf-pagelink-kind]');
		if (!kindSelect) {
			return;
		}
		var kind = kindSelect.value;
		var panes = wrap.querySelectorAll('[data-tkf-pagelink-pane]');
		for (var i = 0; i < panes.length; i++) {
			var show = panes[i].getAttribute('data-tkf-pagelink-pane') === kind && kind !== '';
			panes[i].hidden = !show;
			setPaneInputsDisabled(panes[i], !show);
		}
		syncPageLinkTerms(wrap);
	}

	function syncPageLinkTerms(wrap) {
		var taxSelect = wrap.querySelector('[data-tkf-pagelink-taxonomy]');
		if (!taxSelect) {
			return;
		}
		var termWrap = wrap.querySelector('[data-tkf-pagelink-pane="term"]');
		var tax = taxSelect.value;
		var termPaneVisible = termWrap && !termWrap.hidden && tax !== '';
		var termSelects = wrap.querySelectorAll('[data-tkf-pagelink-terms]');
		for (var i = 0; i < termSelects.length; i++) {
			var show = termPaneVisible && termSelects[i].getAttribute('data-tkf-pagelink-terms') === tax;
			termSelects[i].hidden = !show;
			var sel = termSelects[i].querySelector('select');
			if (sel) {
				sel.disabled = !show;
			}
		}
	}

	function initPageLinks() {
		var wraps = document.querySelectorAll('.tkf-pagelink');
		for (var i = 0; i < wraps.length; i++) {
			(function (wrap) {
				var kindSelect = wrap.querySelector('[data-tkf-pagelink-kind]');
				var taxSelect = wrap.querySelector('[data-tkf-pagelink-taxonomy]');
				if (kindSelect) {
					kindSelect.addEventListener('change', function () { syncPageLink(wrap); });
				}
				if (taxSelect) {
					taxSelect.addEventListener('change', function () { syncPageLinkTerms(wrap); });
				}
				syncPageLink(wrap);
			})(wraps[i]);
		}
	}

	function setGalleryIds(wrap, ids) {
		var holder = wrap.querySelector('[data-tkf-gallery-inputs]');
		if (!holder) {
			return;
		}
		var name = holder.getAttribute('data-name');
		holder.innerHTML = '';
		// Empty marker first: guarantees the key posts even when every
		// image is removed, so clearing the gallery clears the value.
		var empty = document.createElement('input');
		empty.type = 'hidden';
		empty.name = name;
		empty.value = '';
		holder.appendChild(empty);
		ids.forEach(function (id) {
			var inp = document.createElement('input');
			inp.type = 'hidden';
			inp.name = name;
			inp.value = String(id);
			holder.appendChild(inp);
		});
		renderThumbs(wrap, ids);
	}

	function renderThumbs(wrap, ids) {
		var list = wrap.querySelector('[data-tkf-gallery-thumbs]');
		if (!list) {
			return;
		}
		list.innerHTML = '';
		ids.forEach(function (id) {
			var att = window.wp && window.wp.media && window.wp.media.attachment
				? window.wp.media.attachment(id)
				: null;
			var li = document.createElement('li');
			li.setAttribute('data-tkf-gallery-thumb', String(id));
			if (att) {
				att.fetch().then(function () {
					var url = att.get('sizes') && att.get('sizes').thumbnail
						? att.get('sizes').thumbnail.url
						: att.get('url');
					var img = document.createElement('img');
					img.src = url;
					img.alt = '';
					li.appendChild(img);
				});
			} else {
				li.textContent = '#' + id;
			}
			list.appendChild(li);
		});
	}

	function initGalleries() {
		if (!window.wp || !window.wp.media) {
			return;
		}
		var wraps = document.querySelectorAll('[data-tkf-gallery]');
		for (var i = 0; i < wraps.length; i++) {
			(function (wrap) {
				var selectBtn = wrap.querySelector('[data-tkf-gallery-select]');
				var clearBtn = wrap.querySelector('[data-tkf-gallery-clear]');
				var mime = wrap.getAttribute('data-mime') || 'image';
				var title = wrap.getAttribute('data-title') || 'Select images';
				var frame = null;

				function currentIds() {
					var holder = wrap.querySelector('[data-tkf-gallery-inputs]');
					var ids = [];
					if (!holder) {
						return ids;
					}
					var inputs = holder.querySelectorAll('input[type="hidden"]');
					for (var i = 0; i < inputs.length; i++) {
						var n = parseInt(inputs[i].value, 10);
						if (n > 0) {
							ids.push(n);
						}
					}
					return ids;
				}

				if (selectBtn) {
					selectBtn.addEventListener('click', function (e) {
						e.preventDefault();
						if (!frame) {
							frame = window.wp.media({
								title: title,
								multiple: true,
								library: { type: mime.split(',').map(function (s) { return s.trim(); }).filter(Boolean) }
							});
							frame.on('select', function () {
								var ids = frame.state().get('selection').map(function (att) { return att.id; });
								setGalleryIds(wrap, ids);
							});
						}
						var preselect = currentIds();
						if (preselect.length) {
							var selection = frame.state().get('selection');
							selection.reset(preselect.map(function (id) { return window.wp.media.attachment(id); }));
						}
						frame.open();
					});
				}

				if (clearBtn) {
					clearBtn.addEventListener('click', function (e) {
						e.preventDefault();
						setGalleryIds(wrap, []);
					});
				}
			})(wraps[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			initPageLinks();
			initGalleries();
		});
	} else {
		initPageLinks();
		initGalleries();
	}
})();
