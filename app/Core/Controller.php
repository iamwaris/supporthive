<?php

declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    /** @param array<string,mixed> $data */
    protected function view(string $template, array $data = [], string $layout = 'layouts/app'): void
    {
        $data += [
            'authUser' => Auth::user(),
            'flash'    => [
                'success' => Session::flash('success'),
                'error'   => Session::flash('error'),
            ],
        ];
        View::render($template, $data, $layout);
    }

    /** @param array<string,mixed> $data */
    protected function json(array $data, int $status = 200): never
    {
        Http::json($data, $status);
    }

    protected function redirect(string $path, int $status = 302): never
    {
        Http::redirect($path, $status);
    }

    /**
     * Validate the request body. On failure the input (minus secrets) and errors
     * are flashed back and the user is returned to $back.
     *
     * @param array<string,string> $rules
     * @return array<string,mixed>
     */
    protected function validate(array $rules, string $back): array
    {
        $validator = Validator::make($_POST, $rules);

        if ($validator->fails()) {
            $old = $_POST;
            unset($old['password'], $old['password_confirmation'], $old[Csrf::FIELD]);
            Session::set('_old', $old);
            Session::flash('errors', $validator->errors());
            Session::flash('error', 'Please correct the highlighted fields.');
            Http::redirect($back);
        }

        Session::forget('_old');
        return $validator->validated();
    }
}
