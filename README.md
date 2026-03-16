# Installation

## PHP version (recommended)

1. Install PHP (>= 8.0) and ensure `php` is on your PATH.
2. Install `yt-dlp` (https://github.com/yt-dlp/yt-dlp) and ensure it is on your PATH.
3. Install `ffmpeg` (ttps://ffmpeg.org/download.html) and ensure it is on your PATH. Or put in root dir of web app. 
4. Serve the app with a PHP web server:
5. Configure "define('DEFAULT_SAVE_PATH', "\\\\Path\\to\\your\\share");
6. Make shure the webserver user has permission to read/write/change on the the DEFAULT_SAVE_PATH

## Local Server
php -S localhost:8000