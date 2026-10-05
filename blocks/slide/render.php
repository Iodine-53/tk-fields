<?php
/*
 * This file is part of TK Fields.
 *
 * TK Fields is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * See LICENSE in the plugin root for the full license text.
 */
/**
 * Server render for tk/slide.
 *
 * The slider (tk/slider) post-processes this output with WP_HTML_Tag_Processor
 * to stamp each slide's data-wp-context index. The data-wp-class--is-active
 * directive resolves against the nearest data-wp-interactive ancestor
 * (the tk/slider root) using the merged context; CSS cross-fades/slides
 * between .is-active slides (420ms).
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="tk-slide" data-wp-class--is-active="state.isActive"><?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
