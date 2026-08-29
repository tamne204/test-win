"""
app.py
Flask web server for the FFmpeg Slideshow Builder.
Handles file uploads, background rendering, SSE progress, download,
VoxCPM2 Text-to-Speech generation, and AutoSub subtitle recognition & embedding.
"""

import os
import sys
os.environ["HF_HUB_DISABLE_SYMLINKS_WARNING"] = "1"
import time
import re
import datetime
import uuid
import json
import tempfile
import threading
import queue
import subprocess
from pathlib import Path
from flask import (
    Flask, request, render_template, jsonify,
    send_file, Response, stream_with_context
)

import ffmpeg_utils
from ffmpeg_utils import (
    render_video, sort_images,
    is_supported_image, is_supported_audio,
    check_ffmpeg, normalize_weights, build_resolution
)
from tts_utils import check_edge_tts, check_voxcpm, detect_device, generate_tts, CURATED_VOICES
import subtitles_engine
import translation_utils
import license_manager
from version import __version__, APP_NAME
from updater import UpdateManager, check_for_updates

def sanitize_project_id(pid: str) -> str:
    """Sanitize project_id to prevent path traversal attacks (BUG-04)."""
    if not pid:
        return ""
    clean = re.sub(r'[^A-Za-z0-9_\-]', '', str(pid).strip())
    return clean

# Initialize and verify license on startup
license_manager.verify_license()
license_manager.start_heartbeat(interval_seconds=1800)

# Initialize Update Manager
update_mgr = UpdateManager()

# ---------------------------------------------------------------------------
# App setup
# ---------------------------------------------------------------------------

app = Flask(__name__)
app.config['MAX_CONTENT_LENGTH'] = 4 * 1024 * 1024 * 1024  # 4 GB max upload

UPLOAD_DIR = Path('uploads')
OUTPUT_DIR = Path('outputs')
TTS_DIR    = Path('tts_outputs')
PROJECTS_DIR = Path('projects')
UPLOAD_DIR.mkdir(exist_ok=True)
OUTPUT_DIR.mkdir(exist_ok=True)
TTS_DIR.mkdir(exist_ok=True)
PROJECTS_DIR.mkdir(exist_ok=True)

# In-memory job store: job_id → job dict
jobs: dict = {}

FFMPEG_AVAILABLE  = check_ffmpeg()
EDGE_TTS_AVAILABLE = check_edge_tts()
TTS_DEVICE        = detect_device() if EDGE_TTS_AVAILABLE else "N/A"

# ---------------------------------------------------------------------------
# License Management APIs
# ---------------------------------------------------------------------------

@app.route('/api/license/status')
def license_status():
    return jsonify(license_manager.get_status())

@app.route('/api/license/activate', methods=['POST'])
def license_activate():
    data = request.get_json(force=True, silent=True) or {}
    key = data.get('license_key', '').strip()
    if not key:
        return jsonify({'ok': False, 'message': 'Vui lòng nhập mã bản quyền (License Key).'}), 400
    res = license_manager.activate_license(key)
    return jsonify(res)

@app.route('/api/license/deactivate', methods=['POST'])
def license_deactivate():
    license_manager.clear_license()
    return jsonify(license_manager.verify_license())


# ---------------------------------------------------------------------------
# Auto-Update APIs
# ---------------------------------------------------------------------------

@app.route('/api/update/check')
def api_update_check():
    """Check for new release on GitHub / Proxy."""
    res = check_for_updates()
    return jsonify(res)

@app.route('/api/update/download', methods=['POST'])
def api_update_download():
    """Download update archive and stage it."""
    data = request.get_json(force=True, silent=True) or {}
    download_url = data.get('download_url', '').strip()
    expected_sha256 = data.get('sha256', '').strip() or None

    if not download_url:
        return jsonify({'ok': False, 'message': 'Thiếu đường dẫn download_url.'}), 400

    try:
        zip_path = update_mgr.download_update(download_url)
        staging_dir = update_mgr.verify_and_stage_update(zip_path, expected_sha256)
        return jsonify({
            'ok': True,
            'message': 'Đã tải và giải nén bản cập nhật vào staging thành công!',
            'staging_dir': staging_dir
        })
    except Exception as e:
        return jsonify({'ok': False, 'message': f'Lỗi khi tải hoặc xác thực bản cập nhật: {e}'}), 500

def restart_server():
    """Detached restart supporting Windows, macOS, and Linux without port collisions."""
    def _worker():
        time.sleep(0.5)
        try:
            python_bin = sys.executable
            app_script = os.path.abspath(__file__)
            app_dir = os.path.dirname(app_script)

            if sys.platform == 'win32':
                # Create a temporary standalone restart script that waits for port 8080 to be released
                bat_path = os.path.join(tempfile.gettempdir(), f"restart_{os.getpid()}.bat")
                with open(bat_path, "w", encoding="utf-8") as f:
                    f.write("@echo off\r\n")
                    f.write("timeout /t 2 /nobreak >nul\r\n")
                    f.write(f'cd /d "{app_dir}"\r\n')
                    f.write(f'start "" "{python_bin}" "{app_script}"\r\n')
                    f.write('del "%~f0"\r\n')

                CREATE_NEW_CONSOLE = 0x00000010
                CREATE_NEW_PROCESS_GROUP = 0x00000200
                subprocess.Popen(
                    ['cmd.exe', '/c', bat_path],
                    creationflags=CREATE_NEW_CONSOLE | CREATE_NEW_PROCESS_GROUP,
                    close_fds=True
                )
            else:
                launcher_cmd = f'sleep 1.5 && "{python_bin}" "{app_script}"'
                subprocess.Popen(
                    ['sh', '-c', launcher_cmd],
                    cwd=app_dir,
                    close_fds=True
                )
        except Exception as e:
            print(f"[Restart Error] {e}")
        finally:
            os._exit(0)

    threading.Thread(target=_worker, daemon=True).start()


