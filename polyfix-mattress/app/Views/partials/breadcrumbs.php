<?php /** @var list<array{0:string,1:string}> $trail [name, path] — the last item is the current page */ ?>
<nav class="container pt-4" aria-label="Breadcrumb">
    <ol class="breadcrumb mb-0">
        <?php foreach ($trail as $i => [$name, $path]): ?>
            <?php if ($i === array_key_last($trail)): ?>
            <li class="breadcrumb-item active" aria-current="page"><?= esc($name) ?></li>
            <?php else: ?>
            <li class="breadcrumb-item"><a href="<?= site_url(ltrim($path, '/')) ?>"><?= esc($name) ?></a></li>
            <?php endif ?>
        <?php endforeach ?>
    </ol>
</nav>
