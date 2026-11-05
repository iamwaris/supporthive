<?php

/**
 * A one-message public page: the thank-you after a submission, and the
 * neutral "no longer active" page for every link that does not open the form.
 *
 * @var string $heading
 * @var string $text
 */

declare(strict_types=1);

?>
<section class="card p-6 text-center sm:p-8" aria-labelledby="join-message-heading">
    <h1 id="join-message-heading" class="font-display text-xl font-semibold tracking-tight text-ink"><?= e($heading) ?></h1>
    <p class="mt-3 text-sm text-slate-600"><?= e($text) ?></p>
</section>