@app.route('/api/update/apply', methods=['POST'])
def api_update_apply():
    """Apply staged update atomically with backup and rollback."""
    data = request.get_json(force=True, silent=True) or {}
    staging_dir = data.get('staging_dir') or os.path.join(update_mgr.temp_dir, 'staging')

    if not os.path.isdir(staging_dir):
        return jsonify({'ok': False, 'message': 'Không tìm thấy thư mục staging bản cập nhật.'}), 400

    res = update_mgr.apply_update_atomic(staging_dir)
    if res.get('ok'):
        restart_server()
        res['restarting'] = True
        res['message'] = "Cập nhật thành công! Máy chủ đang tự động khởi động lại trong giây lát..."
    return jsonify(res)


# ---------------------------------------------------------------------------
# Main page & Health Check
# ---------------------------------------------------------------------------

@app.route('/api/health')
def health_check():
    return jsonify({
        'status': 'ok',
        'app_name': APP_NAME,
        'version': __version__,
        'ffmpeg_available': FFMPEG_AVAILABLE,
        'edge_tts_available': EDGE_TTS_AVAILABLE,
        'active_jobs': len(jobs),
        'server_time': datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    })

@app.route('/')
def index():
    edge_ok = check_edge_tts()
    device = detect_device() if edge_ok else "N/A"
    return render_template(
        'index.html',
        app_name=APP_NAME,
        app_version=__version__,
        ffmpeg_available=check_ffmpeg(),
        voxcpm_available=edge_ok,
        tts_device=device,
        curated_voices=CURATED_VOICES,
        subtitle_languages=subtitles_engine.SUPPORTED_LANGUAGES,
    )


# ---------------------------------------------------------------------------
# Video render routes
# ---------------------------------------------------------------------------

