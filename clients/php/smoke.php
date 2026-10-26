<?php
/**
 * Real-client smoke test using the Pheanstalk client library, the one
 * Laravel's beanstalkd queue driver uses.
 *
 * Usage: php smoke.php HOST:PORT
 *
 * Runs a full protocol flow against a (fresh) beanstalkd-compatible server,
 * asserting on every result, and prints a transcript to stdout in the line
 * formats of clients/python/smoke.py, so clients/normalize.py can mask it:
 *
 *     <step>: <result>                    one line per client call
 *     [<scope>] <key>: <value>            one line per stats field
 *
 * Pheanstalk returns stats as typed objects, so the fields come in the
 * order of their PHP properties (not the server's), with the property names
 * turned back into the protocol's (currentJobsReady -> current-jobs-ready).
 * The order is the same against both servers, which is what the diff needs.
 *
 * Only the default flow is implemented: the runner's restart, cluster
 * leader-kill and TLS modes run the Python and Go clients.
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pheanstalk\Contract\JobIdInterface;
use Pheanstalk\Exception\ClientException;
use Pheanstalk\Exception\ServerException;
use Pheanstalk\Pheanstalk;
use Pheanstalk\Values\JobId;
use Pheanstalk\Values\Timeout;
use Pheanstalk\Values\TubeName;

const TUBE_A = 'smoke-php-a';
const TUBE_B = 'smoke-php-b';
const TUBE_C = 'smoke-php-c';

function out(string $line): void
{
    fwrite(STDOUT, $line . "\n");
}

function job(JobIdInterface $j): string
{
    return 'job=' . $j->getId();
}

function jb(\Pheanstalk\Values\Job $j): string
{
    return job($j) . ' body=' . var_export($j->getData(), true);
}

function expect(string $label, mixed $got, mixed $want): void
{
    if ($got !== $want) {
        throw new RuntimeException(sprintf('%s: got %s, want %s', $label, var_export($got, true), var_export($want, true)));
    }
}

/** Calls $fn, which must throw a Pheanstalk client or server error; returns its class name. */
function errName(callable $fn): string
{
    try {
        $res = $fn();
    } catch (ClientException | ServerException $e) {
        return (new ReflectionClass($e))->getShortName();
    }
    throw new RuntimeException('expected a Pheanstalk error, got ' . var_export($res, true));
}

function kebab(string $name): string
{
    return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
}

function scalar(mixed $v): string
{
    return match (true) {
        $v instanceof BackedEnum => (string) $v->value,
        $v instanceof JobIdInterface => $v->getId(),
        $v instanceof Stringable => (string) $v,
        is_bool($v) => $v ? 'true' : 'false',
        default => (string) $v,
    };
}

function dump(string $scope, object $stats): void
{
    foreach (get_object_vars($stats) as $k => $v) {
        out(sprintf('[%s] %s: %s', $scope, kebab($k), scalar($v)));
    }
}

/** @return list<string> */
function names(iterable $tubes): array
{
    $r = [];
    foreach ($tubes as $t) {
        $r[] = (string) $t;
    }
    sort($r);
    return $r;
}

function connect(string $host, int $port): Pheanstalk
{
    // A hung server must fail the run, not hang it.
    return Pheanstalk::create($host, $port, new Timeout(10), new Timeout(10));
}

