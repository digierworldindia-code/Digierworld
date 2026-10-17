<?php
/**
 * The camera scanner, as used by the warehouse panel and the field agent.
 *
 * It posts each code to $endpoint and shows whatever comes back, so one
 * partial serves counting units into a batch, picking units onto a dispatch
 * and checking a dealer's shelf. The page supplies the endpoint; the server
 * decides what each scan means.
 *
 * The typed-serial box is always present. It is the fallback for a torn
 * label, a refused camera or a device without one — never the normal way to
 * work, which is why it sits below the camera and not above it.
 *
 * @var string      $endpoint  URL each scan is posted to
 * @var string|null $counter   the count as it stands when the page loads
 * @var string|null $hint      one line telling the operator what to scan
 * @var bool|null   $autostart open the camera without waiting for a tap
 */
$hint ??= 'Point the camera at the QR code on the mattress label.';
?>
<div class="scanner"
     data-scanner
     data-endpoint="<?= esc($endpoint, 'attr') ?>"
     data-csrf-name="<?= esc(csrf_token(), 'attr') ?>"
     data-csrf-token="<?= esc(csrf_hash(), 'attr') ?>"
     data-jsqr="<?= base_url('assets/vendor/jsqr/jsQR.js') ?>"
     <?= ! empty($autostart) ? 'data-scanner-autostart' : '' ?>>

    <div class="scanner__view">
        <video data-scanner-video playsinline muted></video>
        <canvas data-scanner-canvas hidden></canvas>
        <div class="scanner__frame" data-scanner-frame></div>
        <div class="scanner__idle">
            <i class="bi bi-qr-code-scan" aria-hidden="true"></i>
            <span>Press <strong>Scan</strong> to open the camera</span>
        </div>
        <div class="scanner__tools">
            <button type="button" class="btn" data-scanner-torch aria-pressed="false" hidden>
                <i class="bi bi-lightbulb" aria-hidden="true"></i><span class="visually-hidden">Torch</span>
            </button>
        </div>
    </div>

    <?php if (! empty($counter)): ?>
        <div class="scanner__counter" data-scanner-counter aria-live="polite"><?= esc($counter) ?></div>
    <?php else: ?>
        <div class="scanner__counter" data-scanner-counter aria-live="polite" hidden></div>
    <?php endif ?>

    <!-- Announced, because the operator is looking at the mattress and a
         screen reader user has nothing else to go on. -->
    <div class="scanner__status scanner__status--info" data-scanner-status role="status" aria-live="assertive">
        <strong class="d-block">Ready</strong>
        <span><?= esc($hint) ?></span>
    </div>

    <ul class="scanner__log" data-scanner-log aria-label="Recent scans"></ul>

    <div class="scanner__controls">
        <button type="button" class="btn btn-primary" data-scanner-start>
            <i class="bi bi-camera me-2" aria-hidden="true"></i>Scan
        </button>
        <button type="button" class="btn btn-light" data-scanner-stop hidden>
            <i class="bi bi-stop-circle me-2" aria-hidden="true"></i>Stop
        </button>
    </div>

    <form class="scanner__manual" data-scanner-manual>
        <label class="visually-hidden" for="scanner-code">Serial number</label>
        <input class="form-control" id="scanner-code" name="code" type="text"
               inputmode="text" autocomplete="off" autocapitalize="characters"
               spellcheck="false" placeholder="<?= esc(brand('serialPrefix'), 'attr') ?>-<?= esc(local_time(utc_now(), 'Y'), 'attr') ?>-000123">
        <button class="btn btn-outline-primary" type="submit">Look up</button>
    </form>
    <p class="scanner__hint">If a label is torn or the camera will not open, type the serial here.</p>
</div>