@app.route('/render', methods=['POST'])
def render():
    if not FFMPEG_AVAILABLE:
        return jsonify({'error': 'FFmpeg is not installed or not found in PATH.'}), 500

    try:
        enable_subtitles   = request.form.get('enable_subtitles', 'false').lower() == 'true'
        subtitle_language  = request.form.get('subtitle_language', 'vi').strip()

        settings = {
            'aspect_ratio':        request.form.get('aspect_ratio', '16:9'),
            'resolution':          request.form.get('resolution', '1080p'),
            'fps':                 max(30, int(request.form.get('fps', 60))),
            'duration_per_image':  float(request.form.get('duration_per_image', 5.0)),
            'use_transition':      request.form.get('use_transition', 'true').lower() == 'true',
            'transition_duration': float(request.form.get('transition_duration', 1.0)),
            'weight_zoom_in':      float(request.form.get('weight_zoom_in', 25)),
            'weight_zoom_out':     float(request.form.get('weight_zoom_out', 25)),
            'weight_pan':          float(request.form.get('weight_pan', 25)),
            'weight_tilt':         float(request.form.get('weight_tilt', 25)),
            'zoom_magnitude':      float(request.form.get('zoom_magnitude', 0.2)),
            'pan_magnitude':       float(request.form.get('pan_magnitude', 0.2)),
            'tilt_magnitude':      float(request.form.get('tilt_magnitude', 0.2)),
            'codec':               request.form.get('codec', 'h264').strip().lower(),
            'crf':                 int(request.form.get('crf', 19)),
            'preset':              request.form.get('preset', 'medium'),
            'subtitle_language':   subtitle_language,
        }

        job_id  = str(uuid.uuid4())
        job_dir = UPLOAD_DIR / job_id
        job_dir.mkdir(parents=True, exist_ok=True)

        image_paths: list = []
        for f in request.files.getlist('images'):
            if f and f.filename and is_supported_image(f.filename):
                safe_name = os.path.basename(f.filename)
                dest = job_dir / safe_name
                f.save(str(dest))
                image_paths.append(str(dest))

        # Dynamic Image Durations and Effects
        image_durations = None
        raw_durations = request.form.get('image_durations', '').strip()
        if raw_durations:
            try:
                parsed_durs = json.loads(raw_durations)
                if isinstance(parsed_durs, list):
                    image_durations = [float(d) for d in parsed_durs]
            except Exception:
                pass

        if image_durations:
            settings['image_durations'] = image_durations

        # Per-image specific effects
        image_effects = None
        raw_effects = request.form.get('image_effects', '').strip()
        if raw_effects:
            try:
                parsed_effs = json.loads(raw_effects)
                if isinstance(parsed_effs, list):
                    image_effects = [str(e) for e in parsed_effs]
            except Exception:
                pass

        if image_effects:
            settings['image_effects'] = image_effects

        # Subtitle styling & positioning settings
        settings['sub_font'] = request.form.get('sub_font', 'paperlogy').strip()
        settings['sub_color'] = request.form.get('sub_color', '#ffffff').strip()
        settings['sub_stroke_enabled'] = request.form.get('sub_stroke_enabled', 'true').strip()
        settings['sub_stroke_color'] = request.form.get('sub_stroke_color', '#000000').strip()
        settings['sub_bg_enabled'] = request.form.get('sub_bg_enabled', 'false').strip()
        settings['sub_bg_color'] = request.form.get('sub_bg_color', '#000000').strip()
        settings['sub_align'] = request.form.get('sub_align', 'center').strip()

        try:
            settings['sub_size'] = int(request.form.get('sub_size', 36))
        except (ValueError, TypeError):
            settings['sub_size'] = 36

        try:
            settings['sub_stroke_width'] = int(request.form.get('sub_stroke_width', 4))
        except (ValueError, TypeError):
            settings['sub_stroke_width'] = 4

        try:
            settings['sub_bg_opacity'] = int(request.form.get('sub_bg_opacity', 75))
        except (ValueError, TypeError):
            settings['sub_bg_opacity'] = 75

        try:
            settings['sub_bg_radius'] = int(request.form.get('sub_bg_radius', 12))
        except (ValueError, TypeError):
            settings['sub_bg_radius'] = 12

        try:
            settings['sub_pos_y'] = float(request.form.get('sub_pos_y', 6.5))
        except (ValueError, TypeError):
            settings['sub_pos_y'] = 6.5

        try:
            settings['sub_pos_x'] = float(request.form.get('sub_pos_x', 0.0))
        except (ValueError, TypeError):
            settings['sub_pos_x'] = 0.0

        try:
            settings['sub_letter_spacing'] = float(request.form.get('sub_letter_spacing', 0.0))
        except (ValueError, TypeError):
            settings['sub_letter_spacing'] = 0.0

        try:
            settings['sub_line_spacing'] = float(request.form.get('sub_line_spacing', 1.25))
        except (ValueError, TypeError):
            settings['sub_line_spacing'] = 1.25

        # In/Out selection render points
        render_in = request.form.get('render_in')
        if render_in is not None and str(render_in).strip() != '':
            try:
                settings['render_in'] = float(render_in)
            except (ValueError, TypeError):
                pass

        render_out = request.form.get('render_out')
        if render_out is not None and str(render_out).strip() != '':
            try:
                settings['render_out'] = float(render_out)
            except (ValueError, TypeError):
                pass

        # Audio: either an uploaded file OR a previously generated TTS job
        audio_path = None
        audio_file = request.files.get('audio')
        tts_job_id = request.form.get('tts_job_id', '').strip()

        if audio_file and audio_file.filename and is_supported_audio(audio_file.filename):
            safe_audio = os.path.basename(audio_file.filename)
            audio_path = str(job_dir / safe_audio)
            audio_file.save(audio_path)
        elif tts_job_id:
            if tts_job_id in jobs and jobs[tts_job_id].get('status') == 'done':
                audio_path = jobs[tts_job_id].get('output')
            else:
                disk_wav = TTS_DIR / f"{tts_job_id}.wav"
                if disk_wav.is_file():
                    audio_path = str(disk_wav)

        # If no image files were uploaded, auto-generate backdrop slide images
        if not image_paths:
            W, H = ffmpeg_utils.build_resolution(settings.get('aspect_ratio', '16:9'), settings.get('resolution', '1080p'))
            slide_count = 1
            if audio_path and os.path.isfile(audio_path):
                try:
                    ffprobe_bin = ffmpeg_utils.get_ffprobe_bin()
                    cmd_probe = [ffprobe_bin, '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', audio_path]
                    a_dur = float(subprocess.check_output(cmd_probe).decode().strip())
                    slide_count = max(1, int(a_dur / 4.5))
                    dur_each = round(a_dur / slide_count, 3)
                    settings['image_durations'] = [dur_each] * slide_count
                except Exception:
                    slide_count = 1
            elif image_durations:
                slide_count = len(image_durations)

            bg_colors = ['0x090d16', '0x0f172a', '0x1e1b4b', '0x172554', '0x042f2e', '0x1f1641']
            ffmpeg_bin = ffmpeg_utils.get_ffmpeg_bin()
            kwargs = {'creationflags': 0x08000000} if sys.platform == 'win32' else {}
            for i in range(slide_count):
                slide_img = str(job_dir / f"slide_{i:04d}.png")
                col = bg_colors[i % len(bg_colors)]
                subprocess.run([
                    ffmpeg_bin, '-y',
                    '-f', 'lavfi',
                    '-i', f'color=c={col}:s={W}x{H}:d=1',
                    '-vframes', '1',
                    slide_img
                ], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, **kwargs)
                image_paths.append(slide_img)

        output_path = str(OUTPUT_DIR / f'{job_id}.mp4')
        srt_output_path = str(OUTPUT_DIR / f'{job_id}.srt')

        q: queue.Queue = queue.Queue()
        jobs[job_id] = {
            'type':       'render',
            'status':     'running',
            'progress':   0,
            'output':     output_path,
            'srt_output': None,
            'error':      None,
            'queue':      q,
        }

        custom_srt_text = request.form.get('custom_srt', '').strip()
        sorted_preview = [os.path.basename(p) for p in sort_images(image_paths)]

        project_id = sanitize_project_id(request.form.get('project_id', ''))

        print("\n" + "="*60)
        print(f"📥 [Render API] NHẬN LỆNH RENDER VIDEO:")
        print(f"   • Job ID: {job_id}")
        print(f"   • Số lượng ảnh: {len(image_paths)} ảnh")
        print(f"   • Audio: {audio_path or 'Không có (Video không tiếng)'}")
        print(f"   • Phụ đề SRT: {'Có (' + str(len(custom_srt_text.splitlines())) + ' dòng)' if custom_srt_text else 'Không'}")
        print(f"   • Tỷ lệ: {settings.get('aspect_ratio')} | Độ phân giải: {settings.get('resolution')} | FPS: {settings.get('fps')}")
        print("="*60 + "\n")

        def run():
            try:
                sub_path = None
                if custom_srt_text:
                    with open(srt_output_path, 'w', encoding='utf-8') as f:
                        f.write(custom_srt_text)
                    sub_path = srt_output_path
                    jobs[job_id]['srt_output'] = srt_output_path
                    print(f"📄 [Render] Đã ghi {len(custom_srt_text.splitlines())} dòng SRT vào: {srt_output_path}")
                elif enable_subtitles and audio_path:
                    try:
                        def on_sub_progress(pct: int, msg: str):
                            q.put({'progress': int(pct * 0.25), 'message': f'AutoSub: {msg}'})

                        srt_txt, _ = subtitles_engine.transcribe_audio_whisper(
                            audio_path,
                            language=subtitle_language,
                            progress_callback=on_sub_progress
                        )
                        if srt_txt:
                            with open(srt_output_path, 'w', encoding='utf-8') as f:
                                f.write(srt_txt)
                            sub_path = srt_output_path
                            jobs[job_id]['srt_output'] = srt_output_path
                    except Exception as sub_exc:
                        print(f"AutoSub warning: {sub_exc}")

                def on_render_progress(pct: int, msg: str):
                    base = 25 if (enable_subtitles and audio_path and not custom_srt_text) else 0
                    scaled = base + int(pct * ((100 - base) / 100))
                    jobs[job_id]['progress'] = scaled
                    q.put({'progress': scaled, 'message': msg})
                    if pct % 20 == 0 or pct == 100:
                        print(f"🎬 [Render Progress] {scaled}% - {msg[:60] if msg else ''}")

                print(f"🚀 [Render] Bắt đầu chạy FFmpeg...")
                render_video(
                    image_paths, audio_path, output_path, settings,
                    on_render_progress, subtitle_path=sub_path
                )
                print(f"✅ [Render Hoàn Tất] Đã xuất video thành công: {output_path}")

                # Save copy to active project export directory
                if project_id:
                    proj_export_dir = PROJECTS_DIR / project_id / 'export'
                    proj_export_dir.mkdir(parents=True, exist_ok=True)
                    dest_video = proj_export_dir / f"{project_id}_{settings.get('resolution', '1080p')}.mp4"
                    import shutil
                    if os.path.isfile(output_path):
                        shutil.copy2(output_path, str(dest_video))
                    if sub_path and os.path.isfile(sub_path):
                        dest_srt = PROJECTS_DIR / project_id / 'subtitles' / 'subtitles.srt'
                        dest_srt.parent.mkdir(parents=True, exist_ok=True)
                        shutil.copy2(sub_path, str(dest_srt))

                jobs[job_id]['status'] = 'done'
                q.put({
                    'progress': 100,
                    'done': True,
                    'has_srt': sub_path is not None
                })
            except Exception as exc:
                err_msg = str(exc)
                print(f"❌ [Render Lỗi Nghiêm Trọng]: {err_msg}")
                jobs[job_id]['status'] = 'error'
                jobs[job_id]['error']  = err_msg
                q.put({'progress': 0, 'error': err_msg})

        threading.Thread(target=run, daemon=True).start()

        return jsonify({
            'job_id':       job_id,
            'image_count':  len(image_paths),
            'has_audio':    audio_path is not None,
            'sorted_order': sorted_preview,
        })

    except Exception as exc:
        print(f"❌ [Render API Exception]: {exc}")
        return jsonify({'error': str(exc)}), 500


