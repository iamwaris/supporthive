<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Session;
use App\Models\Partner;
use App\Services\ShareService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PartnerController extends Controller
{
    public function index(): void
    {
        $partners = new Partner();
        $today = date('Y-m-d');

        $this->view('pages/partners', [
            'title' => 'Partners & Profit',
            'nav' => 'partners',
            'pageTitle' => 'Partners & Profit',
            'pageMeta' => 'Ownership is used for profit allocation only — never to split expenses',
            'partners' => $partners->allOrdered(),
            'activePartners' => $partners->active(),
            'currentSplit' => ShareService::splitOn($today),
            'currentTotalBp' => ShareService::totalBpOn($today),
            'splitIsValid' => ShareService::isValidOn($today),
            'history' => ShareService::history(),
        ]);
    }

    public function store(): void
    {
        $clean = $this->validate([
            'name' => 'required|max:120',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|max:40',
            'join_date' => 'required|date',
            'notes' => 'nullable|max:2000',
        ], '/partners');

        $partners = new Partner();

        if ($partners->nameExists((string) $clean['name'])) {
            Session::set('_old', $_POST);
            Session::flash('errors', ['name' => ['A partner with that name already exists.']]);
            Session::flash('error', 'That partner already exists.');
            Http::redirect('/partners');
        }

        $id = $partners->createFor([
            'name' => $clean['name'],
            'email' => $clean['email'],
            'phone' => $clean['phone'],
            'join_date' => date('Y-m-d', (int) strtotime((string) $clean['join_date'])),
            'status' => 'active',
            'notes' => $clean['notes'],
        ]);

        Logger::info('Partner created', ['partner_id' => $id]);
        Session::flash('success', $clean['name'] . ' added. Ownership shares are set separately.');
        Http::redirect('/partners');
    }

    /** @param array<string,string> $params */
    public function update(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $partners = new Partner();

        if ($partners->find($id) === null) {
            Http::abort(404);
        }

        $clean = $this->validate([
            'name' => 'required|max:120',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|max:40',
            'join_date' => 'required|date',
            'status' => 'required|in:active,inactive',
            'notes' => 'nullable|max:2000',
        ], '/partners');

        if ($partners->nameExists((string) $clean['name'], $id)) {
            Session::flash('error', 'Another partner already uses that name.');
            Http::redirect('/partners');
        }

        // Deactivating a partner who holds a current share would leave the
        // company owning less than 100% of itself, so it is refused until a
        // new split is activated without them.
        if ($clean['status'] === 'inactive' && array_key_exists($id, ShareService::currentSplit())) {
            Session::flash(
                'error',
                'That partner still holds a share in the current split. Activate a new split without them first.'
            );
            Http::redirect('/partners');
        }

        $partners->updateById($id, [
            'name' => $clean['name'],
            'email' => $clean['email'],
            'phone' => $clean['phone'],
            'join_date' => date('Y-m-d', (int) strtotime((string) $clean['join_date'])),
            'status' => $clean['status'],
            'notes' => $clean['notes'],
        ]);

        Logger::info('Partner updated', ['partner_id' => $id]);
        Session::flash('success', 'Partner updated.');
        Http::redirect('/partners');
    }

    /**
     * Activate an ownership split.
     *
     * Percentages arrive as strings and are converted one at a time so a single
     * malformed entry names its own partner rather than failing the whole form
     * with "invalid input".
     */
    public function activateShares(): void
    {
        $raw = $_POST['shares'] ?? [];
        $effectiveFrom = (string) ($_POST['effective_from'] ?? '');

        if (!is_array($raw) || $raw === []) {
            Session::flash('error', 'Enter a share for at least one partner.');
            Http::redirect('/partners');
        }

        if (strtotime($effectiveFrom) === false) {
            Session::flash('error', 'Choose a date for the split to take effect.');
            Http::redirect('/partners');
        }

        $shares = [];
        $fieldErrors = [];

        foreach ($raw as $partnerId => $percent) {
            $percent = trim((string) $percent);
            if ($percent === '') {
                continue; // Blank means "not in this split".
            }

            try {
                $shares[(int) $partnerId] = ShareService::toBasisPoints($percent);
            } catch (InvalidArgumentException $e) {
                $fieldErrors['shares.' . (int) $partnerId] = [$e->getMessage()];
            }
        }

        if ($fieldErrors !== []) {
            Session::set('_old', $_POST);
            Session::flash('errors', $fieldErrors);
            Session::flash('error', 'Some shares are not valid percentages.');
            Http::redirect('/partners');
        }

        $problems = ShareService::validateSplit($shares);
        if ($problems !== []) {
            Session::set('_old', $_POST);
            Session::flash('error', $problems[0]);
            Http::redirect('/partners');
        }

        try {
            ShareService::activateSplit($shares, $effectiveFrom);
        } catch (RuntimeException $e) {
            Session::set('_old', $_POST);
            Session::flash('error', $e->getMessage());
            Http::redirect('/partners');
        } catch (Throwable $e) {
            Logger::error('Share activation failed', ['message' => $e->getMessage()]);
            Session::flash('error', 'The split could not be saved. Nothing was changed.');
            Http::redirect('/partners');
        }

        Session::forget('_old');
        $when = date('j M Y', (int) strtotime($effectiveFrom));
        Session::flash('success', 'Ownership split activated from ' . $when . '.');
        Http::redirect('/partners');
    }
}
