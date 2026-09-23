<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Allow-list input validation.
 *
 * Rule of the project: no request value is used until it has passed through a
 * Validator rule. Validation constrains what a value may BE; escaping (e()) at
 * output time decides how it is safely rendered. Both are always required.
 *
 * Usage:
 *   $v = Validator::make($_POST, [
 *       'email'    => 'required|email|max:190',
 *       'password' => 'required|min:12',
 *       'age'      => 'nullable|int|between:18,120',
 *   ]);
 *   if (!$v->passes()) { ... $v->errors() ... }
 *   $clean = $v->validated();
 */
final class Validator
{
    /** @var array<string,mixed> */
    private array $data;
    /** @var array<string,string> */
    private array $rules;
    /** @var array<string,list<string>> */
    private array $errors = [];
    /** @var array<string,mixed> */
    private array $validated = [];

    /**
     * @param array<string,mixed>  $data
     * @param array<string,string> $rules
     */
    private function __construct(array $data, array $rules)
    {
        $this->data = $data;
        $this->rules = $rules;
        $this->run();
    }

    /**
     * @param array<string,mixed>  $data
     * @param array<string,string> $rules
     */
    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /** @return array<string,list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }

    /** Only fields with rules are returned: mass-assignment cannot smuggle extras. */
    public function validated(): array
    {
        return $this->validated;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $raw = $this->data[$field] ?? null;
            $value = is_string($raw) ? trim($raw) : $raw;
            $rules = explode('|', $ruleString);
            $nullable = in_array('nullable', $rules, true);

            if (($value === null || $value === '') && $nullable) {
                $this->validated[$field] = null;
                continue;
            }

            $label = str_replace('_', ' ', $field);
            $failed = false;

            foreach ($rules as $rule) {
                if ($rule === 'nullable') {
                    continue;
                }
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);

                $ok = match ($name) {
                    'required' => $value !== null && $value !== '' && $value !== [],
                    'string'   => is_string($value),
                    'int'      => filter_var($value, FILTER_VALIDATE_INT) !== false,
                    'numeric'  => is_numeric($value),
                    'bool'     => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) !== null,
                    'email'    => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                    'url'      => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false,
                    'date'     => is_string($value) && strtotime($value) !== false,
                    'alpha'    => is_string($value) && preg_match('/^[\p{L}]+$/u', $value) === 1,
                    'alphanum' => is_string($value) && preg_match('/^[\p{L}\p{N}]+$/u', $value) === 1,
                    'slug'     => is_string($value) && preg_match('/^[a-z0-9-]+$/', $value) === 1,
                    'min'      => $this->compareLength($value, (int) $arg, '>='),
                    'max'      => $this->compareLength($value, (int) $arg, '<='),
                    'between'  => $this->between($value, (string) $arg),
                    'in'       => in_array((string) $value, explode(',', (string) $arg), true),
                    'regex'    => is_string($value) && preg_match((string) $arg, $value) === 1,
                    'same'     => $value === ($this->data[(string) $arg] ?? null),
                    default    => true,
                };

                if (!$ok) {
                    $this->errors[$field][] = $this->message($name, $label, $arg);
                    $failed = true;
                    break;
                }
            }

            if (!$failed) {
                $this->validated[$field] = $value;
            }
        }
    }

    private function compareLength(mixed $value, int $limit, string $operator): bool
    {
        $length = is_numeric($value) && !is_string($value)
            ? (float) $value
            : mb_strlen((string) $value);

        return $operator === '>=' ? $length >= $limit : $length <= $limit;
    }

    private function between(mixed $value, string $arg): bool
    {
        [$min, $max] = array_pad(explode(',', $arg), 2, '0');
        if (!is_numeric($value)) {
            return false;
        }
        return (float) $value >= (float) $min && (float) $value <= (float) $max;
    }

    private function message(string $rule, string $label, ?string $arg): string
    {
        return match ($rule) {
            'required' => "The {$label} field is required.",
            'email'    => "Enter a valid email address.",
            'url'      => "Enter a valid URL.",
            'int', 'numeric' => "The {$label} must be a number.",
            'min'      => "The {$label} must be at least {$arg} characters.",
            'max'      => "The {$label} may not be longer than {$arg} characters.",
            'between'  => sprintf(
                'The %s must be between %s.',
                $label,
                // "1,100" reads as one number. Say "1 and 100".
                implode(' and ', array_map('trim', explode(',', (string) $arg)))
            ),
            'in'       => "The selected {$label} is not valid.",
            'same'     => "The {$label} does not match.",
            default    => "The {$label} is not valid.",
        };
    }
}