@app.route('/progress/<job_id>')
@app.route('/subtitles/progress/<job_id>')
def progress(job_id):
    """SSE endpoint — works for render, TTS, and AutoSub jobs."""
    if job_id not in jobs:
        return jsonify({'error': 'Job not found'}), 404

    def generate():
        try:
            j = jobs[job_id]
            while True:
                try:
                    msg = j['queue'].get(timeout=30)
                    if msg.get('error'):
                        err_text = str(msg.get('error'))
                        yield f"data: {json.dumps({'status': 'error', 'error': err_text, 'message': err_text})}\n\n"
                        break
                    yield f"data: {json.dumps(msg)}\n\n"
                    if msg.get('done'):
                        break
                except queue.Empty:
                    yield f"data: {json.dumps({'heartbeat': True})}\n\n"
        except Exception as e:
            err_text = str(e)
            yield f"data: {json.dumps({'status': 'error', 'error': err_text, 'message': err_text})}\n\n"

    return Response(
        stream_with_context(generate()),
        mimetype='text/event-stream',
        headers={'Cache-Control': 'no-cache', 'X-Accel-Buffering': 'no'}
    )


@app.route('/download/<job_id>')
def download(job_id):
    if job_id not in jobs:
        return jsonify({'error': 'Job not found'}), 404
    j = jobs[job_id]
    if j['status'] != 'done':
        return jsonify({'error': 'Not complete yet'}), 400
    return send_file(
        j['output'],
        mimetype='video/mp4',
        as_attachment=True,
        download_name=f"slideshow_{job_id[:8]}.mp4"
    )


@app.route('/download_srt/<job_id>')
def download_srt(job_id):
    """Download the generated SRT subtitle file."""
    if job_id not in jobs:
        return jsonify({'error': 'Job not found'}), 404
    j = jobs[job_id]
    srt_path = j.get('srt_output')
    if not srt_path or not os.path.isfile(srt_path):
        return jsonify({'error': 'No subtitles available for this job'}), 404
    return send_file(
        srt_path,
        mimetype='text/plain',
        as_attachment=True,
        download_name='subtitles.srt'
    )


