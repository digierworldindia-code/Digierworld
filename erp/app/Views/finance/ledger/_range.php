<form class="filter-bar row g-2" method="get">
    <?php foreach ($keep ?? [] as $k => $val): ?><input type="hidden" name="<?= esc($k) ?>" value="<?= esc((string) $val) ?>"><?php endforeach ?>
    <div class="col-6 col-md-2"><input type="date" name="from" value="<?= esc($from) ?>" class="form-control form-control-sm" aria-label="From"></div>
    <div class="col-6 col-md-2"><input type="date" name="to" value="<?= esc($to) ?>" class="form-control form-control-sm" aria-label="To"></div>
    <?= $extra ?? '' ?>
    <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Show</button></div>
</form>
