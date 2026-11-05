<?php

/**
 * Salary notices — admin only, this branch: one row per month with where
 * its notices stand.
 *
 * @var list<array<string,mixed>> $runs  PayrollRun::listWithCounts()
 * @var int                       $total
 * @var int                       $page
 * @var int                       $pages
 * @var int                       $perPage
 */

declare(strict_types=1);

use App\Services\Payroll\PayrollNoticeSender;

$pageUrl = static fn (int $target): string => url('/salary-notices') . ($target > 1 ? '?page=' . $target : '');
?>
<div class="flex flex-col gap-5">
    <div>
        <a href="<?= e(url('/salary-notices/new')) ?>" class="btn-primary">Import a month's sheet</a>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <caption class="sr-only">Salary notice runs in this branch, newest month first</caption>
                <thead>
                    <tr>
                        <th scope="col" class="th">Month</th>
                        <th scope="col" class="th text-right">Employees</th>
                        <th scope="col" class="th text-right">Confirmed</th>
                        <th scope="col" class="th text-right">Paid</th>
                        <th scope="col" class="th text-right">Query</th>
                        <th scope="col" class="th hidden text-right sm:table-cell">Awaiting</th>
                        <th scope="col" class="th hidden text-right sm:table-cell">Not sent</th>
                        <th scope="col" class="th"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($runs === []) : ?>
                        <tr>
                            <td class="td" colspan="8">
                                <p class="py-10 text-center text-sm text-slate-600">
                                    No salary notices yet. Import a month's sheet to start.
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($runs as $run) : ?>
                        <?php
                        $id = (int) $run['id'];
                        $month = PayrollNoticeSender::monthLabel((string) $run['period_month']);
                        ?>
                        <tr>
                            <td class="td">
                                <a href="<?= e(url('/salary-notices/' . $id)) ?>" class="font-medium text-ink hover:underline"><?= e($month) ?></a>
                                <p class="text-[11.5px] text-slate-500 sm:hidden">
                                    <?= e((string) (int) $run['pending_count']) ?> awaiting · <?= e((string) (int) $run['draft_count']) ?> not sent
                                </p>
                            </td>
                            <td class="td money text-right"><?= e((string) (int) $run['line_count']) ?></td>
                            <td class="td money text-right"><?= e((string) (int) $run['confirmed_count']) ?></td>
                            <td class="td money text-right"><?= e((string) (int) $run['paid_count']) ?></td>
                            <td class="td money text-right <?= (int) $run['query_count'] > 0 ? 'font-semibold text-bad-text' : '' ?>"><?= e((string) (int) $run['query_count']) ?></td>
                            <td class="td money hidden text-right sm:table-cell"><?= e((string) (int) $run['pending_count']) ?></td>
                            <td class="td money hidden text-right sm:table-cell"><?= e((string) (int) $run['draft_count']) ?></td>
                            <td class="td text-right">
                                <a href="<?= e(url('/salary-notices/' . $id)) ?>" class="btn-secondary" aria-label="<?= e('Open ' . $month) ?>">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php require APP_PATH . '/Views/partials/pagination.php'; ?>
    </div>
</div>
