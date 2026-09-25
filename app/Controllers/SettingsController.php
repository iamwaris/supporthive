<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Session;
use App\Services\Audit;
use App\Services\Settings;

final class SettingsController extends Controller
{
    public function index(): void
    {
        $this->view('pages/settings', [
            'title' => 'Settings',
            'nav' => 'settings',
            'pageTitle' => 'Settings',
            'pageMeta' => 'Company, currency and alert thresholds',
            'settings' => Settings::all(),
        ]);
    }

    public function update(): void
    {
        $clean = $this->validate([
            'company_name' => 'required|max:120',
            'currency_code' => 'required|alpha|min:3|max:3',
            'currency_symbol' => 'required|max:8',
            'fiscal_year_start' => 'required|int|between:1,12',
            'budget_alert_pct' => 'required|int|between:1,100',
        ], '/settings');

        // Validator::validated() only checks that these look like integers —
        // it doesn't cast — so they arrive here as strings. Cast the known
        // int fields back to int before Settings::set() so it can infer the
        // correct value_type instead of writing every field as 'string'.
        $clean['fiscal_year_start'] = (int) $clean['fiscal_year_start'];
        $clean['budget_alert_pct'] = (int) $clean['budget_alert_pct'];

        $before = Settings::all();

        foreach ($clean as $key => $value) {
            Settings::set($key, $value);
        }

        // Settings change how money is presented and when alerts fire, so the
        // change itself is a security-relevant event.
        Logger::security('Settings updated', ['keys' => array_keys($clean)]);
        $diff = Audit::diff($before, $clean);
        Audit::record('settings.updated', 'settings', null, $diff['before'], $diff['after']);

        Session::flash('success', 'Settings saved.');
        Http::redirect('/settings');
    }
}
