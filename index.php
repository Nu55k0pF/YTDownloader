<?php
// Simple PHP frontend for downloading YouTube videos/audio using yt-dlp.
// Requires yt-dlp to be installed and available in PATH.

session_start();set_time_limit(0);
ignore_user_abort(true);

if (isset($_GET['download_file'])) {
    $downloadDir = get_temp_download_dir();
    $filename = basename($_GET['download_file']);
    $filePath = $downloadDir . '/' . $filename;

    if (is_file($filePath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        flush();
        readfile($filePath);
        unlink($filePath);
        exit;
    }

    flash('Requested download file could not be found.', 'error');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Default save path (UNC path used in the original Python app)
define('DEFAULT_SAVE_PATH', "\\\\PRODSERV5\\ZenonImport");

function flash($message, $type = 'info') {
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

function get_flashes() {
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function sanitize($text) {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function parse_user_timestamp($timestamp) {
    $timestamp = trim(str_replace(',', '.', $timestamp));
    if ($timestamp === '') {
        return null;
    }

    $parts = explode(':', $timestamp);
    if (count($parts) === 1) {
        if (!preg_match('/^\d+(?:\.\d+)?$/', $timestamp)) {
            return null;
        }

        $seconds = (float) $timestamp;
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds - ($hours * 3600 + $minutes * 60);
    } elseif (count($parts) === 2 || count($parts) === 3) {
        foreach ($parts as &$part) {
            $part = trim($part);
            if ($part === '') {
                return null;
            }
        }

        if (count($parts) === 2) {
            $hours = 0;
            $minutes = $parts[0];
            $seconds = $parts[1];
        } else {
            $hours = $parts[0];
            $minutes = $parts[1];
            $seconds = $parts[2];
        }

        if (!preg_match('/^\d+$/', $hours) || !preg_match('/^\d+$/', $minutes) || !preg_match('/^\d+(?:\.\d+)?$/', $seconds)) {
            return null;
        }

        $hours = (int) $hours;
        $minutes = (int) $minutes;
        $seconds = (float) $seconds;

        if ($minutes < 0 || $minutes > 59 || $seconds < 0 || $seconds >= 60) {
            return null;
        }
    } else {
        return null;
    }

    $secondsWhole = floor($seconds);
    $secondsFraction = $seconds - $secondsWhole;
    $secondsFormatted = $secondsFraction > 0
        ? sprintf('%02d.%03d', $secondsWhole, (int) round($secondsFraction * 1000))
        : sprintf('%02d', $secondsWhole);

    return sprintf('%02d:%02d:%s', $hours, $minutes, $secondsFormatted);
}

function timestamp_to_seconds($timestamp) {
    $parts = explode(':', $timestamp);
    $hours = (float) $parts[0];
    $minutes = (float) $parts[1];
    $seconds = (float) $parts[2];
    return $hours * 3600 + $minutes * 60 + $seconds;
}

function build_yt_dlp_command(
        $outputTemplate,
        $audioOnly,
        $segmentOnly,
        $startTime = '00:00:00',
        $endTime = 'inf',
        $showProgress = false) {
    $commandParts = ['yt-dlp', '-o', $outputTemplate, '--no-part', '--force-overwrites', '--no-playlist'];

    if ($audioOnly) {
        // When extracting audio, yt-dlp downloads the source video first and then converts it.
        // By default it keeps the original video file (often .webm), which can lead to both
        // an .mp3 and a .webm being present. Use --no-keep-video to remove the source file.
        $commandParts[] = '--extract-audio';
        $commandParts[] = '--audio-format';
        $commandParts[] = 'mp3';
        $commandParts[] = '--no-keep-video';
        $commandParts[] = '--no-part';
    } else {
        $commandParts[] = '--format';
        $commandParts[] = 'mp4';
        $commandParts[] = '--no-write-subs';
        $commandParts[] = '--no-write-thumbnail';
        $commandParts[] = '--no-part';
    }

    if ($segmentOnly) {
        $commandParts[] = "--download-sections";
        if ($endTime === '' || $endTime === 'inf') {
            $commandParts[] = "*$startTime-";
        } else {
            $commandParts[] = "*$startTime-$endTime";
        }
    }

    if ($showProgress) {
        $commandParts[] = '--newline';
    }

    return $commandParts;
}

function run_yt_dlp($commandParts) {
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($commandParts, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    $output = [];
    $exitCode = 1;

    if (is_resource($process)) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $output = array_filter(array_merge(explode("\n", $stdout), explode("\n", $stderr)), fn($line) => $line !== '');
    } else {
        $output[] = 'Failed to start yt-dlp process.';
    }

    return [$exitCode, $output];
}

function get_temp_download_dir() {
    $tempDir = sys_get_temp_dir() . '/ytdl_downloads';
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0755, true);
    }
    return $tempDir;
}

function initialize_progress_output() {
    if (function_exists('apache_setenv')) {
        apache_setenv('no-gzip', '1');
    }

    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');

    while (ob_get_level() > 0) {
        @ob_end_flush();
    }

    @ob_implicit_flush(true);
}

function send_progress_page_header() {
    echo <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Download progress</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; padding: 2rem; max-width: 800px; margin: auto; }
        .progress-container { width: 100%; background: #f3f4f6; border-radius: 1rem; overflow: hidden; height: 1.5rem; margin-bottom: 1rem; border: 1px solid #d1d5db; }
        .progress-bar { height: 100%; width: 0; background: #10b981; transition: width 0.2s ease; }
        .progress-status { margin-bottom: 1rem; font-size: 0.95rem; color: #111827; }
        .progress-log { font-size: 0.85rem; color: #4b5563; white-space: pre-wrap; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 0.5rem; padding: 1rem; max-height: 260px; overflow: auto; }
    </style>
    <script>
        function updateProgress(percent, message) {
            document.getElementById('progress-bar').style.width = percent + '%';
            document.getElementById('progress-label').textContent = percent + '%';
            document.getElementById('status').textContent = message;
        }

        function appendLog(message) {
            var log = document.getElementById('progress-log');
            log.textContent += message + '\n';
            log.scrollTop = log.scrollHeight;
        }
    </script>
</head>
<body>
    <h1>Download progress</h1>
    <div class="progress-status" id="status">Starting download...</div>
    <div class="progress-container"><div class="progress-bar" id="progress-bar"></div></div>
    <div class="progress-status">Progress: <span id="progress-label">0%</span></div>
    <div class="progress-log" id="progress-log"></div>
HTML;
    @flush();
}

function send_progress_update($percent, $message) {
    $message = (string)$message;
    $encodedMessage = json_encode($message, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($encodedMessage === false) {
        $encodedMessage = json_encode('Unable to render progress message', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
    echo '<script>updateProgress(' . (int)round($percent) . ',' . $encodedMessage . '); appendLog(' . $encodedMessage . ');</script>' . "\n";
    @flush();
}

function send_progress_page_footer() {
    echo '</body></html>';
    @flush();
}

function parse_yt_dlp_progress($line) {
    if (preg_match('/\[download\]\s+(\d{1,3}(?:\.\d+)?)%/', $line, $matches)) {
        return (float)$matches[1];
    }
    if (preg_match('/(?:^|\s)(\d{1,3}(?:\.\d+)?)%(?:\s|$)/', $line, $matches)) {
        return (float)$matches[1];
    }
    return null;
}

function run_yt_dlp_live($commandParts, $onLine) {
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($commandParts, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    $output = [];
    $exitCode = 1;

    if (!is_resource($process)) {
        return [1, ['Failed to start yt-dlp process.']];
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $buffers = [1 => '', 2 => ''];

    while (true) {
        $read = [$pipes[1], $pipes[2]];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, 0, 200000);

        if ($ready !== false && $ready > 0) {
            foreach ($read as $pipe) {
                $fd = array_search($pipe, $pipes, true);
                $chunk = fread($pipe, 8192);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                $buffers[$fd] .= $chunk;
                $lines = preg_split('/\r\n|\r|\n/', $buffers[$fd]);
                $buffers[$fd] = array_pop($lines);

                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if ($trimmed === '') {
                        continue;
                    }
                    $output[] = $trimmed;
                    $onLine($trimmed);
                }
            }
        }

        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }

        usleep(100000);
    }

    // Read remaining buffered output
    foreach ($pipes as $fd => $pipe) {
        if (is_resource($pipe)) {
            while (($chunk = fread($pipe, 8192)) !== false && $chunk !== '') {
                $buffers[$fd] .= $chunk;
            }
            $lines = preg_split('/\r\n|\r|\n/', $buffers[$fd]);
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }
                $output[] = $trimmed;
                $onLine($trimmed);
            }
            fclose($pipe);
        }
    }

    $exitCode = proc_close($process);
    return [$exitCode, $output];
}

$defaultPath = DEFAULT_SAVE_PATH;
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url = trim($_POST['url'] ?? '');
    $audioOnly = isset($_POST['audio_only']) && $_POST['audio_only'] === 'on';
    $downloadType = $_POST['download_type'] ?? 'direct';
    $segmentOnly = isset($_POST['segment_only']) && $_POST['segment_only'] === 'on';
    $startTime = trim($_POST['startTime'] ?? '');
    $endTime = trim($_POST['endTime'] ?? '');

    if ($url === '') {
        flash('Please provide a YouTube URL.', 'error');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if ($segmentOnly) {
        $normalizedStartTime = parse_user_timestamp($startTime);
        $normalizedEndTime = parse_user_timestamp($endTime);

        if ($normalizedStartTime === null && $normalizedEndTime === null) {
            flash('Please enter a valid start or end time for segment download.', 'error');
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }

        if ($startTime !== '' && $normalizedStartTime === null) {
            flash('Invalid start time format. Please use HH:MM:SS, MM:SS or total seconds.', 'error');
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }

        if ($endTime !== '' && $normalizedEndTime === null) {
            flash('Invalid end time format. Please use HH:MM:SS, MM:SS or total seconds.', 'error');
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }

        if ($normalizedStartTime === null) {
            $normalizedStartTime = '00:00:00';
        }

        if ($normalizedEndTime !== null && timestamp_to_seconds($normalizedEndTime) <= timestamp_to_seconds($normalizedStartTime)) {
            flash('End time must be greater than start time.', 'error');
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }

        $startTime = $normalizedStartTime;
        $endTime = $normalizedEndTime ?? '';
    }


// This section deals with downloading to preconfigured server path
    if ($downloadType === 'direct') {
        // Direct download to server
        $savePath = $defaultPath;

        // Build yt-dlp command for direct download
        if ($audioOnly) {
            $outputTemplate = $savePath . '/%(title)s.mp3';
        } else {
            $outputTemplate = $savePath . '/%(title)s.mp4';
        }

        $commandParts = build_yt_dlp_command($outputTemplate, $audioOnly, $segmentOnly, $startTime, $endTime);
        $commandParts[] = $url;

        // Run the command
        [$exitCode, $output] = run_yt_dlp($commandParts);

        if ($exitCode === 0) {
            flash('Download finished. Check the save directory for the file.', 'success');
        } else {
            $outputText = implode("\n", $output);
            flash('Error downloading: ' . sanitize($outputText), 'error');
        }


// This section deals with downloading to local machine
    } elseif ($downloadType === 'browser') {
        // Download to temp directory and show a progress page in the browser.
        $tempDir = get_temp_download_dir();
        if (!is_dir($tempDir) && !@mkdir($tempDir, 0755, true)) {
            flash('Could not create temporary directory for download.', 'error');
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }

        // Build yt-dlp command for temp download with progress output.
        $tempDirNormalized = str_replace('\\', '/', $tempDir);
        if ($audioOnly) {
            $outputTemplate = $tempDirNormalized . '/%(title)s.mp3';
        } else {
            $outputTemplate = $tempDirNormalized . '/%(title)s.mp4';
        }
        $commandParts = build_yt_dlp_command($outputTemplate, $audioOnly, $segmentOnly, $startTime, $endTime, true);
        $commandParts[] = $url;

        initialize_progress_output();
        send_progress_page_header();
        send_progress_update(0, 'Starting download...');

        [$exitCode, $output] = run_yt_dlp_live($commandParts, function ($line) {
            $progress = parse_yt_dlp_progress($line);
            if ($progress !== null) {
                send_progress_update($progress, $line);
            } else {
                send_progress_update(0, $line);
            }
        });

        if ($exitCode === 0) {
            $files = glob($tempDir . '/*');
            if (!empty($files)) {
                usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
            }

            if (empty($files)) {
                send_progress_update(100, 'Download completed but file not found.');
                send_progress_page_footer();
                exit;
            }

            $downloadedFile = $files[0];
            $filename = basename($downloadedFile);
            send_progress_update(100, 'Download complete. Preparing file...');
            echo '<script>appendLog("Opening download..."); window.location.href = "' . $_SERVER['PHP_SELF'] . '?download_file=' . rawurlencode($filename) . '";</script>';
            send_progress_page_footer();
            exit;
        }

        $outputText = implode("\n", $output);
        send_progress_update(0, 'Error downloading: ' . $outputText);
        send_progress_page_footer();
        exit;
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

//TODO: Fix timestamp download. Current code does not download the correct segment. Finde out why the timestamps are not handeld prorperly
//TODO: Make Site more pretty
//TODO: Find solution for downloading in .part file. Current code downloads directly even with the --no-part flag.

$flashes = get_flashes();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>RF - Video Downloader</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            padding: 2rem;
            max-width: 800px;
            margin: auto;
            background: #fff;
            position: relative;
            min-height: 100vh;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: url('pictures/rathaus_tag_nacht_fg_v1-copy_c_01.jpg') no-repeat center center;
            background-size: cover;
            opacity: 0.5;
            z-index: -2;
        }

        body::after {
            content: '';
            position: fixed;
            inset: 0;
            background: linear-gradient(to bottom, rgba(255,255,255,0) 0%, rgba(255,255,255,1) 70%, rgba(255,255,255,1) 100%);
            z-index: -1;
        }
        label { display: block; margin-top: 1rem; }
        input[type=text] { width: 100%; padding: 0.5rem; font-size: 1rem; }
        button { margin-top: 1rem; padding: 0.75rem 1.25rem; font-size: 1rem; }
        .flash { padding: 0.75rem 1rem; border-radius: 4px; margin-top: 1rem; }
        .flash.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .flash.success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .flash.info { background: #e0f2fe; color: #0c4a6e; border: 1px solid #7dd3fc; }
        .hint { font-size: 0.9rem; color: #555; margin-top: 0.25rem; }
    </style>
</head>
<body>
    <img src="pictures/fantasy-logo.png" alt="Logo" style="width: 250px; display: block; margin-bottom: 1rem;" />
    <h1>Radio Fantasy Video Downloader</h1>
        <div class="hint" style="margin-top: 0.5rem;">
            <strong>Anleitung:</strong> Kopiere eine URL von Youtube oder TikTok und f&#252;ge sie in das Video URL-Feld ein. Das Audio des Videos wird heruntergeladen und auf deinem PC gespeichert. <br>
            <strong>Unterst&#252;tzte Platformen:</strong> Es werden alle g&#228;ngigen Videoplattformen unterst&#252;tzt. F&#252;r eine genaue Auflistung siehe <a href="https://github.com/yt-dlp/yt-dlp/blob/master/supportedsites.md">hier</a> 
        </div>

    <?php foreach ($flashes as $flash): ?>
        <div class="flash <?= sanitize($flash['type']) ?>">
            <?= sanitize($flash['message']) ?>
        </div>
    <?php endforeach; ?>

    <form method="post">
        <label>
            Video URL
            <input type="text" name="url" placeholder="https://www.youtube.com/watch?v=..." required />
        </label>

        <label>
            <input type="checkbox" name="audio_only" checked /> Nur Audio herunterladen (MP3) <br>
            <input type="checkbox" name="segment_only" /> Nur einen bestimmten Abschnitt herunterladen <strong> Startet 10 sek vor Angegebenem Zeitpunkt </strong> 
            <input type="text" name="startTime" placeholder="00:00:00" />
            <input type="text" name="endTime" placeholder="00:01:00" /> 
        </label>

        <div style="margin-top: 1rem;">
            <!--</2><strong>ACHTUNG DER ZENON IMPORT FUNKTIONIERT NOCH NICHT RICHTIG!</strong></h2><br> -->
            <!-- <button type="submit" name="download_type" value="direct">Zenon Import</button> -->
            <button type="submit" name="download_type" value="browser">Download auf PC</button>
        </div>

        <div class="hint" style="margin-top: 0.5rem;">
            <!-- <strong>Zenon Import:</strong> Speichert das Audio direct im Zenonbrowser in Redaktion_Temp<br> -->
            <strong>Download auf PC:</strong> Speichrt die Datei auf dem lokalen PC.
        </div>
    </form>

    <footer style="margin-top: 2rem; text-align: center; font-size: 0.8rem; color: #666;">
        Version 1.4
    </footer>

</body>
</html>


