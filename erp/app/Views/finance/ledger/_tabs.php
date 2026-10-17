<ul class="nav nav-tabs mb-3 small">
    <?php foreach (['finance/ledger' => 'Journal', 'finance/ledger/accounts' => 'Chart of accounts', 'finance/ledger/trial-balance' => 'Trial balance'] as $u => $l): ?>
    <li class="nav-item"><a class="nav-link <?= $active === $u ? 'active' : '' ?>" href="<?= site_url($u) ?>"><?= $l ?></a></li>
    <?php endforeach ?>
</ul>
