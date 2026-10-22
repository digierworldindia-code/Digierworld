<?php
/** @var array|null $jd current version to pre-fill, @var array $rubricDefaults */
$dec = static fn ($k) => implode("\n", json_decode((string) ($jd[$k] ?? '[]'), true) ?: []);
$rub = json_decode((string) ($jd['rubric'] ?? ''), true) ?: $rubricDefaults;
$o   = static fn ($k, $d = '') => esc((string) (old($k) ?? $d));
?>
<div class="row g-3">
    <div class="col-12"><label class="form-label required" for="summary">Role summary</label><textarea class="form-control<?= is_invalid('summary') ?>" id="summary" name="summary" rows="3" required><?= $o('summary', $jd['summary'] ?? '') ?></textarea><?= field_error('summary') ?></div>
    <div class="col-md-6"><label class="form-label" for="responsibilities">Responsibilities</label><textarea class="form-control" id="responsibilities" name="responsibilities" rows="5"><?= $o('responsibilities', $jd['responsibilities'] ?? '') ?></textarea></div>
    <div class="col-md-6"><label class="form-label" for="skills">Skills (one per line)</label><textarea class="form-control" id="skills" name="skills" rows="5" placeholder="Google Ads&#10;Meta Ads Manager&#10;GA4"><?= $o('skills', $dec('skills')) ?></textarea></div>
    <div class="col-md-6"><label class="form-label required" for="must_have">Must-have requirements (one per line)</label><textarea class="form-control<?= is_invalid('must_have') ?>" id="must_have" name="must_have" rows="5" required placeholder="2+ years running paid campaigns for clients"><?= $o('must_have', $dec('must_have')) ?></textarea><?= field_error('must_have') ?></div>
    <div class="col-md-6"><label class="form-label" for="nice_to_have">Nice-to-have (one per line)</label><textarea class="form-control" id="nice_to_have" name="nice_to_have" rows="5"><?= $o('nice_to_have', $dec('nice_to_have')) ?></textarea></div>
    <div class="col-12"><label class="form-label" for="qualifications">Role-specific qualifications</label><input class="form-control" id="qualifications" name="qualifications" value="<?= $o('qualifications', $jd['qualifications'] ?? '') ?>" placeholder="e.g. Google Ads certification preferred"></div>
    <div class="col-12"><span class="form-label d-block">Evaluation rubric (weights must total 100) <?= field_error('rubric') ?></span>
        <div class="row g-2"><?php foreach ($rubricDefaults as $k => $r): ?><div class="col-12 col-sm-6 col-xl"><div class="input-group input-group-sm"><span class="input-group-text small text-wrap text-start"><?= esc($r['label']) ?></span><input class="form-control text-end" name="rubric[<?= $k ?>]" value="<?= (int) ((old('rubric') ?? [])[$k] ?? $rub[$k]['weight'] ?? $r['weight']) ?>" inputmode="numeric" aria-label="<?= esc($r['label']) ?> weight"><span class="input-group-text">%</span></div></div><?php endforeach ?></div>
        <div class="form-text">Used for human screening and, only if enabled, AI decision support. Age, gender, religion, caste, marital status, disability, photographs and similar traits are never criteria.</div></div>
</div>
