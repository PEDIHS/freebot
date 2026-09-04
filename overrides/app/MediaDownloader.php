<?php
declare(strict_types=1);

final class MediaDownloader
{
    public function __construct(
        private string $ytDlp = '/usr/local/bin/yt-dlp',
        private string $aria2 = '/usr/bin/aria2c',
        private string $ffprobe = '/usr/bin/ffprobe',
    ) {}

    public function tools(): array
    {
        return [
            'yt-dlp' => $this->toolVersion($this->ytDlp, ['--version']),
            'aria2c' => $this->toolVersion($this->aria2, ['--version']),
            'ffmpeg' => $this->toolVersion('/usr/bin/ffmpeg', ['-version']),
            'ffprobe' => $this->toolVersion($this->ffprobe, ['-version']),
            'mediainfo' => $this->toolVersion('/usr/bin/mediainfo', ['--Version']),
        ];
    }

    public function validatePublicUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('لینک معتبر نیست.');
        }
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http','https'], true)) {
            throw new RuntimeException('فقط لینک HTTP/HTTPS پشتیبانی می‌شود.');
        }
        $host = trim((string)($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost') {
            throw new RuntimeException('آدرس مقصد عمومی نیست.');
        }
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
            foreach ($records as $record) {
                if (!empty($record['ip'])) $ips[] = (string)$record['ip'];
                if (!empty($record['ipv6'])) $ips[] = (string)$record['ipv6'];
            }
        }
        if (!$ips) {
            throw new RuntimeException('DNS لینک قابل resolve نیست.');
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('لینک به IP خصوصی/رزروشده اشاره می‌کند.');
            }
        }
        return $url;
    }

    /**
     * @param callable(array):void $progress
     * @param callable():bool $cancelled
     */
    public function download(string $url, string $dir, int $jobId, int $fragments, callable $progress, callable $cancelled): array
    {
        $url = $this->validatePublicUrl($url);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('ساخت پوشه دانلود ناموفق بود.');
        }
        $fragments = max(1, min(32, $fragments));
        $base = rtrim($dir, '/') . '/job-' . $jobId . '-%(title).120B-%(id)s.%(ext)s';
        $args = [
            $this->ytDlp,
            '--no-playlist', '--no-warnings', '--newline', '--restrict-filenames',
            '--retries', '8', '--fragment-retries', '8', '--socket-timeout', '20',
            '--concurrent-fragments', (string)$fragments,
            '--merge-output-format', 'mp4',
            '-f', 'bv*+ba/b',
            '-o', $base,
            '--progress-template', 'download:FBPROGRESS|%(progress.downloaded_bytes)s|%(progress.total_bytes_estimate)s|%(progress.total_bytes)s|%(progress.speed)s|%(progress.eta)s|%(progress._percent_str)s',
            '--print', 'after_move:FBFILE|%(filepath)s',
            '--downloader', 'aria2c',
            '--downloader-args', 'aria2c:-x16 -s16 -k1M --file-allocation=none --summary-interval=1',
            $url,
        ];

        $result = $this->runProcess($args, function(string $line) use ($progress): void {
            if (str_starts_with($line, 'FBPROGRESS|')) {
                $p = explode('|', $line);
                $downloaded = (int)($p[1] ?? 0);
                $estimated = (int)($p[2] ?? 0);
                $total = (int)($p[3] ?? 0);
                $speed = (float)($p[4] ?? 0);
                $percent = trim(str_replace('%', '', (string)($p[6] ?? '0')));
                $progress([
                    'downloaded_bytes' => $downloaded,
                    'total_bytes' => max($total, $estimated),
                    'speed_bps' => max(0, (int)$speed),
                    'progress' => is_numeric($percent) ? min(100, max(0, (float)$percent)) : 0.0,
                ]);
            }
        }, $cancelled);

        $file = '';
        foreach ($result['stdout_lines'] as $line) {
            if (str_starts_with($line, 'FBFILE|')) {
                $file = trim(substr($line, 7));
            }
        }
        if ($result['exit_code'] !== 0 || $file === '' || !is_file($file)) {
            throw new RuntimeException('دانلود ناموفق بود: ' . $this->lastUsefulError($result));
        }
        $size = (int)filesize($file);
        $progress(['downloaded_bytes'=>$size,'total_bytes'=>$size,'speed_bps'=>0,'progress'=>100.0]);
        return [
            'path' => $file,
            'size' => $size,
            'mime' => $this->probeMime($file),
            'duration' => $this->probeDuration($file),
        ];
    }

    /** @return array{exit_code:int,stdout_lines:array,stderr_lines:array} */
    private function runProcess(array $args, callable $onLine, callable $cancelled): array
    {
        foreach ($args as $arg) {
            if (str_contains((string)$arg, "\0")) throw new RuntimeException('پارامتر نامعتبر برای downloader.');
        }
        $cmd = implode(' ', array_map('escapeshellarg', $args));
        $descriptors = [1=>['pipe','w'], 2=>['pipe','w']];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) throw new RuntimeException('اجرای downloader ناموفق بود.');
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = $stderr = [];
        $buffers = [1=>'',2=>''];
        try {
            while (true) {
                if ($cancelled()) {
                    proc_terminate($proc, 15);
                    usleep(250000);
                    proc_terminate($proc, 9);
                    throw new RuntimeException('عملیات توسط مدیر لغو شد.');
                }
                foreach ([1,2] as $i) {
                    $chunk = stream_get_contents($pipes[$i]);
                    if ($chunk === false || $chunk === '') continue;
                    $buffers[$i] .= $chunk;
                    while (($pos = strpos($buffers[$i], "\n")) !== false) {
                        $line = trim(substr($buffers[$i], 0, $pos));
                        $buffers[$i] = substr($buffers[$i], $pos + 1);
                        if ($line === '') continue;
                        if ($i === 1) $stdout[] = $line; else $stderr[] = $line;
                        $onLine($line);
                    }
                }
                $status = proc_get_status($proc);
                if (!$status['running']) break;
                usleep(120000);
            }
            foreach ([1,2] as $i) {
                $chunk = stream_get_contents($pipes[$i]);
                if ($chunk) $buffers[$i] .= $chunk;
                foreach (preg_split('/\r?\n/', trim($buffers[$i])) ?: [] as $line) {
                    $line = trim($line); if ($line==='') continue;
                    if ($i === 1) $stdout[]=$line; else $stderr[]=$line;
                    $onLine($line);
                }
            }
            foreach ($pipes as $pipe) fclose($pipe);
            $code = proc_close($proc);
            return ['exit_code'=>$code,'stdout_lines'=>$stdout,'stderr_lines'=>$stderr];
        } catch (Throwable $e) {
            foreach ($pipes as $pipe) if (is_resource($pipe)) @fclose($pipe);
            if (is_resource($proc)) @proc_close($proc);
            throw $e;
        }
    }

    private function lastUsefulError(array $r): string
    {
        $lines = array_values(array_filter(array_merge($r['stderr_lines'] ?? [], $r['stdout_lines'] ?? []), static fn($v)=>trim((string)$v)!==''));
        return mb_substr((string)($lines[count($lines)-1] ?? 'خطای نامشخص'), 0, 900, 'UTF-8');
    }

    private function probeDuration(string $file): float
    {
        if (!is_executable($this->ffprobe)) return 0.0;
        $cmd = escapeshellarg($this->ffprobe) . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($file) . ' 2>/dev/null';
        $v = trim((string)shell_exec($cmd));
        return is_numeric($v) ? max(0.0, (float)$v) : 0.0;
    }

    private function probeMime(string $file): string
    {
        $f = function_exists('mime_content_type') ? @mime_content_type($file) : false;
        return is_string($f) && $f !== '' ? $f : 'video/mp4';
    }

    private function toolVersion(string $bin, array $args): array
    {
        if (!is_file($bin) && !is_executable($bin)) return ['ok'=>false,'version'=>'not found'];
        $cmd = escapeshellarg($bin) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        $out = trim((string)shell_exec($cmd));
        return ['ok'=>$out!=='','version'=>mb_substr(strtok($out,"\n") ?: '',0,180,'UTF-8')];
    }
}
