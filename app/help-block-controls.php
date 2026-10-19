<?php
/**
 * Shared move-up / move-down / remove controls for one Help Centre body block
 * card in the GUI editor (help-admin-article.php).
 *
 * Included inside each block's .ha-block-head. The buttons carry no state and
 * are wired up by the editor's JS (data-* hooks), so this partial is safe to
 * include for every block type.
 */
?>
<div class="ha-block-controls">
    <button type="button" class="btn btn-outline-secondary btn-sm ha-move-up" aria-label="Move block up" title="Move up">&uarr;</button>
    <button type="button" class="btn btn-outline-secondary btn-sm ha-move-down" aria-label="Move block down" title="Move down">&darr;</button>
    <button type="button" class="btn btn-outline-danger btn-sm ha-block-remove" aria-label="Remove block" title="Remove block">&times;</button>
</div>
