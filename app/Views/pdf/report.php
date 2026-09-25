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

// A money value is always DECIMAL(15,2) end to end in this app (CLAUDE.md:
// "money is never a float"), which means every such value reaches this
// template as a string with exactly two decimal digits — "1800000.00", never
// "1800000" or "1800000.5". That shape is specific enough to reformat with a
// thousands separator without a per-report list of "which columns are
// money": anything else (dates, ids, free text) never matches it.
$formatCell = static function (mixed $cell): string {
    $value = (string) $cell;
    if (preg_match('/^-?\d+\.\d{2}$/', $value) !== 1) {
        return $value;
    }

    return number_format((float) $value, 2);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= e($title) ?></title>
<style>
    @page { margin: 30px 30px 55px 30px; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #0F172A; }
    header { border-bottom: 2px solid #0F172A; padding-bottom: 8px; margin-bottom: 16px; }
    h1 { font-size: 16px; margin: 0 0 2px; }
    p.subtitle { font-size: 10px; color: #475569; margin: 0; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border-bottom: 1px solid #E2E8F0; padding: 5px 6px; text-align: left; }
    th { background: #F8FAFC; font-size: 9px; text-transform: uppercase; letter-spacing: 0.03em; color: #475569; }
    td { font-size: 10px; }
    .empty { padding: 20px 6px; text-align: center; color: #94A3B8; }
    /* Fixed-position elements repeat on every page in dompdf — this is what
       puts the company's name on page 2+ of a long report, not just page 1.
       Page numbers are drawn separately, by App\Core\Pdf, via dompdf's
       canvas API rather than anything in this HTML/CSS. */
    footer { position: fixed; bottom: -40px; left: 0; right: 0; font-size: 8px; color: #94A3B8; }
</style>
</head>
<body>
    <header>
        <h1><?= e($companyName) ?> &middot; <?= e($title) ?></h1>
        <p class="subtitle"><?= e($subtitle) ?> &middot; generated <?= e(date('j M Y, H:i')) ?></p>
    </header>

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
                        <td><?= e($formatCell($cell)) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$any) : ?>
                <tr><td class="empty" colspan="<?= count($header) ?>">No data for this filter.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <footer><?= e($companyName) ?> &middot; generated <?= e(date('j M Y, H:i')) ?></footer>
</body>
</html>
