<?php
/**
 * mPDF running footer.
 *
 * @var string      $printedBy
 * @var string      $printedAt
 * @var string      $ref
 * @var string|null $watermark
 */
?>
<table class="pdf-footer">
    <tr>
        <td>Printed by <?= esc($printedBy) ?> on <?= esc($printedAt) ?><?= $watermark !== null ? ' · ' . esc($watermark) : '' ?></td>
        <td class="right"><?= esc($ref) ?> · Page {PAGENO} of {nbpg}</td>
    </tr>
</table>
