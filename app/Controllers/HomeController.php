<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;

final class HomeController extends Controller
{
    public function index(): void
    {
        $this->view('pages/home', [
            'title' => 'SupportHive',
        ]);
    }
}
