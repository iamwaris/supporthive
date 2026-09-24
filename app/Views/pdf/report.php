<?php

/**
 * Shared PDF layout for every report export. Plain HTML/CSS only — dompdf's
 * CSS support does not cover the compiled Tailwind build, so this is a
 * separate, deliberately simple stylesheet rather than the app's own.
 *
 * @var string       $title
 * @var string        $subtitle
 * @var list<string>  $header
 * @var iterable<list<int|float|string|null>> $rows
 * @var string        $companyName
 */

declare(strict_types=1);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= e($title) ?></title>
<style>
    body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #0F172A; }
    h1 { font-size: 16px; margin: 0 0 2px; }
    p.subtitle { font-size: 10px; color: #475569; margin: 0 0 16px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border-bottom: 1px solid #E2E8F0; padding: 5px 6px; text-align: left; }
    th { background: #F8FAFC; font-size: 9px; text-transform: uppercase; letter-spacing: 0.03em; color: #475569; }
    td { font-size: 10px; }
    .empty { padding: 20px 6px; text-align: center; color: #94A3B8; }
    footer { position: fixed; bottom: -20px; left: 0; right: 0; font-size: 9px; color: #94A3B8; text-align: center; }
</style>
</head>
<body>
    <h1><?= e($companyName) ?> &middot; <?= e($title) ?></h1>
    <p class="subtitle"><?= e($subtitle) ?> &middot; generated <?= e(date('j M Y, H:i')) ?></p>

    <table>
        <thead>
            <tr>
                <?php foreach ($header as $column) : ?>
                    <th><?= e($column) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php $any = false; ?>
            <?php foreach ($rows as $row) : ?>
                <?php $any = true; ?>
                <tr>
                    <?php foreach ($row as $cell) : ?>
                        <td><?= e((string) $cell) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$any) : ?>
                <tr><td class="empty" colspan="<?= count($header) ?>">No data for this filter.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
