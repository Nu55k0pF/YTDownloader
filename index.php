<?php
// Simple PHP frontend for downloading YouTube videos/audio using yt-dlp.
// Requires yt-dlp to be installed and available in PATH.

session_start();

// Default save path (UNC path used in the original Python app)
define('DEFAULT_SAVE_PATH', '\\PRODSERV5\\ZenonImport');

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
    $savePath = trim($_POST['save_path'] ?? '');
    $audioOnly = isset($_POST['audio_only']) && $_POST['audio_only'] === 'on';

    if ($savePath === '') {
        $savePath = $defaultPath;
    }

    if ($url === '') {
        flash('Please provide a YouTube URL.', 'error');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // Ensure directory exists.
    if (!is_dir($savePath)) {
        if (!@mkdir($savePath, 0777, true)) {
            flash("Could not create directory '$savePath'.", 'error');
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }

    // Build yt-dlp command.
    $outputTemplate = rtrim($savePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '%(title)s.%(ext)s';
    $commandParts = ['yt-dlp', '-o', $outputTemplate];

    if ($audioOnly) {
        $commandParts[] = '--extract-audio';
        $commandParts[] = '--audio-format';
        $commandParts[] = 'mp3';
    }

    $commandParts[] = $url;

    // Escape each argument.
    $command = implode(' ', array_map('escapeshellarg', $commandParts));

    // Run the command.
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);

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
            Save path
            <input type="text" name="save_path" value="<?= sanitize($defaultPath) ?>" />
            <div class="hint">Leave empty to use default path. The server must have write access.</div>
        </label>

        <label>
            <input type="checkbox" name="audio_only" /> Download audio only (MP3)
        </label>

        <button type="submit">Download</button>
    </form>

    <p class="hint">This app uses <code>yt-dlp</code>. Install it and ensure it is available on the server PATH.</p>
</body>
</html>
