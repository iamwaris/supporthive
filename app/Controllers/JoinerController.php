<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Session;
use App\Core\Validator;
use App\Models\EmployeeJoinLink;
use App\Models\JoinerSubmission;
use App\Services\Audit;
use App\Services\BankDetails;
use App\Services\EmployeeOnboarding;
use PDOException;

/**
 * New joiners, admin only (`can:administer`), scoped to the admin's branch:
 * the branch's shared /join link (generate, regenerate, disable, enable) and
 * the submissions waiting for review (approve or delete).
 *
 * Every id resolves through JoinerSubmission's branch-scoped reads, so
 * another branch's submission is a 404. Approve and delete re-read the row
 * with FOR UPDATE and act only while it is still pending.
 */
final class JoinerController extends Controller
{
    private const PER_PAGE = 20;

    /** The approval form: what the admin decides, plus the two fields a joiner may have got wrong. */
    private const APPROVE_RULES = [
        'employee_code' => 'required|max:30|regex:/^[A-Za-z0-9-]+$/',
        'designation' => 'required|max:120',
        'email' => 'required|email|max:190',
        'joining_date' => 'nullable|ymd',
    ] + EmployeeOnboarding::STARTING_SALARY_RULES;

    public function index(): void
    {
        $filters = Validator::make($this->queryStrings(['status', 'page']), [
            'status' => 'nullable|in:' . implode(',', JoinerSubmission::STATUSES),
            'page' => 'nullable|int|between:1,100000',
        ])->validated();
        $status = isset($filters['status']) ? (string) $filters['status'] : 'pending';
        $page = isset($filters['page']) ? (int) $filters['page'] : 1;

        $submissions = new JoinerSubmission();
        $total = $submissions->countWithStatus($status);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $rows = $submissions->search($status, $page, self::PER_PAGE);

        $masks = [];
        foreach ($rows as $row) {
            // Approved rows hold no bank details any more (moved to the employee).
            $masks[(int) $row['id']] = $status === 'pending'
                ? BankDetails::maskedSummary($row)
                : null;
        }

        $pending = $status === 'pending' ? $total : $submissions->countWithStatus('pending');

        $this->view('pages/joiners/index', [
            'title' => 'New joiners',
            'nav' => 'joiners',
            'pageTitle' => 'New joiners',
            'pageMeta' => $pending === 1 ? '1 waiting for review' : number_format($pending) . ' waiting for review',
            'link' => (new EmployeeJoinLink())->current(),
            // Present only on the request right after (re)generating: the
            // token is not stored, so this is the one chance to copy it.
            'newLinkUrl' => Session::flash('join_link_url'),
            'submissions' => $rows,
            'bankMasks' => $masks,
            'status' => $status,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'perPage' => self::PER_PAGE,
        ]);
    }

    /** @param array<string,string> $params */
    public function show(array $params): void
    {
        $submission = $this->findOr404($params);

        $this->view('pages/joiners/show', [
            'title' => (string) $submission['name'],
            'nav' => 'joiners',
            'pageTitle' => (string) $submission['name'],
            'pageMeta' => 'Submitted ' . date('j M Y, H:i', (int) strtotime((string) $submission['created_at'])),
            'submission' => $submission,
            // Only a pending row still holds them; see JoinerSubmission::markApproved().
            'bankDetails' => (string) $submission['status'] === 'pending'
                ? BankDetails::from($submission)
                : null,
            'defaults' => [
                'designation' => EmployeeOnboarding::DEFAULT_DESIGNATION,
                'salary' => EmployeeOnboarding::DEFAULT_STARTING_SALARY,
            ],
        ]);
    }

    /**
     * Creates the employee exactly as "Add employee" does — the same
     * EmployeeOnboarding::create(), so a login with a one-time temporary
     * password shown once to this admin — plus the joiner's bank details,
     * moved as they are. The starting salary, if kept, takes effect from the
     * joining date, or today.
     *
     * @param array<string,string> $params
     */
    public function approve(array $params): void
    {
        $submission = $this->findOr404($params);
        $id = (int) $submission['id'];
        $back = '/joiners/' . $id;
        $this->requirePending($submission, $back);

        $clean = $this->validate(self::APPROVE_RULES, $back . '#approve');
        $fields = EmployeeOnboarding::normalise([
            'name' => $submission['name'],
            'email' => $clean['email'],
            'phone' => $submission['phone'],
            'designation' => $clean['designation'],
            'joining_date' => $clean['joining_date'],
            'employee_code' => $clean['employee_code'],
        ]);

        $conflicts = EmployeeOnboarding::conflicts($fields);
        if ($conflicts !== []) {
            $this->failWithFieldErrors($conflicts, $back . '#approve');
        }

        $startingSalary = EmployeeOnboarding::startingSalary($clean);
        $submissions = new JoinerSubmission();

        try {
            $created = $this->approveInTransaction($submissions, $id, $fields, $startingSalary);
        } catch (PDOException $e) {
            // Another approval or employee took the email or Employee ID
            // between the check above and this write.
            $this->failWithFieldErrors(EmployeeOnboarding::conflictsFromDuplicate($e, $fields), $back . '#approve');
        }

        if ($created === null) {
            Session::flash('error', 'This joiner has already been reviewed.');
            Http::redirect($back);
        }

        Logger::security('Joiner approved', ['submission_id' => $id, 'user_id' => $created['id'], 'by' => Auth::id()]);
        Session::flash(
            'success',
            EmployeeOnboarding::credentialsNotice($fields['name'], $fields['email'], $created['temporaryPassword'])
        );
        Http::redirect('/employees/' . $created['id']);
    }

