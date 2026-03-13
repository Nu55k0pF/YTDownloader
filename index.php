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

$defaultPath = DEFAULT_SAVE_PATH;
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url = trim($_POST['url'] ?? '');
    $audioOnly = isset($_POST['audio_only']) && $_POST['audio_only'] === 'on';

    if ($url === '') {
        flash('Please provide a YouTube URL.', 'error');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // Direct download to server
    $savePath = $defaultPath;

    // Normalize paths for different operations
    $savePathForFS = str_replace('/', '\\', $savePath); // Backslashes for Windows filesystem operations

    // Build yt-dlp command for direct download
    if ($audioOnly) {
        $commandParts = ['yt-dlp', '-P', $savePathForFS, '-o', '%(title)s.mp3', '--extract-audio', '--audio-format', 'mp3'];
    } else {
        $commandParts = ['yt-dlp', '-P', $savePathForFS, '-o', '%(title)s.mp4', '-t', 'mp4', '--no-js-runtimes', '--no-write-subs', '--no-write-thumbnail', '--no-playlist'];
    }

    $commandParts[] = $url;

    // Run the command directly (no shell) so yt-dlp receives %(title)s literally.
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

    if ($exitCode === 0) {
        flash('Download finished. Check the save directory for the file.', 'success');
    } else {
        $outputText = implode("\n", $output);
        flash('Error downloading: ' . sanitize($outputText), 'error');
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
            <input type="checkbox" name="audio_only" /> Download audio only (MP3)
        </label>

        <div style="margin-top: 1rem;">
            <button type="submit">Download to Server</button>
        </div>

        <div class="hint" style="margin-top: 0.5rem;">
            <strong>Download to Server:</strong> Saves directly to <code>\\\\PRODSERV5\\ZenonImport</code>
        </div>
    </form>

    <p class="hint">This app uses <code>yt-dlp</code>. Install it and ensure it is available on the server PATH.</p>
</body>
</html>
