<?php /** @var array $iv @var string $back */ $crit = ['Role knowledge', 'Communication', 'Problem solving', 'Culture & teamwork']; ?>
<form method="post" action="<?= site_url('hr/interviews/' . $iv['id'] . '/complete') ?>" enctype="multipart/form-data" class="small mt-2"><?= csrf_field() ?><input type="hidden" name="back" value="<?= esc($back) ?>">
    <div class="row g-2">
        <div class="col-md-4"><select class="form-select form-select-sm" name="status" aria-label="Outcome"><option value="completed">Held</option><option value="no_show">No-show</option><option value="cancelled">Cancelled</option></select></div>
        <div class="col-md-4"><select class="form-select form-select-sm" name="result" aria-label="Result"><option value="pending">Result pending</option><option value="pass">Pass / move ahead</option><option value="hold">Hold</option><option value="fail">Not suitable</option></select></div>
        <div class="col-md-4"><select class="form-select form-select-sm" name="overall_rating" aria-label="Overall rating"><option value="">Overall rating…</option><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= $i ?> / 5</option><?php endfor ?></select></div>
    </div>
    <table class="table table-sm my-2"><thead><tr><th>Criterion</th><th>Rating (1–5)</th><th>Comment</th></tr></thead><tbody>
    <?php foreach ($crit as $n => $cr): ?><tr><td><input class="form-control form-control-sm" name="scorecards[<?= $n ?>][criterion]" value="<?= $cr ?>" aria-label="Criterion"></td><td><select class="form-select form-select-sm" name="scorecards[<?= $n ?>][rating]" aria-label="Rating"><option value="">—</option><?php for ($i = 1; $i <= 5; $i++): ?><option><?= $i ?></option><?php endfor ?></select></td><td><input class="form-control form-control-sm" name="scorecards[<?= $n ?>][comments]" aria-label="Comment"></td></tr><?php endforeach ?>
    </tbody></table>
    <textarea class="form-control form-control-sm mb-2" name="feedback" rows="2" placeholder="Feedback — what you actually observed" aria-label="Feedback"></textarea>
    <div class="input-group input-group-sm"><input type="file" class="form-control" name="attachment" accept=".pdf,.docx,.png,.jpg,.jpeg" aria-label="GD notes or assignment"><button class="btn btn-primary" type="submit">Save outcome</button></div>
    <div class="form-text">Attach GD notes or the submitted assignment only if one was actually conducted.</div>
</form>