    /**
     * @param array{
     *     name:string,email:string,phone:?string,designation:string,joining_date:?string,employee_code:?string
     * } $fields
     * @return array{id:int,temporaryPassword:string}|null null when the row is no longer pending
     */
    private function approveInTransaction(
        JoinerSubmission $submissions,
        int $id,
        array $fields,
        ?string $startingSalary
    ): ?array {
        return Database::instance()->transaction(
            static function () use ($submissions, $id, $fields, $startingSalary): ?array {
                $locked = $submissions->lockPending($id);
                if ($locked === null) {
                    return null;
                }

                $bankColumns = BankDetails::from($locked);
                $created = EmployeeOnboarding::create($fields, $startingSalary, $bankColumns);
                $submissions->markApproved($id, $created['id']);
                Audit::record(
                    'joiner.approved',
                    'joiner_submissions',
                    $id,
                    ['status' => 'pending'],
                    [
                        'status' => 'approved',
                        'employee_user_id' => $created['id'],
                        'employee_code' => $fields['employee_code'],
                    ]
                );

                return $created;
            }
        );
    }

    /**
     * Permanently deletes a pending submission — a hard delete, bank
     * details included; nothing is kept to retain. Approved joiners are
     * employees by then and are never deleted from here. The audit row names
     * who was deleted and when they submitted, never what they sent.
     *
     * @param array<string,string> $params
     */
    public function destroy(array $params): void
    {
        $submission = $this->findOr404($params);
        $id = (int) $submission['id'];
        $this->requirePending($submission, '/joiners/' . $id);
        $submissions = new JoinerSubmission();

        $deleted = Database::instance()->transaction(static function () use ($submissions, $id): ?array {
            $locked = $submissions->lockPending($id);
            if ($locked === null) {
                return null;
            }

            $submissions->deletePending($id);
            Audit::record(
                'joiner.deleted',
                'joiner_submissions',
                $id,
                ['name' => $locked['name'], 'submitted_at' => $locked['created_at']],
                null
            );

            return $locked;
        });

        if ($deleted === null) {
            Session::flash('error', 'This joiner has already been reviewed.');
            Http::redirect('/joiners/' . $id);
        }

        Logger::security('Joiner deleted', ['submission_id' => $id, 'by' => Auth::id()]);
        Session::flash('success', (string) $deleted['name'] . ' was deleted.');
        Http::redirect('/joiners');
    }

    /**
     * First link, or a replacement: the previous URL stops working at once.
     * The new URL is flashed for the next page and never stored.
     */
    public function regenerateLink(): void
    {
        $links = new EmployeeJoinLink();
        $hadLink = $links->current() !== null;
        $token = $links->regenerate();

        Audit::record(
            $hadLink ? 'join_link.regenerated' : 'join_link.generated',
            'employee_join_links',
            self::linkId($links)
        );
        Logger::security('Joiner link generated', ['branch_id' => Auth::branchId(), 'by' => Auth::id()]);

        Session::flash('join_link_url', url('/join/' . $token));
        Session::flash('success', $hadLink
            ? 'New link generated. The old link no longer works.'
            : 'Link generated.');
        Http::redirect('/joiners');
    }

    public function disableLink(): void
    {
        $this->switchLink(false);
    }

    public function enableLink(): void
    {
        $this->switchLink(true);
    }

    private function switchLink(bool $active): never
    {
        $links = new EmployeeJoinLink();
        if (!$links->setActive($active)) {
            Session::flash('error', 'There is no link yet. Generate one first.');
            Http::redirect('/joiners');
        }

        Audit::record(
            $active ? 'join_link.enabled' : 'join_link.disabled',
            'employee_join_links',
            self::linkId($links)
        );
        Logger::security($active ? 'Joiner link enabled' : 'Joiner link disabled', [
            'branch_id' => Auth::branchId(),
            'by' => Auth::id(),
        ]);

        Session::flash('success', $active
            ? 'Link enabled. The same URL works again.'
            : 'Link disabled. Anyone opening it now sees “This link is no longer active”.');
        Http::redirect('/joiners');
    }

    private static function linkId(EmployeeJoinLink $links): ?int
    {
        $link = $links->current();

        return $link === null ? null : (int) $link['id'];
    }

    /** @param array<string,mixed> $submission */
    private function requirePending(array $submission, string $back): void
    {
        if ((string) $submission['status'] !== 'pending') {
            Session::flash('error', 'This joiner has already been reviewed.');
            Http::redirect($back);
        }
    }

    /**
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    private function findOr404(array $params): array
    {
        $submission = (new JoinerSubmission())->findWithReviewer((int) ($params['id'] ?? 0));
        if ($submission === null) {
            Http::abort(404);
        }

        return $submission;
    }

    /** @param array<string,string> $messages field => message */
    private function failWithFieldErrors(array $messages, string $back): never
    {
        $old = $_POST;
        unset($old[Csrf::FIELD]);
        Session::set('_old', $old);
        Session::flash('errors', array_map(static fn (string $message): array => [$message], $messages));
        Session::flash('error', 'Please correct the highlighted fields.');
        Http::redirect($back);
    }

    /**
     * Only string query values reach the Validator; an array (?status[]=x)
     * is dropped rather than cast.
     *
     * @param list<string> $keys
     * @return array<string,string>
     */
    private function queryStrings(array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            if (isset($_GET[$key]) && is_string($_GET[$key])) {
                $values[$key] = $_GET[$key];
            }
        }

        return $values;
    }
}
