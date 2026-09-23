<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Session;
use App\Models\Account;
use App\Services\ProfitDistributionService;
use App\Services\ShareService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Calculate → approve → distribute, per spec §11. Three separate POSTs
 * rather than one, because this is the module that decides who gets paid and
 * every step deserves its own confirmation and its own audit row.
 */
final class ProfitDistributionController extends Controller
{
    public function index(): void
    {
        $today = date('Y-m-d');

        $this->view('pages/profit-distribution', [
            'title' => 'Profit Distribution',
            'nav' => 'profit-distribution',
            'pageTitle' => 'Profit Distribution',
            'pageMeta' => 'Calculate, approve, then pay out — each a separate, audited step',
            'batches' => array_map(
                static function (array $b): array {
                    $b['rows'] = ProfitDistributionService::batchRows((string) $b['batch_id']);
                    return $b;
                },
                ProfitDistributionService::batches()
            ),
            'accounts' => (new Account())->allOrdered(),
            'splitIsValid' => ShareService::isValidOn($today),
            'currentSplit' => ShareService::splitOn($today),
        ]);
    }

    public function calculate(): void
    {
        $clean = $this->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date',
        ], '/profit-distributions');

        try {
            $batchId = ProfitDistributionService::calculate(
                (string) $clean['period_start'],
                (string) $clean['period_end']
            );
        } catch (InvalidArgumentException | RuntimeException $e) {
            Session::set('_old', $_POST);
            Session::flash('error', $e->getMessage());
            Http::redirect('/profit-distributions');
        }

        Session::forget('_old');
        Logger::info('Profit distribution calculated', ['batch_id' => $batchId]);
        Session::flash('success', 'Profit calculated and split into a draft batch. Review it, then approve.');
        Http::redirect('/profit-distributions');
    }

    /** @param array<string,string> $params */
    public function approve(array $params): void
    {
        $batchId = (string) ($params['batch'] ?? '');

        try {
            ProfitDistributionService::approve($batchId);
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            Http::redirect('/profit-distributions');
        }

        Session::flash('success', 'Batch approved. It can now be distributed.');
        Http::redirect('/profit-distributions');
    }

    /** @param array<string,string> $params */
    public function distribute(array $params): void
    {
        $batchId = (string) ($params['batch'] ?? '');

        $clean = $this->validate([
            'account_id' => 'required|int',
        ], '/profit-distributions');

        try {
            ProfitDistributionService::distribute($batchId, (int) $clean['account_id']);
        } catch (InvalidArgumentException | RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            Http::redirect('/profit-distributions');
        } catch (Throwable $e) {
            Logger::error('Profit distribution payout failed', ['batch_id' => $batchId, 'message' => $e->getMessage()]);
            Session::flash('error', 'The payout could not be completed. Nothing was paid.');
            Http::redirect('/profit-distributions');
        }

        Session::flash('success', 'Distribution posted to the ledger for every partner in this batch.');
        Http::redirect('/profit-distributions');
    }
}
