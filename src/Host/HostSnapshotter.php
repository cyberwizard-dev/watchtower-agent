<?php

namespace Cyberwizard\WatchtowerAgent\Host;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Symfony\Component\Process\Process;
use Throwable;

class HostSnapshotter
{
    public const MAX_DISKS = 10;

    public const MAX_TOP = 15;

    private const TOP_EACH = 10;

    private const CLOCK_TICKS = 100;

    private const PAGE_SIZE = 4096;

    private const SCAN_BUDGET_SECONDS = 1.0;

    public ?Closure $pause = null;

    public function __construct(private Repository $config) {}

    public function snapshot(): array
    {
        $root = $this->procRoot();
        $scope = $root === '/proc' ? 'container' : 'host';

        if ($this->readable($root.'/meminfo') || $this->readable($root.'/stat')) {
            $snapshot = $this->linux($root, $scope);
        } else {
            $snapshot = $this->fallback('host');
        }

        $snapshot['disks'] = $this->safely(fn (): array => $this->disks(), []);

        return [
            'v' => 1,
            'scope' => $snapshot['scope'],
            'os' => $snapshot['os'],
            'hostname' => $snapshot['hostname'],
            'uptime_seconds' => $snapshot['uptime_seconds'],
            'cpu' => $snapshot['cpu'],
            'memory' => $snapshot['memory'],
            'disks' => $snapshot['disks'],
            'processes' => $snapshot['processes'],
        ];
    }

    private function linux(string $root, string $scope): array
    {
        $started = microtime(true);

        $first = $this->safely(fn (): ?array => $this->cpuTimes($root));
        $processes = $this->safely(fn (): array => $this->scanProcesses($root, $started), ['total' => null, 'pids' => []]);

        $this->wait();

        $later = $this->procRoot();
        $second = $this->safely(fn (): ?array => $this->cpuTimes($later));

        foreach ($processes['pids'] as $pid => $process) {
            $ticks = $this->safely(fn (): ?array => $this->pidStat($later, $pid));
            $processes['pids'][$pid]['ticks_later'] = $ticks['ticks'] ?? null;
        }

        $meminfo = $this->safely(fn (): array => $this->meminfo($root), []);
        $memory = $this->memoryFromMeminfo($meminfo);
        $cores = $second['cores'] ?? $first['cores'] ?? $this->coresFromCpuinfo($root);
        [$load1, $load5, $load15] = $this->safely(fn (): array => $this->loadavg($root), [null, null, null]);

        return [
            'scope' => $scope,
            'os' => $this->safely(fn (): ?string => $this->firstLine($root.'/sys/kernel/ostype')) ?? 'Linux',
            'hostname' => $this->hostname($scope === 'host' ? $root : null),
            'uptime_seconds' => $this->safely(fn (): ?int => $this->uptime($root)),
            'cpu' => [
                'cores' => $cores,
                'load_1' => $load1,
                'load_5' => $load5,
                'load_15' => $load15,
                'usage_percent' => $this->usage($first, $second),
            ],
            'memory' => $memory + $this->safely(fn (): array => $this->cgroup($memory['total_bytes']), [
                'container_limit_bytes' => null,
                'container_used_bytes' => null,
            ]),
            'processes' => $this->safely(fn (): array => $this->linuxTop($root, $scope, $processes, $first, $second, $cores, $memory['total_bytes']), [
                'total' => $processes['total'],
                'top' => [],
            ]),
        ];
    }

    private function cpuTimes(string $root): ?array
    {
        $stat = $this->read($root.'/stat');

        if ($stat === null) {
            return null;
        }

        $total = null;
        $idle = null;
        $cores = 0;
        $boot = null;

        foreach (explode("\n", $stat) as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];

            if (($parts[0] ?? '') === 'cpu') {
                $values = array_map('intval', array_slice($parts, 1, 8));
                $total = array_sum($values);
                $idle = ($values[3] ?? 0) + ($values[4] ?? 0);
            } elseif (preg_match('/^cpu\d+$/', $parts[0] ?? '') === 1) {
                $cores++;
            } elseif (($parts[0] ?? '') === 'btime' && isset($parts[1])) {
                $boot = (int) $parts[1];
            }
        }

