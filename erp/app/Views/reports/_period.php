<form class="filter-bar row g-2" method="get">
    <div class="col-md-3"><select name="period" class="form-select form-select-sm" aria-label="Period" data-autosubmit>
        <?php foreach (['this_month' => 'This month', 'last_month' => 'Last month', 'this_quarter' => 'This quarter', 'last_quarter' => 'Last quarter', 'this_fy' => 'This financial year', 'last_fy' => 'Last financial year', 'custom' => 'Custom dates'] as $k => $l): ?>
        <option value="<?= $k ?>" <?= $preset === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?>
    </select></div>
    <div class="col-6 col-md-2"><input type="date" name="from" value="<?= esc($from) ?>" class="form-control form-control-sm" aria-label="From" <?= $preset === 'custom' ? '' : 'readonly' ?>></div>
    <div class="col-6 col-md-2"><input type="date" name="to" value="<?= esc($to) ?>" class="form-control form-control-sm" aria-label="To" <?= $preset === 'custom' ? '' : 'readonly' ?>></div>
    <div class="col-md-2"><button class="btn btn-primary btn-sm w-100" name="period" value="custom">Apply dates</button></div>
    <div class="col-md-3 small text-muted align-self-center text-md-end"><?= dmy($from) ?> – <?= dmy($to) ?></div>
</form>
