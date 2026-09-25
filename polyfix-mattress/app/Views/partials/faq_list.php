<?php
/**
 * Questions and answers as native disclosure elements — keyboard-accessible
 * and readable without JavaScript.
 *
 * @var list<array{question:string, answer:string}> $faqs
 * @var string $heading
 */
if ($faqs === []) {
    return;
}
?>
<section class="section-tight">
    <div class="container container-narrow">
        <h2 class="mb-4"><?= esc($heading ?? 'Common questions') ?></h2>
        <?php foreach ($faqs as $faq): ?>
        <details class="faq-item">
            <summary><?= esc($faq['question']) ?></summary>
            <div class="faq-item__body"><p class="mb-0"><?= nl2br(esc($faq['answer'])) ?></p></div>
        </details>
        <?php endforeach ?>
    </div>
</section>