        if ($total === null) {
            return null;
        }

        return ['total' => $total, 'idle' => $idle, 'cores' => $cores > 0 ? $cores : null, 'boot' => $boot];
    }

    private function usage(?array $first, ?array $second): ?float
    {
        if ($first === null || $second === null) {
            return null;
        }

        $total = $second['total'] - $first['total'];

        if ($total <= 0) {
            return null;
        }

        $busy = $total - ($second['idle'] - $first['idle']);

        return round(max(0.0, min(100.0, $busy / $total * 100)), 1);
    }

    private function scanProcesses(string $root, float $started): array
    {
        $entries = @scandir($root);

        if ($entries === false) {
            return ['total' => null, 'pids' => []];
        }

        $pids = [];
        $total = 0;
        $limit = max(1, (int) $this->config->get('watchtower-agent.host.max_processes', 2000));

        foreach ($entries as $entry) {
            if (! ctype_digit($entry)) {
                continue;
            }

            $total++;

            if (count($pids) >= $limit || microtime(true) - $started > self::SCAN_BUDGET_SECONDS) {
                continue;
            }

            $stat = $this->safely(fn (): ?array => $this->pidStat($root, (int) $entry));

            if ($stat !== null) {
                $pids[(int) $entry] = $stat;
            }
        }

        return ['total' => $total, 'pids' => $pids];
    }

    private function pidStat(string $root, int $pid): ?array
    {
        $stat = $this->read("{$root}/{$pid}/stat");

        if ($stat === null) {
            return null;
        }

        $open = strpos($stat, '(');
        $close = strrpos($stat, ')');

        if ($open === false || $close === false || $close < $open) {
            return null;
        }

        $fields = preg_split('/\s+/', trim(substr($stat, $close + 1))) ?: [];

        if (count($fields) < 22) {
            return null;
        }

        return [
            'name' => substr($stat, $open + 1, $close - $open - 1),
            'ticks' => (int) $fields[11] + (int) $fields[12],
            'start' => (int) $fields[19],
            'rss' => (int) $fields[21] * self::PAGE_SIZE,
        ];
    }

    private function linuxTop(string $root, string $scope, array $processes, ?array $first, ?array $second, ?int $cores, ?int $memoryTotal): array
    {
        $elapsed = null;

        if ($first !== null && $second !== null && $second['total'] > $first['total']) {
            $elapsed = ($second['total'] - $first['total']) / max(1, $cores ?? 1);
        }

        $rows = [];

        foreach ($processes['pids'] as $pid => $process) {
            $cpu = null;

            if ($elapsed !== null && $process['ticks_later'] !== null) {
                $cpu = round(max(0, $process['ticks_later'] - $process['ticks']) / $elapsed * 100, 1);
            }

            $rows[$pid] = ['pid' => $pid, 'cpu' => $cpu, 'rss' => $process['rss']] + $process;
        }

        $selected = $this->selectTop($rows);
        $boot = $second['boot'] ?? $first['boot'] ?? null;
        $users = $this->safely(fn (): array => $this->passwd(), []);
        $top = [];

        foreach ($selected as $row) {
            $pid = $row['pid'];
            $status = $this->safely(fn (): array => $this->status($root, $pid), []);
            $memory = $status['rss'] ?? $row['rss'];
            $uid = $status['uid'] ?? null;
            $cmdline = $this->safely(fn (): ?string => $this->read("{$root}/{$pid}/cmdline"));
            $name = CommandLine::clean($row['name']);
            $command = $cmdline !== null && trim(str_replace("\0", ' ', $cmdline)) !== ''
                ? str_replace("\0", ' ', rtrim($cmdline, "\0"))
                : "[{$name}]";

            $top[] = [
                'pid' => $pid,
                'user' => $uid === null ? null : $this->user($uid, $users, $scope),
                'name' => $name,
                'command' => CommandLine::redact($command),
                'cpu_percent' => $row['cpu'],
                'memory_bytes' => $memory,
                'memory_percent' => $memory !== null && $memoryTotal ? round($memory / $memoryTotal * 100, 2) : null,
                'started_at' => $boot !== null ? (float) ($boot + intdiv($row['start'], self::CLOCK_TICKS)) + ($row['start'] % self::CLOCK_TICKS) / self::CLOCK_TICKS : null,
            ];
        }

        return ['total' => $processes['total'], 'top' => $top];
    }

    private function selectTop(array $rows): array
    {
        $byCpu = $rows;
        uasort($byCpu, fn (array $a, array $b): int => [$b['cpu'] ?? -1, $b['rss'] ?? -1] <=> [$a['cpu'] ?? -1, $a['rss'] ?? -1]);

        $byMemory = $rows;
        uasort($byMemory, fn (array $a, array $b): int => [$b['rss'] ?? -1, $b['cpu'] ?? -1] <=> [$a['rss'] ?? -1, $a['cpu'] ?? -1]);

        $selected = array_slice($byCpu, 0, self::TOP_EACH, true) + array_slice($byMemory, 0, self::TOP_EACH, true);

        uasort($selected, fn (array $a, array $b): int => [$b['cpu'] ?? -1, $b['rss'] ?? -1, $a['pid']] <=> [$a['cpu'] ?? -1, $a['rss'] ?? -1, $b['pid']]);

        return array_values(array_slice($selected, 0, self::MAX_TOP, true));
    }

    private function status(string $root, int $pid): array
    {
        $status = $this->read("{$root}/{$pid}/status");

        if ($status === null) {
            return [];
        }

        $result = [];

        if (preg_match('/^VmRSS:\s+(\d+)\s*kB/mi', $status, $match) === 1) {
            $result['rss'] = (int) $match[1] * 1024;
        }

        if (preg_match('/^Uid:\s+(\d+)/m', $status, $match) === 1) {
            $result['uid'] = (int) $match[1];
        }

        return $result;
    }

    private function passwd(): array
    {
        $path = (string) $this->config->get('watchtower-agent.host.passwd_path', '/etc/passwd');
        $content = $this->read($path);

        if ($content === null) {
            return [];
        }

        $users = [];

        foreach (explode("\n", $content) as $line) {
            $parts = explode(':', $line);

            if (count($parts) >= 3 && ctype_digit($parts[2]) && ! isset($users[(int) $parts[2]])) {
                $users[(int) $parts[2]] = $parts[0];
            }
        }

        return $users;
    }

    private function user(int $uid, array $users, string $scope): ?string
    {
        if (isset($users[$uid])) {
            return CommandLine::clean($users[$uid]);
        }

        if ($scope === 'container' && function_exists('posix_getpwuid')) {
            $entry = $this->safely(fn (): mixed => @posix_getpwuid($uid));

            if (is_array($entry) && isset($entry['name']) && is_string($entry['name'])) {
                return CommandLine::clean($entry['name']);
            }
        }

        return null;
    }

    private function meminfo(string $root): array
    {
        $content = $this->read($root.'/meminfo');

        if ($content === null) {
            return [];
        }

        $values = [];

        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^(\w+(?:\(\w+\))?):\s+(\d+)(?:\s*kB)?/i', $line, $match) === 1) {
                $values[$match[1]] = (int) $match[2] * 1024;
            }
        }

        return $values;
    }

    private function memoryFromMeminfo(array $values): array
    {
        $total = $values['MemTotal'] ?? null;
        $available = $values['MemAvailable'] ?? null;

        if ($available === null && isset($values['MemFree'])) {
            $available = $values['MemFree'] + ($values['Buffers'] ?? 0) + ($values['Cached'] ?? 0);
        }

        $swapTotal = $values['SwapTotal'] ?? null;
        $swapFree = $values['SwapFree'] ?? null;

        return [
            'total_bytes' => $total,
            'used_bytes' => $total !== null && $available !== null ? max(0, $total - $available) : null,
            'available_bytes' => $available,
            'swap_total_bytes' => $swapTotal,
            'swap_used_bytes' => $swapTotal !== null && $swapFree !== null ? max(0, $swapTotal - $swapFree) : null,
        ];
    }

    private function cgroup(?int $hostTotal): array
    {
        $base = rtrim((string) $this->config->get('watchtower-agent.host.cgroup_path', '/sys/fs/cgroup'), '/');
        $limit = null;
        $used = null;

        $max = $this->firstLine($base.'/memory.max');

        if ($max !== null) {
            $limit = ctype_digit($max) ? (int) $max : null;
            $current = $this->firstLine($base.'/memory.current');
            $used = $current !== null && ctype_digit($current) ? (int) $current : null;
        } else {
            $v1 = $this->firstLine($base.'/memory/memory.limit_in_bytes');

            if ($v1 !== null) {
                $limit = ctype_digit($v1) ? (int) $v1 : null;
                $usage = $this->firstLine($base.'/memory/memory.usage_in_bytes');
                $used = $usage !== null && ctype_digit($usage) ? (int) $usage : null;
            }
        }

        if ($limit === null || $limit <= 0 || $limit >= 1 << 60 || ($hostTotal !== null && $limit >= $hostTotal)) {
            return ['container_limit_bytes' => null, 'container_used_bytes' => null];
        }

        return ['container_limit_bytes' => $limit, 'container_used_bytes' => $used];
    }

    private function loadavg(string $root): array
    {
        $content = $this->firstLine($root.'/loadavg');

        if ($content !== null) {
            $parts = preg_split('/\s+/', $content) ?: [];

            if (count($parts) >= 3 && is_numeric($parts[0]) && is_numeric($parts[1]) && is_numeric($parts[2])) {
                return [(float) $parts[0], (float) $parts[1], (float) $parts[2]];
            }
        }

        return $this->phpLoad();
    }

    private function phpLoad(): array
    {
        if (! function_exists('sys_getloadavg')) {
            return [null, null, null];
        }

        $load = @sys_getloadavg();

        if (! is_array($load) || count($load) < 3) {
            return [null, null, null];
        }

        return [round((float) $load[0], 2), round((float) $load[1], 2), round((float) $load[2], 2)];
    }

    private function uptime(string $root): ?int
    {
        $content = $this->firstLine($root.'/uptime');

        if ($content === null) {
            return null;
        }

        $first = explode(' ', $content)[0];

        return is_numeric($first) ? (int) $first : null;
    }

    private function coresFromCpuinfo(string $root): ?int
    {
        $content = $this->safely(fn (): ?string => $this->read($root.'/cpuinfo'));

        if ($content === null) {
            return null;
        }

        $count = preg_match_all('/^processor\s*:/m', $content);

        return $count > 0 ? $count : null;
    }

    private function hostname(?string $root): string
    {
        if ($root !== null) {
            $name = $this->safely(fn (): ?string => $this->firstLine($root.'/sys/kernel/hostname'));

            if ($name !== null && $name !== '') {
                return CommandLine::clean($name);
            }
        }

        $name = $this->safely(fn (): string|false => gethostname(), false);

        return is_string($name) ? CommandLine::clean($name) : '';
    }

    private function disks(): array
    {
        $paths = $this->config->get('watchtower-agent.host.disks');
        $paths = is_array($paths) && $paths !== [] ? $paths : ['/', storage_path()];
        $disks = [];
        $seen = [];

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '' || count($disks) >= self::MAX_DISKS) {
                continue;
            }

            $total = $this->safely(fn (): float|false => @disk_total_space($path), false);
            $free = $this->safely(fn (): float|false => @disk_free_space($path), false);

            if (! is_float($total) || ! is_float($free) || $total <= 0) {
                continue;
            }

            $key = ((int) $total).':'.((int) $free);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $disks[] = [
                'mount' => $path,
                'total_bytes' => (int) $total,
                'used_bytes' => max(0, (int) $total - (int) $free),
                'free_bytes' => (int) $free,
            ];
        }

        return $disks;
    }

    private function fallback(string $scope): array
    {
        $sysctl = $this->safely(fn (): array => $this->sysctl(), []);
        $cores = isset($sysctl['hw.ncpu']) && ctype_digit($sysctl['hw.ncpu']) ? (int) $sysctl['hw.ncpu'] : null;
        $total = isset($sysctl['hw.memsize']) && ctype_digit($sysctl['hw.memsize']) ? (int) $sysctl['hw.memsize'] : null;
        [$load1, $load5, $load15] = $this->safely(fn (): array => $this->phpLoad(), [null, null, null]);
        $available = $this->safely(fn (): ?int => $this->vmStatAvailable());
        [$swapTotal, $swapUsed] = $this->safely(fn (): array => $this->swapUsage($sysctl['vm.swapusage'] ?? null), [null, null]);
        $uptime = null;

        if (isset($sysctl['kern.boottime']) && preg_match('/sec\s*=\s*(\d+)/', $sysctl['kern.boottime'], $match) === 1) {
            $uptime = max(0, time() - (int) $match[1]);
        }

        $processes = $this->safely(fn (): array => $this->psProcesses($total), ['total' => null, 'top' => [], 'cpu_sum' => null]);
        $usage = $processes['cpu_sum'] !== null && $cores ? round(min(100.0, $processes['cpu_sum'] / $cores), 1) : null;

        return [
            'scope' => $scope,
            'os' => PHP_OS_FAMILY,
            'hostname' => $this->hostname(null),
            'uptime_seconds' => $uptime,
            'cpu' => [
                'cores' => $cores,
                'load_1' => $load1,
                'load_5' => $load5,
                'load_15' => $load15,
                'usage_percent' => $usage,
            ],
            'memory' => [
                'total_bytes' => $total,
                'used_bytes' => $total !== null && $available !== null ? max(0, $total - $available) : null,
                'available_bytes' => $available,
                'swap_total_bytes' => $swapTotal,
                'swap_used_bytes' => $swapUsed,
                'container_limit_bytes' => null,
                'container_used_bytes' => null,
            ],
            'processes' => ['total' => $processes['total'], 'top' => $processes['top']],
        ];
    }

    private function sysctl(): array
    {
        $output = $this->run(['sysctl', 'hw.ncpu', 'hw.memsize', 'vm.swapusage', 'kern.boottime']);

        if ($output === null) {
            return [];
        }

        $values = [];

        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^([\w.]+)\s*[:=]\s*(.*)$/', trim($line), $match) === 1) {
                $values[$match[1]] = trim($match[2]);
            }
        }

        return $values;
    }

    private function vmStatAvailable(): ?int
    {
        $output = $this->run(['vm_stat']);

        if ($output === null || preg_match('/page size of (\d+) bytes/', $output, $match) !== 1) {
            return null;
        }

        $page = (int) $match[1];
        $pages = 0;
        $found = false;

        foreach (['Pages free', 'Pages inactive', 'Pages speculative', 'Pages purgeable'] as $label) {
            if (preg_match('/^'.preg_quote($label, '/').':\s+(\d+)/m', $output, $count) === 1) {
                $pages += (int) $count[1];
                $found = true;
            }
        }

        return $found ? $pages * $page : null;
    }

    private function swapUsage(?string $value): array
    {
        if ($value === null || preg_match('/total\s*=\s*([\d.]+)([KMGT]?)\s+used\s*=\s*([\d.]+)([KMGT]?)/i', $value, $match) !== 1) {
            return [null, null];
        }

        return [$this->units((float) $match[1], $match[2]), $this->units((float) $match[3], $match[4])];
    }

    private function units(float $value, string $unit): int
    {
        $power = ['' => 0, 'K' => 1, 'M' => 2, 'G' => 3, 'T' => 4][strtoupper($unit)] ?? 0;

        return (int) round($value * (1024 ** $power));
    }

    private function psProcesses(?int $memoryTotal): array
    {
        $output = $this->run(['ps', '-axww', '-o', 'pid=,uid=,user=,pcpu=,rss=,etime=,comm=']);

        if ($output === null) {
            return ['total' => null, 'top' => [], 'cpu_sum' => null];
        }

        $rows = [];
        $sum = 0.0;
        $limit = max(1, (int) $this->config->get('watchtower-agent.host.max_processes', 2000));
        $total = 0;

        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s+(\S+)\s+([\d.]+)\s+(\d+)\s+(\S+)\s+(.+)$/', $line, $match) !== 1) {
                continue;
            }

            $total++;
            $sum += (float) $match[4];

            if (count($rows) >= $limit) {
                continue;
            }

            $pid = (int) $match[1];
            $rows[$pid] = [
                'pid' => $pid,
                'user' => $match[3],
                'cpu' => round((float) $match[4], 1),
                'rss' => (int) $match[5] * 1024,
                'elapsed' => $this->elapsed($match[6]),
                'name' => basename(trim($match[7])),
            ];
        }

        $selected = $this->selectTop($rows);
        $commands = $selected === [] ? [] : $this->safely(fn (): array => $this->psCommands(array_column($selected, 'pid')), []);
        $now = microtime(true);
        $top = [];

        foreach ($selected as $row) {
            $top[] = [
                'pid' => $row['pid'],
                'user' => CommandLine::clean($row['user']),
                'name' => CommandLine::clean($row['name']),
                'command' => CommandLine::redact($commands[$row['pid']] ?? $row['name']),
                'cpu_percent' => $row['cpu'],
                'memory_bytes' => $row['rss'],
                'memory_percent' => $memoryTotal ? round($row['rss'] / $memoryTotal * 100, 2) : null,
                'started_at' => $row['elapsed'] !== null ? (float) floor($now - $row['elapsed']) : null,
            ];
        }

        return ['total' => $total, 'top' => $top, 'cpu_sum' => $sum];
    }

    private function psCommands(array $pids): array
    {
        $output = $this->run(['ps', '-ww', '-o', 'pid=,args=', '-p', implode(',', $pids)]);

        if ($output === null) {
            return [];
        }

        $commands = [];

        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\s*(\d+)\s+(.*)$/', $line, $match) === 1) {
                $commands[(int) $match[1]] = $match[2];
            }
        }

        return $commands;
    }

    private function elapsed(string $value): ?int
    {
        if (preg_match('/^(?:(\d+)-)?(?:(\d+):)?(\d+):(\d+)$/', $value, $match) !== 1) {
            return null;
        }

        return (int) ($match[1] ?: 0) * 86400 + (int) ($match[2] ?: 0) * 3600 + (int) $match[3] * 60 + (int) $match[4];
    }

    private function run(array $command): ?string
    {
        if (! class_exists(Process::class)) {
            return null;
        }

        return $this->safely(function () use ($command): ?string {
            $process = new Process($command, null, ['LC_ALL' => 'C']);
            $process->setTimeout(1.0);
            $process->run();

            return $process->isSuccessful() ? $process->getOutput() : null;
        });
    }

    private function wait(): void
    {
        if ($this->pause !== null) {
            $this->safely($this->pause);

            return;
        }

        $milliseconds = max(0, min(1000, (int) $this->config->get('watchtower-agent.host.sample_ms', 500)));

        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    private function procRoot(): string
    {
        $path = $this->config->get('watchtower-agent.host.proc_path', '/proc');
        $path = is_string($path) && trim($path) !== '' ? rtrim(trim($path), '/') : '/proc';

        return $path === '' ? '/proc' : $path;
    }

    private function readable(string $path): bool
    {
        return (bool) $this->safely(fn (): bool => @is_readable($path), false);
    }

    private function read(string $path): ?string
    {
        if (! $this->readable($path)) {
            return null;
        }

        $content = $this->safely(fn (): string|false => @file_get_contents($path, false, null, 0, 1 << 20), false);

        return is_string($content) ? $content : null;
    }

    private function firstLine(string $path): ?string
    {
        $content = $this->read($path);

        if ($content === null) {
            return null;
        }

        $line = trim(strtok($content, "\n") ?: '');

        return $line === '' ? null : $line;
    }

    private function safely(Closure $callback, mixed $default = null): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return $default;
        }
    }
}
