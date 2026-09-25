<?php

/**
 * Add Expense — built for repetition, per spec §20.3.
 *
 * @var list<array<string,mixed>> $accounts
 * @var list<array{parent:array<string,mixed>,children:list<array<string,mixed>>}> $tree
 * @var int                       $lastAccountId
 * @var list<array{vendor:string,uses:int}> $suggestions
 * @var array<string,list<string>> $errors
 * @var bool                       $aiEnabled
 */

declare(strict_types=1);

use App\Core\Csrf;
use App\Services\Settings;

$errors = $errors ?? [];
$aiEnabled = $aiEnabled ?? false;
$symbol = Settings::string('currency_symbol', 'Rs');
$activeAccounts = array_values(array_filter($accounts, static fn (array $a): bool => (int) $a['is_active'] === 1));
$hasCategories = $tree !== [];
?>

<?php if (!$hasCategories || $activeAccounts === []) : ?>
    <div class="card p-6">
        <h2 class="font-display text-base font-semibold text-ink">Set up first</h2>
        <p class="mt-2 text-sm text-slate-600">
            An expense needs somewhere to sit before it can be recorded.
        </p>
        <ul class="mt-3 flex flex-col gap-1.5 text-sm text-slate-600">
            <?php if ($activeAccounts === []) : ?>
                <li>• No active account to pay from &mdash; <a href="<?= e(url('/accounts')) ?>">add one</a></li>
            <?php endif; ?>
            <?php if (!$hasCategories) : ?>
                <li>• No expense categories &mdash; <a href="<?= e(url('/categories')) ?>">add one</a></li>
            <?php endif; ?>
        </ul>
    </div>
