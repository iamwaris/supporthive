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
            // Per-field messages. A summary banner alone is not enough: the
            // design rule is that every field shows its own error.
            'errors'   => Session::flash('errors') ?? [],
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
     * $input defaults to $_POST; pass a normalised copy of it when a field
     * must be cleaned up before its rules apply. $notFlashed names fields
     * kept out of the session's flashed input, like the passwords always
     * are — values that must never sit in plain text in a session file.
     *
     * @param array<string,string> $rules
     * @param array<string,mixed>|null $input
     * @param list<string> $notFlashed
     * @return array<string,mixed>
     */
    protected function validate(array $rules, string $back, ?array $input = null, array $notFlashed = []): array
    {
        $validator = Validator::make($input ?? $_POST, $rules);

        if ($validator->fails()) {
            $old = $_POST;
            unset($old['password'], $old['password_confirmation'], $old[Csrf::FIELD]);
            foreach ($notFlashed as $field) {
                unset($old[$field]);
            }
            Session::set('_old', $old);
            Session::flash('errors', $validator->errors());
            Session::flash('error', 'Please correct the highlighted fields.');
            Http::redirect($back);
        }

        Session::forget('_old');
        return $validator->validated();
    }
}
