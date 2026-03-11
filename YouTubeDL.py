from pytubefix import YouTube
from pytubefix.cli import on_progress
import os


# Credits: https://dev.to/pranjol-dev/creating-a-youtube-video-downloader-with-python-a-step-by-step-guide-281f

SAVE_PATH = "//PRODSERV5/ZenonImport"

def download_video(url, save_path):
    try:
        yt = YouTube(url)
        ys = yt.streams.get_highest_resolution()
        print(f"Downloading: {yt.title}")
        ys.download(save_path)
        print("Download completed!")
    except Exception as e:
        print(f"Error: {e}")

def download_audio(url, save_path):
    try:
        yt = YouTube(url)
        ys = yt.streams.filter(only_audio=True).first()
        print(f"Downloading: {yt.title}")
        # download and provide mp3 extension
        ys.download(save_path, filename=yt.title + ".mp3")
        print("Download completed!")
    except Exception as e:
        print(f"Error: {e}")


if __name__ == "__main__":
    url = input("Enter the YouTube video URL: ")
    save_path = SAVE_PATH
    if not os.path.exists(save_path):
        save_path = input("Enter the path where the video will be saved: ")
        os.makedirs(save_path)
    download_video(url, save_path)