@app.route('/subtitles/preview', methods=['POST'])
def subtitles_preview():
    """Generate and return subtitles text asynchronously with real-time SSE progress."""
    try:
        lang = request.form.get('language', 'auto')
        audio_file = request.files.get('audio')
        tts_job_id = request.form.get('tts_job_id', '').strip()

        audio_path = None
        tmp_clean = None
        if audio_file and audio_file.filename:
            tmp = tempfile.NamedTemporaryFile(suffix=os.path.splitext(audio_file.filename)[1], delete=False)
            audio_path = tmp.name
            tmp_clean = tmp.name
            audio_file.save(audio_path)
        elif tts_job_id:
            if tts_job_id in jobs and jobs[tts_job_id].get('status') == 'done':
                audio_path = jobs[tts_job_id].get('output')
            else:
                disk_wav = TTS_DIR / f"{tts_job_id}.wav"
                if disk_wav.is_file():
                    audio_path = str(disk_wav)

        if not audio_path or not os.path.isfile(audio_path):
            return jsonify({'error': 'Vui lòng chọn hoặc tạo file audio trước.'}), 400

        job_id = str(uuid.uuid4())
        job_q = queue.Queue()
        jobs[job_id] = {
            'type': 'autosub',
            'status': 'running',
            'progress': 0,
            'queue': job_q,
            'message': 'Đang chuẩn bị Faster-Whisper AI...',
            'srt': '',
            'subtitles': [],
            'count': 0
        }

        engine_type = request.form.get('engine_type', 'stable-ts')
        model_size = request.form.get('model_size', 'large-v3')
        demucs_flag = request.form.get('demucs', 'true').lower() in ('true', '1', 'yes')
        initial_prompt = request.form.get('initial_prompt')
        ground_truth_script = request.form.get('ground_truth_script', '').strip()
        gemini_api_key = request.form.get('gemini_api_key', '').strip()

        print(f"📥 [Frontend Request /subtitles/preview] Engine: {engine_type}, Model: {model_size}, Demucs: {demucs_flag}, Key: {'***' + gemini_api_key[-4:] if gemini_api_key and len(gemini_api_key) >= 4 else 'None'} (len: {len(gemini_api_key)}), Script len: {len(ground_truth_script)}")

        def run_worker():
            try:
                def progress_cb(pct, msg=""):
                    if job_id in jobs:
                        jobs[job_id]['progress'] = pct
                        jobs[job_id]['message'] = msg
                        job_q.put({'progress': pct, 'message': msg})

                report = None
                if engine_type == 'gemini-cloud-multimodal':
                    srt_text, subs, report = subtitles_engine.transcribe_audio_gemini_multimodal(
                        audio_path=audio_path,
                        ground_truth_script=ground_truth_script,
                        language=lang,
                        api_key=gemini_api_key,
                        progress_callback=progress_cb
                    )
                elif engine_type == 'whisper-gemini-doublecheck':
                    srt_text, subs, report = subtitles_engine.align_and_verify_with_llm(
                        audio_path=audio_path,
                        ground_truth_script=ground_truth_script,
                        language=lang,
                        model_size=model_size,
                        demucs=demucs_flag,
                        api_key=gemini_api_key,
                        progress_callback=progress_cb
                    )
                elif engine_type == 'stable-ts':
                    srt_text, subs = subtitles_engine.transcribe_audio_stable_whisper(
                        audio_path=audio_path,
                        language=lang,
                        model_size=model_size,
                        demucs=demucs_flag,
                        initial_prompt=initial_prompt,
                        progress_callback=progress_cb
                    )
                    audio_dur = subtitles_engine.get_audio_duration(audio_path)
                    subs, tc_stats = subtitles_engine.validate_and_sanitize_timecodes(subs, audio_dur)
                    srt_text = subtitles_engine.create_srt_content(subs)
                    report = {
                        'gemini_verified': False,
                        'model_used': f'Stable-Whisper ({model_size})',
                        'subs_count': len(subs),
                        'corrections_count': 0,
                        'mode': 'Whisper Local',
                        'timecode_status': f"Đã chuẩn hóa (Sửa {tc_stats['fixed_overlaps']} mốc chồng lấn)",
                        'audio_coverage': f"00:00:00,000 → {subtitles_engine.format_srt_time(audio_dur) if audio_dur else 'N/A'}"
                    }
                else:
                    srt_text, subs = subtitles_engine.transcribe_audio_whisper(
                        audio_path=audio_path,
                        language=lang,
                        progress_callback=progress_cb
                    )
                    audio_dur = subtitles_engine.get_audio_duration(audio_path)
                    subs, tc_stats = subtitles_engine.validate_and_sanitize_timecodes(subs, audio_dur)
                    srt_text = subtitles_engine.create_srt_content(subs)
                    report = {
                        'gemini_verified': False,
                        'model_used': 'Faster-Whisper (Int8)',
                        'subs_count': len(subs),
                        'corrections_count': 0,
                        'mode': 'Faster-Whisper Local',
                        'timecode_status': f"Đã chuẩn hóa (Sửa {tc_stats['fixed_overlaps']} mốc chồng lấn)",
                        'audio_coverage': f"00:00:00,000 → {subtitles_engine.format_srt_time(audio_dur) if audio_dur else 'N/A'}"
                    }

                if tmp_clean and os.path.isfile(tmp_clean):
                    try:
                        os.remove(tmp_clean)
                    except OSError:
                        pass

                jobs[job_id]['status'] = 'done'
                jobs[job_id]['progress'] = 100
                jobs[job_id]['srt'] = srt_text
                jobs[job_id]['subtitles'] = subs
                jobs[job_id]['count'] = len(subs)
                jobs[job_id]['verification_report'] = report
                job_q.put({
                    'done': True,
                    'progress': 100,
                    'message': f'Đã nhận diện & chuẩn hóa {len(subs)} câu phụ đề.',
                    'srt': srt_text,
                    'subtitles': subs,
                    'count': len(subs),
                    'verification_report': report
                })
            except Exception as e:
                jobs[job_id]['status'] = 'error'
                jobs[job_id]['error'] = str(e)
                job_q.put({'error': str(e)})

        threading.Thread(target=run_worker, daemon=True).start()
        return jsonify({'job_id': job_id})
    except Exception as exc:
        return jsonify({'error': str(exc)}), 500


@app.route('/api/forced-align', methods=['POST'])
def api_forced_align():
    """100% Exact Forced Alignment of Ground Truth Script (.txt) with Audio Waveform."""
    try:
        import forced_alignment_engine

        audio_file = request.files.get('audio')
        script_file = request.files.get('script_file')
        script_text = request.form.get('script_text', '').strip()
        language = request.form.get('language', 'vi').strip()
        api_key = request.form.get('gemini_api_key', '').strip()
        engine = request.form.get('engine', 'gemini').strip()
        model_size = request.form.get('model_size', 'base').strip()

        if script_file and script_file.filename:
            script_text = script_file.read().decode('utf-8', errors='replace').strip()

        if not script_text:
            return jsonify({'error': 'Vui lòng nhập hoặc tải file kịch bản .txt để so khớp.'}), 400

        audio_path = None
        tmp_clean = None
        if audio_file and audio_file.filename:
            tmp = tempfile.NamedTemporaryFile(suffix=os.path.splitext(audio_file.filename)[1], delete=False)
            audio_path = tmp.name
            tmp_clean = tmp.name
            audio_file.save(audio_path)
        else:
            tts_job_id = request.form.get('tts_job_id', '').strip()
            if tts_job_id:
                disk_wav = TTS_DIR / f"{tts_job_id}.wav"
                if disk_wav.is_file():
                    audio_path = str(disk_wav)

        if not audio_path or not os.path.isfile(audio_path):
            return jsonify({'error': 'Vui lòng tải lên file âm thanh (TTS / Voice .mp3/.wav).'}), 400

        job_id = str(uuid.uuid4())
        job_q = queue.Queue()
        jobs[job_id] = {
            'type': 'forced-align',
            'status': 'running',
            'progress': 10,
            'queue': job_q,
            'message': f'Đang khởi động Forced Alignment ({engine.upper()})...',
            'srt': '',
            'subtitles': [],
            'count': 0
        }

        def run_worker():
            try:
                t0 = time.time()
                print(f"🎯 [Forced-Align] Bắt đầu so khớp kịch bản (Engine: {engine}, Language: {language})...")
                def progress_cb(pct, msg=""):
                    if job_id in jobs:
                        jobs[job_id]['progress'] = pct
                        jobs[job_id]['message'] = msg
                        job_q.put({'progress': pct, 'message': msg})
                        if pct in (15, 50, 70, 100):
                            print(f"   • [{pct}%] {msg}")

                res = forced_alignment_engine.forced_align(
                    audio_path=audio_path,
                    script_text=script_text,
                    engine=engine,
                    language=language,
                    api_key=api_key,
                    model_size=model_size,
                    progress_callback=progress_cb
                )

                if tmp_clean and os.path.isfile(tmp_clean):
                    try:
                        os.remove(tmp_clean)
                    except OSError:
                        pass

                srt_content = res['srt']
                subs = res['segments']
                elapsed = time.time() - t0
                print(f"✅ [Forced-Align] Hoàn tất 100% so khớp {len(subs)} câu kịch bản trong {elapsed:.1f}s!")

                jobs[job_id]['status'] = 'done'
                jobs[job_id]['progress'] = 100
                jobs[job_id]['srt'] = srt_content
                jobs[job_id]['subtitles'] = subs
                jobs[job_id]['count'] = len(subs)
                job_q.put({
                    'done': True,
                    'progress': 100,
                    'message': f'✅ Đã so khớp 100% chính xác {len(subs)} câu kịch bản!',
                    'srt': srt_content,
                    'subtitles': subs,
                    'count': len(subs)
                })
            except Exception as e:
                import traceback
                print(f"❌ [Forced-Align Error] {e}")
                traceback.print_exc()
                jobs[job_id]['status'] = 'error'
                jobs[job_id]['error'] = str(e)
                job_q.put({'error': str(e)})

        threading.Thread(target=run_worker, daemon=True).start()
        return jsonify({'job_id': job_id})
    except Exception as exc:
        return jsonify({'error': str(exc)}), 500


