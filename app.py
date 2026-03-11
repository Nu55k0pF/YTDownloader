from flask import Flask, render_template, request, redirect, url_for, flash
import os

# reuse backend logic from YouTubeDL.py
from YouTubeDL import download_video, SAVE_PATH

app = Flask(__name__)
# secret key is used by Flask to sign session cookies, flash messages, etc.
app.secret_key = "replace-with-a-secure-key"

@app.route('/', methods=["GET", "POST"])
def index():
    if request.method == "POST":
        url = request.form.get("url", "").strip()
        save_path = request.form.get("save_path", "").strip() or SAVE_PATH

        if not url:
            flash("Please provide a YouTube URL.")
            return redirect(url_for('index'))

        # ensure directory exists
        if not os.path.exists(save_path):
            try:
                os.makedirs(save_path, exist_ok=True)
            except Exception as e:
                flash(f"Could not create directory '{save_path}': {e}")
                return redirect(url_for('index'))

        # kick off download (this will run synchronously and block the request)
        try:
            download_video(url, save_path)
            flash("Download finished (see server logs for details).")
        except Exception as exc:
            flash(f"Error downloading video: {exc}")
        return redirect(url_for('index'))

    # GET
    return render_template('form.html', default_path=SAVE_PATH)

if __name__ == '__main__':
    # running in debug mode for development; remove debug=True for production
    app.run(host='0.0.0.0', port=5000, debug=True)
