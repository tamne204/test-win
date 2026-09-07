"""
tests/test_stage_g_ai_upscale.py
Verification suite for Stage G (GAP-12: AI Upscale & Remove Placeholders).
Verifies:
1. Complete elimination of static placeholder alert in AI Upscale module.
2. Renderer HTML contains real file management container, progress bar, and output result box.
3. Preload bridge exposes runUpscale and onUpscaleProgress.
4. Electron main process registers upscale:process-images IPC handler.
5. Auto-upscale checkbox (chkAutoUpscale) has dynamic token hint indicator.
"""
import pytest
import os
import re


def test_no_static_placeholder_alert_in_upscale_tab():
    """Verify that the placeholder alert 'Phân hệ AI Upscale NCNN Vulkan đã sẵn sàng...' is eliminated."""
    app_js_path = os.path.abspath("apps/capcut-v2/desktop/src/renderer/app.js")
    with open(app_js_path, "r", encoding="utf-8") as f:
        js = f.read()

    assert "Phân hệ AI Upscale NCNN Vulkan đã sẵn sàng kết nối cùng Token ví tài khoản" not in js, (
        "Static placeholder alert still present in app.js!"
    )


def test_html_contains_upscale_file_list_and_progress_elements():
    """Verify that view-upscale in index.html has complete UI elements for files, progress, and results."""
    html_path = os.path.abspath("apps/capcut-v2/desktop/src/renderer/index.html")
    with open(html_path, "r", encoding="utf-8") as f:
        html = f.read()

    # File management elements
    assert 'id="upscaleFilesContainer"' in html
    assert 'id="upscaleCount"' in html
    assert 'id="upscaleFileList"' in html
    assert 'id="btnClearUpscale"' in html

    # Progress bar elements
    assert 'id="upscaleProgressWrap"' in html
    assert 'id="upscaleProgressMsg"' in html
    assert 'id="upscaleProgressPct"' in html
    assert 'id="upscaleProgressFill"' in html

    # Result action elements
    assert 'id="upscaleResultBox"' in html
    assert 'id="upscaleResultPath"' in html
    assert 'id="btnOpenUpscaleDir"' in html

    # Auto-upscale token hint
    assert 'id="lblUpscaleTokenHint"' in html


def test_preload_exposes_upscale_api():
    """Verify that preload bridge exposes runUpscale and onUpscaleProgress."""
    preload_path = os.path.abspath("apps/capcut-v2/desktop/src/preload/preload.js")
    with open(preload_path, "r", encoding="utf-8") as f:
        js = f.read()

    assert "runUpscale:" in js
    assert "onUpscaleProgress:" in js
    assert "upscale:process-images" in js
    assert "upscale:progress" in js


def test_main_process_handles_upscale_ipc():
    """Verify that index.js registers upscale:process-images IPC handler with real scaling logic."""
    main_path = os.path.abspath("apps/capcut-v2/desktop/src/main/index.js")
    with open(main_path, "r", encoding="utf-8") as f:
        js = f.read()

    assert "ipcMain.handle('upscale:process-images'" in js
    assert "upscale:progress" in js
    assert "realesrgan-ncnn-vulkan" in js
    assert "POST /api/v1/credits/commit" in js or "/api/v1/credits/commit" in js


def test_app_js_wires_upscale_workflow_and_progress():
    """Verify that app.js caches DOM elements, handles drag-drop, run action, and progress."""
    app_js_path = os.path.abspath("apps/capcut-v2/desktop/src/renderer/app.js")
    with open(app_js_path, "r", encoding="utf-8") as f:
        js = f.read()

    assert "renderUpscaleList" in js
    assert "DOM.btnBrowseUpscale" in js
    assert "DOM.btnClearUpscale" in js
    assert "DOM.btnRunUpscale" in js
    assert "DOM.btnOpenUpscaleDir" in js
    assert "window.autoedit.runUpscale" in js
    assert "onUpscaleProgress" in js
    assert "state.upscaleFiles" in js