@app.route('/subtitles/align', methods=['POST'])
def forced_align():
    """100% Accurate Forced Alignment with real-time SSE progress streaming."""
    try:
        script_text        = request.form.get('script_text', '').strip()
        duration_per_image = float(request.form.get('duration_per_image', 3.5))
        image_count        = int(request.form.get('image_count', 0))
        audio_file         = request.files.get('audio')
        tts_job_id         = request.form.get('tts_job_id', '').strip()
        lang               = request.form.get('language', 'auto')

        audio_path = None
        tmp_clean = None
        if audio_file and audio_file.filename:
            tmp = tempfile.NamedTemporaryFile(suffix=os.path.splitext(audio_file.filename)[1], delete=False)
            audio_path = tmp.name
            tmp_clean = tmp.name
            audio_file.save(audio_path)
        elif tts_job_id:
            if tts_job_id in jobs and jobs[tts_job_id].get('status') == 'done':
                audio_path = jobs[tts_job_id].get('output')
            else:
                disk_wav = TTS_DIR / f"{tts_job_id}.wav"
                if disk_wav.is_file():
                    audio_path = str(disk_wav)

        job_id = str(uuid.uuid4())
        job_q = queue.Queue()
        jobs[job_id] = {
            'type': 'autosub',
            'status': 'running',
            'progress': 0,
            'queue': job_q,
            'message': 'Đang chuẩn bị Faster-Whisper AI...',
            'srt': '',
            'subtitles': [],
            'count': 0
        }

        # Check if pre-computed SRT is already cached for this TTS job
        cached_srt = None
        if tts_job_id:
            if tts_job_id in jobs and jobs[tts_job_id].get('srt'):
                cached_srt = jobs[tts_job_id]['srt']
            else:
                disk_srt = TTS_DIR / f"{tts_job_id}.srt"
                if disk_srt.is_file():
                    with open(disk_srt, 'r', encoding='utf-8') as f_s:
                        cached_srt = f_s.read()

        if cached_srt and cached_srt.strip():
            cached_subs = subtitles_engine.parse_srt_content(cached_srt)
            if cached_subs:
                jobs[job_id]['status'] = 'done'
                jobs[job_id]['progress'] = 100
                jobs[job_id]['srt'] = cached_srt
                jobs[job_id]['subtitles'] = cached_subs
                jobs[job_id]['count'] = len(cached_subs)
                job_q.put({
                    'done': True,
                    'progress': 100,
                    'message': f'Đã gán tức thì {len(cached_subs)} câu phụ đề.',
                    'srt': cached_srt,
                    'subtitles': cached_subs,
                    'count': len(cached_subs)
                })
                return jsonify({'job_id': job_id})

        engine_type = request.form.get('engine_type', 'stable-ts')
        model_size = request.form.get('model_size', 'large-v3')
        demucs_flag = request.form.get('demucs', 'true').lower() in ('true', '1', 'yes')
        initial_prompt = request.form.get('initial_prompt')

        def run_worker():
            try:
                def progress_cb(pct, msg=""):
                    if job_id in jobs:
                        jobs[job_id]['progress'] = pct
                        jobs[job_id]['message'] = msg
                        job_q.put({'progress': pct, 'message': msg})

                if not audio_path or not os.path.isfile(audio_path):
                    raise ValueError("Không tìm thấy tệp âm thanh để căn chỉnh phụ đề.")

                if script_text and script_text.strip():
                    # High precision Two-Pass Anchor Forced Alignment (100% script match, 20ms gap buffer)
                    import forced_alignment_engine
                    res = forced_alignment_engine.forced_align(
                        audio_path=audio_path,
                        script_text=script_text,
                        engine='whisperx',
                        language=lang,
                        model_size=model_size if model_size in ('tiny', 'base', 'small', 'medium') else 'base',
                        progress_callback=progress_cb
                    )
                    srt_content = res.get('srt', '')
                    subs = res.get('segments', [])
                elif engine_type == 'stable-ts':
                    srt_content, subs = subtitles_engine.transcribe_audio_stable_whisper(
                        audio_path=audio_path,
                        language=lang,
                        model_size=model_size,
                        demucs=demucs_flag,
                        initial_prompt=initial_prompt,
                        progress_callback=progress_cb
                    )
                else:
                    srt_content, subs = subtitles_engine.transcribe_audio_whisper(
                        audio_path=audio_path,
                        language=lang,
                        progress_callback=progress_cb
                    )

                if tmp_clean and os.path.isfile(tmp_clean):
                    try:
                        os.remove(tmp_clean)
                    except OSError:
                        pass

                jobs[job_id]['status'] = 'done'
                jobs[job_id]['progress'] = 100
                jobs[job_id]['srt'] = srt_content
                jobs[job_id]['subtitles'] = subs
                jobs[job_id]['count'] = len(subs)
                job_q.put({
                    'done': True,
                    'progress': 100,
                    'message': f'Đã gán {len(subs)} câu phụ đề.',
                    'srt': srt_content,
                    'subtitles': subs,
                    'count': len(subs)
                })
            except Exception as e:
                jobs[job_id]['status'] = 'error'
                jobs[job_id]['error'] = str(e)
                job_q.put({'error': str(e)})

        threading.Thread(target=run_worker, daemon=True).start()
        return jsonify({'job_id': job_id})
    except Exception as exc:
        return jsonify({'error': str(exc)}), 500