function fullFlow(string $host, int $port): void
{
    $a = new TubeName(TUBE_A);
    $b = new TubeName(TUBE_B);
    $c = new TubeName(TUBE_C);
    $default = new TubeName('default');

    // --- tubes: use / watch / ignore / list ------------------------------
    $prod = connect($host, $port);
    $prod->useTube($a);
    $prod->watch($a);
    $prod->ignore($default);
    $work = connect($host, $port);
    $work->watch($a);
    $work->watch($b);
    $work->ignore($default);
    out('producer using: ' . $prod->listTubeUsed());
    expect('producer using', (string) $prod->listTubeUsed(), TUBE_A);
    $watching = names($work->listTubesWatched());
    out('worker watching: ' . implode(', ', $watching));
    expect('worker watching', $watching, [TUBE_A, TUBE_B]);
    $n = $work->watch($c);
    out('worker watch ' . TUBE_C . ": $n");
    expect('watch count', $n, 3);
    $n = $work->ignore($c);
    out('worker ignore ' . TUBE_C . ": $n");
    expect('ignore count', $n, 2);
    $e = errName(fn () => $prod->ignore($a));
    out("producer ignore last watched tube: $e");
    expect('ignore last', $e, 'NotIgnoredException');
    $tubes = names($prod->listTubes());
    out('list-tubes: ' . implode(', ', $tubes));
    expect('list-tubes', $tubes, ['default', TUBE_A, TUBE_B]);

    // --- empty-queue results ----------------------------------------------
    $j = $work->reserveWithTimeout(0);
    out('reserve-with-timeout 0 on empty: ' . ($j === null ? 'null' : jb($j)));
    expect('reserve empty', $j, null);
    foreach (['peek-ready' => fn () => $prod->peekReady(),
              'peek-delayed' => fn () => $prod->peekDelayed(),
              'peek-buried' => fn () => $prod->peekBuried()] as $name => $fn) {
        $j = $fn();
        out("$name on empty: " . ($j === null ? 'null' : jb($j)));
        expect($name, $j, null);
    }

    // --- put with priorities; reserve returns the most urgent first -------
    $ids = [];
    foreach ([['low', 2000], ['urgent', 10], ['mid', 500], ['mid2', 500]] as [$body, $pri]) {
        $ids[$body] = $prod->put($body, $pri, 0, 60);
        out("put $body pri=$pri: " . job($ids[$body]));
    }
    $prod->useTube($b);
    $ids['b1'] = $prod->put('b1', 100, 0, 60);
    out('put b1 into ' . TUBE_B . ': ' . job($ids['b1']));

    $j = $prod->peekReady();
    out('peek-ready (' . TUBE_B . '): ' . jb($j));
    expect('peek-ready b', $j->getId(), $ids['b1']->getId());
    $prod->useTube($a);
    $j = $prod->peekReady();
    out('peek-ready (' . TUBE_A . '): ' . jb($j));
    expect('peek-ready a', $j->getId(), $ids['urgent']->getId());
    $j = $prod->peek($ids['mid']);
    out('peek mid: ' . jb($j));
    expect('peek body', $j->getData(), 'mid');
    $e = errName(fn () => $prod->peek(new JobId(999999)));
    out("peek missing: $e");
    expect('peek missing', $e, 'JobNotFoundException');

    dump('stats-tube ' . TUBE_A, $prod->statsTube($a));
    dump('stats-tube ' . TUBE_B, $prod->statsTube($b));

    $order = [];
    for ($i = 0; $i < 5; $i++) {
        // The first one is a plain `reserve` (a job is known to be ready);
        // the rest use `reserve-with-timeout`.
        $j = $i === 0 ? $work->reserve() : $work->reserveWithTimeout(1);
        $order[] = $j->getData();
        out(($i === 0 ? 'reserve' : 'reserve-with-timeout 1') . ': ' . jb($j));
        switch ($j->getData()) {
            case 'mid':
                dump('stats-job mid', $work->statsJob($j));
                $work->touch($j);
                out('touch ' . job($j) . ': ok');
                $work->release($j, 500, 2);
                out('release ' . job($j) . ' delay=2: ok');
                break;
            case 'mid2':
                $work->bury($j, 7);
                out('bury ' . job($j) . ' pri=7: ok');
                break;
            case 'low':
                $work->release($j, 3000);
                out('release ' . job($j) . ' no delay: ok');
                break;
            default:
                $work->delete($j);
                out('delete ' . job($j) . ': ok');
        }
    }
    expect('reserve order', $order, ['urgent', 'b1', 'mid', 'mid2', 'low']);
    $e = errName(fn () => $work->delete($ids['urgent']));
    out("delete already-deleted: $e");
    expect('double delete', $e, 'JobNotFoundException');

    $j = $prod->peekDelayed();
    out('peek-delayed: ' . jb($j));
    expect('peek-delayed', $j->getId(), $ids['mid']->getId());
    $j = $prod->peekBuried();
    out('peek-buried: ' . jb($j));
    expect('peek-buried', $j->getId(), $ids['mid2']->getId());
    dump('stats-job mid2', $prod->statsJob($ids['mid2']));
    dump('stats-tube ' . TUBE_A, $prod->statsTube($a));

    // kick: buried jobs are kicked first (only buried ones while any exist).
    $n = $prod->kick(10);
    out("kick 10: $n");
    expect('kick buried', $n, 1);
    $j = $work->reserveWithTimeout(3);
    out('reserve: ' . jb($j));
    expect('reserve kicked', $j->getData(), 'mid2');
    $work->delete($j);
    out('delete ' . job($j) . ': ok');
    // reserve-job takes a specific ready job regardless of priority.
    $j = $work->reserveJob($ids['low']);
    out('reserve-job low: ' . jb($j));
    $work->delete($j);
    out('delete ' . job($j) . ': ok');
    $e = errName(fn () => $work->reserveJob($ids['low']));
    out("reserve-job deleted: $e");
    expect('reserve-job deleted', $e, 'JobNotFoundException');
    $j = $work->reserveWithTimeout(5);
    out('reserve: ' . jb($j));
    expect('reserve after delay', $j->getData(), 'mid');
    $work->bury($j);
    out('bury ' . job($j) . ': ok');
    $prod->kickJob($j);
    out('kick-job ' . job($j) . ': ok');
    $e = errName(fn () => $prod->kickJob(new JobId(999999)));
    out("kick-job missing: $e");
    expect('kick-job missing', $e, 'JobNotFoundException');

    // A delayed job kicked by `kick` (no buried jobs present).
    $ids['later'] = $prod->put('later', 1, 100, 60);
    out('put later delay=100: ' . job($ids['later']));
    dump('stats-job later', $prod->statsJob($ids['later']));
    $n = $prod->kick(5);
    out("kick 5 (delayed): $n");
    expect('kick delayed', $n, 1);

    // --- reserve with timeout ----------------------------------------------
    foreach (['later', 'mid'] as $want) {
        $j = $work->reserveWithTimeout(1);
        out('reserve: ' . jb($j));
        expect('drain', $j->getData(), $want);
        $work->delete($j);
        out('delete ' . job($j) . ': ok');
    }
    $j = $work->reserveWithTimeout(1);
    out('reserve-with-timeout 1 on empty: ' . ($j === null ? 'null' : jb($j)));
    expect('reserve timeout', $j, null);

    // --- pause-tube ----------------------------------------------------------
    $prod->useTube($b);
    $ids['p'] = $prod->put('paused', 1024, 0, 60);
    out('put paused into ' . TUBE_B . ': ' . job($ids['p']));
    $prod->pauseTube($b, 1);
    out('pause-tube ' . TUBE_B . ' 1: ok');
    $st = $prod->statsTube($b);
    dump('stats-tube ' . TUBE_B, $st);
    expect('pause', $st->pause, 1);
    $j = $work->reserveWithTimeout(0);
    out('reserve-with-timeout 0 while paused: ' . ($j === null ? 'null' : jb($j)));
    expect('paused reserve', $j, null);
    $j = $work->reserveWithTimeout(3);
    out('reserve after pause expiry: ' . jb($j));
    expect('after pause', $j->getId(), $ids['p']->getId());
    $work->delete($j);
    out('delete ' . job($j) . ': ok');
    $e = errName(fn () => $prod->pauseTube(new TubeName('no-such-tube'), 1));
    out("pause-tube missing: $e");
    expect('pause missing', $e, 'TubeNotFoundException');

    // --- final stats -----------------------------------------------------------
    $st = $prod->stats();
    dump('stats', $st);
    expect('current-jobs-ready', $st->currentJobsReady, 0);
    expect('current-jobs-reserved', $st->currentJobsReserved, 0);
    expect('current-jobs-delayed', $st->currentJobsDelayed, 0);
    expect('current-jobs-buried', $st->currentJobsBuried, 0);
    expect('total-jobs', $st->totalJobs, 7);

    $work->disconnect();
    $prod->disconnect();
    out('DONE');
}

function main(array $argv): int
{
    $args = array_slice($argv, 1);
    if (count($args) !== 1 || !str_contains($args[0], ':')) {
        fwrite(STDERR, "usage: php smoke.php HOST:PORT\n");
        return 2;
    }
    $i = strrpos($args[0], ':');
    fullFlow(substr($args[0], 0, $i), (int) substr($args[0], $i + 1));
    return 0;
}

exit(main($argv));
