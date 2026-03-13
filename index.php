<?php
// Simple PHP frontend for downloading YouTube videos/audio using yt-dlp.
// Requires yt-dlp to be installed and available in PATH.

session_start();

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

function build_yt_dlp_command($outputTemplate, $audioOnly) {
    $commandParts = ['yt-dlp', '-o', $outputTemplate, '--no-part', '--force-overwrites'];

    if ($audioOnly) {
        $commandParts[] = '--extract-audio';
        $commandParts[] = '--audio-format';
        $commandParts[] = 'mp3';
    } else {
        $commandParts[] = '--format';
        $commandParts[] = 'mp4';
        $commandParts[] = '--no-write-subs';
        $commandParts[] = '--no-write-thumbnail';
        $commandParts[] = '--no-playlist';
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

$defaultPath = DEFAULT_SAVE_PATH;
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url = trim($_POST['url'] ?? '');
    $audioOnly = isset($_POST['audio_only']) && $_POST['audio_only'] === 'on';
    $downloadType = $_POST['download_type'] ?? 'direct';

    if ($url === '') {
        flash('Please provide a YouTube URL.', 'error');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
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

        $commandParts = build_yt_dlp_command($outputTemplate, $audioOnly);
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
        // Download to temp directory and serve to browser
        $tempDir = sys_get_temp_dir() . '/ytdl_downloads';
        if (!is_dir($tempDir) && !@mkdir($tempDir, 0755, true)) {
            flash('Could not create temporary directory for download.', 'error');
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }

        // Build yt-dlp command for temp download
        $tempDirNormalized = str_replace('\\', '/', $tempDir);
        if ($audioOnly) {
            $outputTemplate = $tempDirNormalized . '/%(title)s.mp3';
        } else {
            $outputTemplate = $tempDirNormalized . '/%(title)s.mp4';
        }
        $commandParts = build_yt_dlp_command($outputTemplate, $audioOnly);
        $commandParts[] = $url;

        // Run the command
        [$exitCode, $output] = run_yt_dlp($commandParts);

        if ($exitCode === 0) {
            // Find the downloaded file
            $files = glob($tempDir . '/*');
            if (empty($files)) {
                flash('Download completed but file not found.', 'error');
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            }

            $downloadedFile = $files[0]; // Get the first (and likely only) file

            // Serve the file for download
            $filename = basename($downloadedFile);
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($downloadedFile));
            flush();
            readfile($downloadedFile);

            // Clean up temp file
            unlink($downloadedFile);
            exit;
        } else {
            $outputText = implode("\n", $output);
            flash('Error downloading: ' . sanitize($outputText), 'error');
        }
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$flashes = get_flashes();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>YouTube Downloader (PHP)</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; padding: 2rem; max-width: 800px; margin: auto; }
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
    <h1>YouTube Downloader (PHP)</h1>

    <?php foreach ($flashes as $flash): ?>
        <div class="flash <?= sanitize($flash['type']) ?>">
            <?= sanitize($flash['message']) ?>
        </div>
    <?php endforeach; ?>

    <form method="post">
        <label>
            YouTube URL
            <input type="text" name="url" placeholder="https://www.youtube.com/watch?v=..." required />
        </label>

        <label>
            <input type="checkbox" name="audio_only" checked /> Download audio only (MP3)
        </label>

        <div style="margin-top: 1rem;">
            <button type="submit" name="download_type" value="direct">Zenon Import</button>
            <button type="submit" name="download_type" value="browser">Download auf PC</button>
        </div>

        <div class="hint" style="margin-top: 0.5rem;">
            <strong>Zenon Import:</strong> Speichert das Audio direct im Zenonbrowser in Redaktion_Temp<br>
            <strong>Download auf PC:</strong> Speichrt die Datei auf dem lokalen PC herunter
        </div>
    </form>

    <footer style="margin-top: 2rem; text-align: center; font-size: 0.8rem; color: #666;">
        Version 1.2
    </footer>

</body>
</html>