@app.route('/api/subtitles/translate', methods=['POST'])
def api_translate_subtitles():
    """Translate subtitles or script text to target language (default 'vi')."""
    try:
        req_data = request.get_json(silent=True) or {}
        subtitles = req_data.get('subtitles', [])
        script_text = req_data.get('script_text', '')
        source_lang = req_data.get('source_lang', 'auto')
        target_lang = req_data.get('target_lang', 'vi')

        if subtitles:
            translated = translation_utils.translate_subtitles_batch(
                subtitles, source_lang=source_lang, target_lang=target_lang
            )
            return jsonify({'success': True, 'subtitles': translated})
        elif script_text:
            trans_script, trans_subs = translation_utils.translate_script_json(
                script_text, source_lang=source_lang, target_lang=target_lang
            )
            return jsonify({
                'success': True,
                'script_text': trans_script if isinstance(trans_script, str) else json.dumps(trans_script, ensure_ascii=False, indent=2),
                'subtitles': trans_subs
            })
        else:
            return jsonify({'error': 'No subtitles or script_text provided'}), 400
    except Exception as exc:
        return jsonify({'error': str(exc)}), 500


# ---------------------------------------------------------------------------
# TTS routes
# ---------------------------------------------------------------------------

@app.route('/tts/status')
def tts_status():
    """Check TTS availability and device."""
    edge_ok = check_edge_tts()
    return jsonify({
        'available': edge_ok,
        'device':    detect_device() if edge_ok else "N/A",
        'engine':    'Microsoft Edge Neural TTS',
        'note':      'Sẵn sàng tạo giọng đọc chất lượng cao (Miễn phí 100%).',
    })


@app.route('/tts/voices')
def tts_voices():
    """Return available curated voices."""
    return jsonify({
        'voices': CURATED_VOICES
    })


@app.route('/tts/generate', methods=['POST'])
def tts_generate():
    """Start a TTS generation job with Edge-TTS. Returns job_id for SSE progress tracking."""
    if not check_edge_tts():
        return jsonify({'error': 'edge-tts chưa được cài đặt. Chạy: pip install edge-tts'}), 500

    try:
        text              = request.form.get('text', '').strip()
        voice             = request.form.get('voice', 'ko-KR-InJoonNeural').strip()
        rate              = request.form.get('rate', '+0%').strip()
        pitch             = request.form.get('pitch', '+0Hz').strip()
        volume            = request.form.get('volume', '+0%').strip()

        if not text:
            return jsonify({'error': 'Vui lòng nhập văn bản kịch bản'}), 400

        job_id      = str(uuid.uuid4())
        output_path = str(TTS_DIR / f'{job_id}.wav')

        q: queue.Queue = queue.Queue()
        jobs[job_id] = {
            'type':     'tts',
            'status':   'running',
            'progress': 0,
            'output':   output_path,
            'error':    None,
            'queue':    q,
        }

        def run():
            try:
                def on_progress(pct: int, msg: str):
                    if pct >= 0:
                        jobs[job_id]['progress'] = pct
                    q.put({'progress': max(pct, 0), 'message': msg})

                out_path, durations, srt_content = generate_tts(
                    text=text,
                    voice=voice,
                    rate=rate,
                    pitch=pitch,
                    volume=volume,
                    output_path=output_path,
                    progress_callback=on_progress,
                )
                jobs[job_id]['durations'] = durations
                jobs[job_id]['srt'] = srt_content
                jobs[job_id]['status'] = 'done'
                q.put({'progress': 100, 'done': True, 'srt': srt_content, 'durations': durations})
            except Exception as exc:
                err_msg = str(exc)
                jobs[job_id]['status'] = 'error'
                jobs[job_id]['error']  = err_msg
                q.put({'progress': 0, 'error': err_msg})

        threading.Thread(target=run, daemon=True).start()
        return jsonify({'job_id': job_id})

    except Exception as exc:
        return jsonify({'error': str(exc)}), 500


@app.route('/tts/audio/<job_id>')
def tts_audio(job_id):
    """Stream the generated WAV for in-browser preview."""
    if job_id not in jobs:
        return jsonify({'error': 'Job not found'}), 404
    j = jobs[job_id]
    if j.get('type') != 'tts' or j['status'] != 'done':
        return jsonify({'error': 'TTS audio not ready'}), 400
    return send_file(j['output'], mimetype='audio/wav')


# ---------------------------------------------------------------------------
# Project Management APIs (Studio Workflow)
# ---------------------------------------------------------------------------

def sanitize_project_name(name: str) -> str:
    import re
    s = re.sub(r'[^\w\s-]', '', name).strip().replace(' ', '_')
    return s[:60] if s else 'Video_Moi'


