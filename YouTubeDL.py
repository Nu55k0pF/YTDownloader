from pytubefix import YouTube
from pytubefix.cli import on_progress

url = "https://www.youtube.com/watch?v=9bZkp7q19f0"

yt = YouTube(url, on_progress_callback=on_progress)
print(yt.title)