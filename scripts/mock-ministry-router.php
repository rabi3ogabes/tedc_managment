<?php

/*
 * Router of `php artisan tedc:mock-ministry` (served by `php -S`): a stand-in for a Ministry system that answers the operations of its
 * contract from the recorded fixtures. It does not start the application and holds no data: only the contract kit and the shipped files.
 * Settings come from the environment the command sets (TEDC_MOCK_SYSTEM, TEDC_MOCK_DIR, TEDC_MOCK_SCENARIO, TEDC_MOCK_KEY, TEDC_MOCK_SETTINGS).
 */

require __DIR__.'/../vendor/autoload.php';

use App\Integrations\Contracts\MockMinistry;

$mock = MockMinistry::fromDirectory((string) getenv('TEDC_MOCK_DIR'), (string) getenv('TEDC_MOCK_SYSTEM'), (string) (getenv('TEDC_MOCK_SCENARIO') ?: 'ok'), getenv('TEDC_MOCK_KEY') ?: null)
    ->withSettings((array) json_decode((string) (getenv('TEDC_MOCK_SETTINGS') ?: '{}'), true));

$headers = [];
foreach (function_exists('getallheaders') ? getallheaders() : [] as $name => $value) {
    $headers[strtolower((string) $name)] = (string) $value;
}
parse_str((string) ($_SERVER['QUERY_STRING'] ?? ''), $query);
$answer = $mock->respond((string) $_SERVER['REQUEST_METHOD'], (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH), $query, $headers, (string) file_get_contents('php://input'));

if ($answer['delay_ms'] > 0) {
    usleep(min(30_000, $answer['delay_ms']) * 1000);
}
http_response_code($answer['status']);
foreach ($answer['headers'] as $name => $value) {
    header($name.': '.$value);
}
echo $answer['body'];