<?php else : ?>
<form method="post" action="<?= e(url('/expenses')) ?>" enctype="multipart/form-data" novalidate
      x-data="{
          vendor: <?= e(json_encode(old('vendor'), JSON_THROW_ON_ERROR)) ?>,
          amount: <?= e(json_encode(old('amount'), JSON_THROW_ON_ERROR)) ?>,
          transactionDate: <?= e(json_encode(old('transaction_date', date('Y-m-d')), JSON_THROW_ON_ERROR)) ?>,
          categoryId: <?= e(json_encode(old('category_id'), JSON_THROW_ON_ERROR)) ?>,
          suggestions: [],
          open: false,
          async lookup() {
              if (this.vendor.length < 1) { this.suggestions = []; this.open = false; return; }
              try {
                  const response = await fetch('<?= e(url('/expenses/vendors')) ?>?q=' + encodeURIComponent(this.vendor));
                  const data = await response.json();
                  this.suggestions = data.suggestions || [];
                  this.open = this.suggestions.length > 0;
              } catch (e) { this.suggestions = []; this.open = false; }
          },
          pick(name) { this.vendor = name; this.open = false; },
          scanning: false,
          scanError: null,
          scanBanner: false,
          async scanReceipt(event) {
              const input = event.target;
              const file = input.files && input.files[0];
              if (!file) { return; }

              this.scanning = true;
              this.scanError = null;
              this.scanBanner = false;

              try {
                  const body = new FormData();
                  body.append('receipt', file);
                  const response = await fetch('<?= e(url('/expenses/scan-receipt')) ?>', {
                      method: 'POST',
                      headers: { 'X-CSRF-Token': '<?= e(Csrf::token()) ?>' },
                      body,
                  });
                  const data = await response.json();

                  if (!response.ok) {
                      this.scanError = data.error || 'Could not read that receipt. Enter the details manually.';
                      return;
                  }

                  if (data.vendor) { this.vendor = data.vendor; }
                  if (data.amount) { this.amount = data.amount; }
                  if (data.transaction_date) { this.transactionDate = data.transaction_date; }
                  if (data.category_id !== null && data.category_id !== undefined) {
                      this.categoryId = String(data.category_id);
                  }
                  this.scanBanner = true;
              } catch (e) {
                  this.scanError = 'Could not read that receipt. Enter the details manually.';
              } finally {
                  this.scanning = false;
                  input.value = '';
              }
          }
      }">
    <?= csrf_field() ?>

    <?php if ($aiEnabled) : ?>
        <div class="mb-5 card p-4 sm:p-5">
            <label for="ai_receipt" class="label">Scan a receipt to auto-fill</label>
            <input id="ai_receipt" type="file" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf"
                   x-on:change="scanReceipt($event)" x-bind:disabled="scanning"
                   class="input file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-slate-700">
            <p class="help">Reads the vendor, amount, date and category from a photo or PDF — review before saving.</p>

            <p x-show="scanning" x-cloak class="mt-2 flex items-center gap-2 text-[11.5px] text-slate-500"
               role="status" aria-live="polite">
                <span class="h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-slate-300 border-t-brand-500"></span>
                Reading receipt&hellip;
            </p>

            <p x-show="scanError" x-cloak x-text="scanError" class="error mt-2" role="alert" aria-live="assertive"></p>

            <div x-show="scanBanner" x-cloak
                 class="mt-3 flex items-start justify-between gap-3 rounded-lg border border-brand-200 bg-brand-50 px-3 py-2 text-[11.5px] text-brand-800"
                 role="status" aria-live="polite">
                <span>Pre-filled from receipt &mdash; review before saving.</span>
                <button type="button" x-on:click="scanBanner = false" class="shrink-0 font-medium underline">
                    Dismiss
                </button>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid gap-5 xl:grid-cols-[1fr_340px]">

        <!-- ============ the form ============ -->
        <div class="card p-6">

            <div class="flex flex-wrap gap-5">
                <div class="w-full sm:w-48">
                    <label for="transaction_date" class="label">Date</label>
                    <input id="transaction_date" name="transaction_date" type="date" required
                           value="<?= e(old('transaction_date', date('Y-m-d'))) ?>" x-model="transactionDate" class="input">
                    <p class="help">Defaults to today</p>
                </div>

                <div class="min-w-48 flex-1">
                    <label for="amount" class="label">Amount (<?= e($symbol) ?>)</label>
                    <input id="amount" name="amount" type="text" inputmode="decimal" required
                           autocomplete="off" placeholder="0.00"
                           value="<?= e(old('amount')) ?>" x-model="amount"
                           class="money h-14 text-2xl font-medium tracking-tight
                                  <?= isset($errors['amount']) ? 'input-error' : 'input' ?>">
                    <?php if (isset($errors['amount'][0])) : ?>
                        <p class="error"><?= e($errors['amount'][0]) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="my-5 h-px bg-slate-100"></div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="category_id" class="label">Category</label>
                    <select id="category_id" name="category_id" required x-model="categoryId"
                            class="<?= isset($errors['category_id']) ? 'input-error' : 'input' ?>">
                        <option value="">Choose one&hellip;</option>
                        <?php foreach ($tree as $node) : ?>
                            <?php if ((int) $node['parent']['is_active'] !== 1) : ?>
                                <?php continue; ?>
                            <?php endif; ?>
                            <?php if ($node['children'] === []) : ?>
                                <option value="<?= e((string) $node['parent']['id']) ?>"
                                    <?= old('category_id') === (string) $node['parent']['id'] ? 'selected' : '' ?>>
                                    <?= e((string) $node['parent']['name']) ?>
                                </option>
                            <?php else : ?>
                                <optgroup label="<?= e((string) $node['parent']['name']) ?>">
                                    <option value="<?= e((string) $node['parent']['id']) ?>"
                                        <?= old('category_id') === (string) $node['parent']['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $node['parent']['name']) ?> (general)
                                    </option>
                                    <?php foreach ($node['children'] as $child) : ?>
                                        <?php if ((int) $child['is_active'] !== 1) : ?>
                                            <?php continue; ?>
                                        <?php endif; ?>
                                        <option value="<?= e((string) $child['id']) ?>"
                                            <?= old('category_id') === (string) $child['id'] ? 'selected' : '' ?>>
                                            <?= e((string) $child['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['category_id'][0])) : ?>
                        <p class="error"><?= e($errors['category_id'][0]) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label for="account_id" class="label">Paid from</label>
                    <select id="account_id" name="account_id" required class="input">
                        <?php foreach ($activeAccounts as $account) : ?>
                            <?php
                            $id = (string) $account['id'];
                            $selected = old('account_id') !== ''
                                ? old('account_id') === $id
                                : $lastAccountId === (int) $account['id'];
                            ?>
                            <option value="<?= e($id) ?>" <?= $selected ? 'selected' : '' ?>>
                                <?= e((string) $account['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($lastAccountId > 0) : ?>
                        <p class="help">Remembers the account you used last</p>
                    <?php endif; ?>
                </div>

                <!-- vendor: free text, with a type-ahead so spellings converge -->
                <div class="relative">
                    <label for="vendor" class="label">
                        Vendor / payee <span class="font-normal text-slate-400">optional</span>
                    </label>
                    <input id="vendor" name="vendor" type="text" maxlength="160" autocomplete="off"
                           x-model="vendor" x-on:input.debounce.200ms="lookup()"
                           x-on:focus="lookup()" x-on:keydown.escape="open = false"
                           class="input">
                    <div x-show="open" x-cloak x-on:click.outside="open = false"
                         class="absolute left-0 right-0 top-full z-10 mt-1 rounded-lg border border-slate-200 bg-white p-1 shadow-lg">
                        <template x-for="item in suggestions" x-bind:key="item.vendor">
                            <button type="button" x-on:click="pick(item.vendor)"
                                    class="flex min-h-11 w-full items-center gap-2 rounded-md px-2.5 py-2 text-left hover:bg-brand-50">
                                <span class="flex-1 truncate text-xs text-ink" x-text="item.vendor"></span>
                                <span class="money text-[10.5px] text-slate-400"
                                      x-text="'used ' + item.uses + '×'"></span>
                            </button>
                        </template>
                    </div>
                    <p class="help">Suggestions come from what you have typed before</p>
                </div>

                <div>
                    <label for="reference_no" class="label">
                        Reference <span class="font-normal text-slate-400">optional</span>
                    </label>
                    <input id="reference_no" name="reference_no" type="text" maxlength="80"
                           value="<?= e(old('reference_no')) ?>" class="input money">
                </div>

                <div>
                    <label for="receipt" class="label">
                        Receipt <span class="font-normal text-slate-400">optional</span>
                    </label>
                    <input id="receipt" name="receipt" type="file" accept="image/jpeg,image/png,image/webp,application/pdf"
                           class="input file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-slate-700">
                    <p class="help">JPG, PNG, WebP or PDF</p>
                </div>
            </div>

            <div class="mt-5">
                <label for="description" class="label">Description</label>
                <input id="description" name="description" type="text" required maxlength="255"
                       placeholder="What was this for?"
                       value="<?= e(old('description')) ?>"
                       class="<?= isset($errors['description']) ? 'input-error' : 'input' ?>">
                <?php if (isset($errors['description'][0])) : ?>
                    <p class="error"><?= e($errors['description'][0]) ?></p>
                <?php endif; ?>
            </div>

            <div class="mt-5">
                <label for="notes" class="label">
                    Notes <span class="font-normal text-slate-400">optional</span>
                </label>
                <textarea id="notes" name="notes" rows="2" maxlength="2000"
                          placeholder="Anything worth remembering later"
                          class="input h-auto py-2.5"><?= e(old('notes')) ?></textarea>
            </div>

            <div class="mt-6 flex flex-col gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:items-center">
                <p class="flex-1 text-[11.5px] text-slate-500">
                    Recorded against your account with the date and time.
                </p>
                <div class="flex flex-wrap gap-3">
                    <a href="<?= e(url('/expenses')) ?>" class="btn-secondary">Cancel</a>
                    <button type="submit" name="add_another" value="1" class="btn-secondary">Save &amp; add another</button>
                    <button type="submit" class="btn-primary">Save expense</button>
                </div>
            </div>
        </div>

        <!-- ============ side rail ============ -->
        <div class="flex flex-col gap-4">
            <div class="card p-5">
                <h2 class="font-display text-[13px] font-semibold text-ink">How this is stored</h2>
                <ul class="mt-3 flex flex-col gap-2.5 text-[11.5px] leading-relaxed text-slate-600">
                    <li class="flex gap-2">
                        <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-brand-500"></span>
                        The amount goes into the ledger as an exact decimal &mdash; never through a
                        floating-point number.
                    </li>
                    <li class="flex gap-2">
                        <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-brand-500"></span>
                        A mistake is voided with a reason, not deleted. The original stays readable.
                    </li>
                    <li class="flex gap-2">
                        <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-brand-500"></span>
                        Only expense categories are offered here, so this can never land in revenue.
                    </li>
                </ul>
            </div>

            <?php if ($suggestions !== []) : ?>
                <div class="card p-5">
                    <h2 class="font-display text-[13px] font-semibold text-ink">Frequent payees</h2>
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        <?php foreach ($suggestions as $item) : ?>
                            <button type="button" x-on:click="pick(<?= e(json_encode($item['vendor'], JSON_THROW_ON_ERROR)) ?>)"
                                    class="rounded-md border border-slate-200 px-2 py-1 text-[11.5px] text-slate-600 hover:border-brand-500 hover:bg-brand-50">
                                <?= e($item['vendor']) ?>
                                <span class="money text-slate-400"><?= e((string) $item['uses']) ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php endif; ?>