@app.route('/api/projects', methods=['GET'])
def list_projects():
    """List all projects sorted by last modification date."""
    projects = []
    if PROJECTS_DIR.exists():
        for p in sorted(PROJECTS_DIR.iterdir(), key=lambda x: x.stat().st_mtime if x.exists() else 0, reverse=True):
            if p.is_dir() and not p.name.startswith('.'):
                meta_file = p / 'project.json'
                data = {}
                if meta_file.is_file():
                    try:
                        with open(meta_file, 'r', encoding='utf-8') as f:
                            data = json.load(f)
                    except Exception:
                        pass
                
                title = data.get('title') or p.name
                projects.append({
                    'id': p.name,
                    'name': title,
                    'mtime': p.stat().st_mtime,
                    'image_count': len(data.get('images_data', [])),
                    'duration': data.get('duration', 0.0),
                    'created_at': data.get('created_at', '')
                })
    return jsonify({'projects': projects})


@app.route('/api/projects/create', methods=['POST'])
def create_project():
    """Create a new project directory with standard subfolders."""
    import datetime
    req_data = request.get_json(silent=True) or {}
    raw_title = req_data.get('title', '').strip() or 'Video_Moi'
    today_str = datetime.datetime.now().strftime('%Y-%m-%d')
    safe_title = sanitize_project_name(raw_title)
    
    folder_name = f"{today_str}_{safe_title}"
    project_path = PROJECTS_DIR / folder_name
    
    # Avoid duplicate name collision
    counter = 1
    while project_path.exists():
        folder_name = f"{today_str}_{safe_title}_{counter:02d}"
        project_path = PROJECTS_DIR / folder_name
        counter += 1
        
    project_path.mkdir(parents=True, exist_ok=True)
    (project_path / 'images').mkdir(exist_ok=True)
    (project_path / 'audio').mkdir(exist_ok=True)
    (project_path / 'script').mkdir(exist_ok=True)
    (project_path / 'subtitles').mkdir(exist_ok=True)
    (project_path / 'export').mkdir(exist_ok=True)
    
    initial_meta = {
        'id': folder_name,
        'title': raw_title,
        'created_at': datetime.datetime.now().isoformat(),
        'updated_at': datetime.datetime.now().isoformat(),
        'settings': {},
        'images_data': [],
        'subtitles': [],
        'script_text': ''
    }
    with open(project_path / 'project.json', 'w', encoding='utf-8') as f:
        json.dump(initial_meta, f, ensure_ascii=False, indent=2)
        
    return jsonify({
        'success': True,
        'project_id': folder_name,
        'title': raw_title,
        'folder_path': str(project_path)
    })


@app.route('/api/projects/save', methods=['POST'])
def save_project():
    """Save full project state to project.json and assets."""
    req_data = request.get_json(silent=True) or {}
    project_id = sanitize_project_id(req_data.get('project_id'))
    if not project_id:
        return jsonify({'error': 'Valid project_id is required'}), 400
        
    project_path = PROJECTS_DIR / project_id
    if not project_path.exists():
        project_path.mkdir(parents=True, exist_ok=True)
        (project_path / 'images').mkdir(exist_ok=True)
        (project_path / 'audio').mkdir(exist_ok=True)
        (project_path / 'script').mkdir(exist_ok=True)
        (project_path / 'subtitles').mkdir(exist_ok=True)
        (project_path / 'export').mkdir(exist_ok=True)
        
    req_data['updated_at'] = datetime.datetime.now().isoformat()
    
    # Save script to script/ folder if present
    if req_data.get('script_text'):
        try:
            with open(project_path / 'script' / 'script.txt', 'w', encoding='utf-8') as f_sc:
                f_sc.write(req_data['script_text'])
        except Exception:
            pass
            
    # Save SRT to subtitles/ folder if present
    if req_data.get('srt_content'):
        try:
            with open(project_path / 'subtitles' / 'subtitles.srt', 'w', encoding='utf-8') as f_sb:
                f_sb.write(req_data['srt_content'])
        except Exception:
            pass
            
    meta_file = project_path / 'project.json'
    with open(meta_file, 'w', encoding='utf-8') as f:
        json.dump(req_data, f, ensure_ascii=False, indent=2)
        
    return jsonify({'success': True, 'saved_at': datetime.datetime.now().strftime('%H:%M:%S')})


@app.route('/api/projects/<project_id>/load', methods=['GET'])
def load_project(project_id):
    """Load project state from project.json."""
    clean_pid = sanitize_project_id(project_id)
    if not clean_pid:
        return jsonify({'error': 'Invalid project_id'}), 400
    project_path = PROJECTS_DIR / clean_pid
    meta_file = project_path / 'project.json'
    if not meta_file.is_file():
        return jsonify({'error': 'Project not found'}), 404
        
    try:
        with open(meta_file, 'r', encoding='utf-8') as f:
            data = json.load(f)
        return jsonify({'success': True, 'project': data})
    except Exception as e:
        return jsonify({'error': str(e)}), 500


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------


# ---------------------------------------------------------------------------
# Server Lifecycle Management (Auto-Restart & Shutdown)
# ---------------------------------------------------------------------------

@app.route('/api/server/restart', methods=['POST'])
def api_server_restart():
    """Seamlessly restart Flask server process in background."""
    restart_server()
    return jsonify({'ok': True, 'message': 'Máy chủ đang tự động khởi động lại...'})

@app.route('/api/server/shutdown', methods=['POST'])
def api_server_shutdown():
    """Gracefully shutdown server process."""
    def _do_shutdown():
        time.sleep(0.8)
        os._exit(0)

    threading.Thread(target=_do_shutdown, daemon=True).start()
    return jsonify({'ok': True, 'message': 'Đã tắt máy chủ thành công.'})

if __name__ == '__main__':
    print("=" * 55)
    print(f"{'✅' if FFMPEG_AVAILABLE else '❌'}  FFmpeg: {'found' if FFMPEG_AVAILABLE else 'NOT found — install ffmpeg'}")
    print(f"✅  Edge-TTS: {'available (' + TTS_DEVICE + ')' if EDGE_TTS_AVAILABLE else 'not available'}")
    print("✅  AutoSub: ready (Gemini 3.6 Flash Cloud)")
    print()
    print("🎬  Slideshow Builder  →  http://localhost:8080")
    print("=" * 55)
    app.run(host='0.0.0.0', port=8080, debug=False, threaded=True)
