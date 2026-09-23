<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Session;
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

        foreach ($clean as $key => $value) {
            Settings::set($key, (string) $value);
        }

        // Settings change how money is presented and when alerts fire, so the
        // change itself is a security-relevant event.
        Logger::security('Settings updated', ['keys' => array_keys($clean)]);

        Session::flash('success', 'Settings saved.');
        Http::redirect('/settings');
    }
}
