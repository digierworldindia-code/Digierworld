<?php
/**
 * Page footer. Set before including: $page_scripts (list of JS files in assets/js/).
 */
defined('QMS') || exit;
?>
        </div>
    </main>
</div>

<script src="<?= asset('bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script type="module" src="<?= asset('js/app.js') ?>"></script>
<?php foreach ($page_scripts ?? [] as $qmsScript): ?>
<script type="module" src="<?= asset('js/' . $qmsScript) ?>"></script>
<?php endforeach ?>
</body>
</html>
