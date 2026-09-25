<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Runs one controller method to completion in a real, separate PHP process.
 *
 * Every state-changing controller action in this app ends in Http::redirect(),
 * Controller::json() or Http::abort() — all declared `: never` and all calling
 * `exit`. That is correct for the app (see Http::redirect()/json()/abort()) but
 * means the method cannot be invoked from inside the PHPUnit process itself:
 * the `exit` would kill the whole test run, and PHPUnit's own process-isolation
 * template (vendor/phpunit/phpunit/src/Util/PHP/Template/TestCaseMethod.tpl)
 * only writes its result file *after* the test method returns normally, so
 * `@runInSeparateProcess` cannot be used here either — a mid-method `exit`
 * leaves nothing for the parent process to read back.
 *
 * This sidesteps both problems by not asking PHPUnit to survive the `exit` at
 * all: the action runs in a genuinely separate `php` process that is allowed
 * to terminate however it likes, and the two things a test actually needs —
 * the HTTP status code (captured from a `register_shutdown_function`, which
 * still runs after `exit`) and whatever body the action echoed or JSON it
 * printed — are handed back over a pipe and a temp file. Database writes the
 * action made before exiting are already committed by the time this returns,
 * since they went through the same MySQL connection the app always uses; the
 * calling test re-reads them with the normal (non-subprocess) Database
 * connection, exactly as it would after a real HTTP request.
 */
final class ControllerActionRunner
{
    /**
     * @param array<string,mixed> $session   Written to $_SESSION before the call.
     * @param array<string,mixed> $post      Written to $_POST before the call.
     * @param array<string,array{name:string,type:string,tmp_name:string,error:int,size:int}> $files
     *                                       Written to $_FILES before the call.
     * @return array{status:int,body:string}
     */
    public static function run(
        string $controllerClass,
        string $method,
        array $session,
        array $post = [],
        array $files = [],
        string $requestMethod = 'POST'
    ): array {
        $resultFile = tempnam(sys_get_temp_dir(), 'shresult');
        if ($resultFile === false) {
            throw new RuntimeException('Could not create a temp file for the subprocess result.');
        }

        $scriptFile = tempnam(sys_get_temp_dir(), 'shrun');
        if ($scriptFile === false) {
            throw new RuntimeException('Could not create a temp file for the subprocess script.');
        }
        $phpFile = $scriptFile . '.php';
        rename($scriptFile, $phpFile);

        file_put_contents($phpFile, self::buildScript(
            $controllerClass,
            $method,
            $session,
            $post,
            $files,
            $requestMethod,
            $resultFile
        ));

        $process = proc_open(
            [PHP_BINARY, $phpFile],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2)
        );

        if ($process === false) {
            unlink($phpFile);
            throw new RuntimeException('Could not start the subprocess.');
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        unlink($phpFile);

        $resultJson = is_file($resultFile) ? (string) file_get_contents($resultFile) : '';
        if (is_file($resultFile)) {
            unlink($resultFile);
        }

        /** @var array{status:int|null}|null $result */
        $result = $resultJson !== '' ? json_decode($resultJson, true) : null;
        $status = is_array($result) ? (int) ($result['status'] ?? 0) : 0;

        if ($status === 0) {
            throw new RuntimeException(
                'Subprocess for ' . $controllerClass . '::' . $method . '() produced no status. '
                . "stdout:\n" . $stdout . "\nstderr:\n" . $stderr
            );
        }

        return ['status' => $status, 'body' => $stdout];
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $post
     * @param array<string,array{name:string,type:string,tmp_name:string,error:int,size:int}> $files
     */
    private static function buildScript(
        string $controllerClass,
        string $method,
        array $session,
        array $post,
        array $files,
        string $requestMethod,
        string $resultFile
    ): string {
        // Method names here are always one of this class's own literal
        // arguments ('edit', 'update', 'scanReceipt', ...), never request
        // input, but the identifier check keeps it impossible to break out
        // of the `->name()` call syntax below regardless.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method) !== 1) {
            throw new RuntimeException('Not a valid PHP method name: ' . $method);
        }

        $bootstrapLiteral = var_export(dirname(__DIR__) . '/bootstrap.php', true);
        $controllerClassLiteral = var_export($controllerClass, true);
        $sessionLiteral = var_export($session, true);
        $postLiteral = var_export($post, true);
        $filesLiteral = var_export($files, true);
        $requestMethodLiteral = var_export($requestMethod, true);
        $resultFileLiteral = var_export($resultFile, true);

        return <<<PHP
            <?php
            declare(strict_types=1);
            require {$bootstrapLiteral};

            \$_SESSION = {$sessionLiteral};
            \$_POST = {$postLiteral};
            \$_FILES = {$filesLiteral};
            \$_SERVER['REQUEST_METHOD'] = {$requestMethodLiteral};

            \$resultFile = {$resultFileLiteral};
            register_shutdown_function(static function () use (\$resultFile) {
                file_put_contents(\$resultFile, json_encode(['status' => http_response_code()]));
            });

            \$controllerClass = {$controllerClassLiteral};
            \$controller = new \$controllerClass();
            \$controller->{$method}();
            PHP;
    }
}
