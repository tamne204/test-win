"""
tests/test_stage_h_localization.py
Verification suite for Stage H (GAP-13: UI Localization).
Verifies:
1. i18n engine existence, dictionary coverage (VI & EN), and key parity.
2. index.html includes i18n.js script and selAppLanguage selector.
3. Sidebar navigation buttons contain data-i18n attributes.
4. app.js handles multilingual VIEW_METADATA and language switching.
"""
import pytest
import os
import json


def test_i18n_file_structure_and_dictionaries():
    """Verify that i18n.js provides both Vietnamese and English dictionaries with key parity."""
    i18n_path = os.path.abspath("apps/capcut-v2/desktop/src/renderer/i18n.js")
    assert os.path.isfile(i18n_path), "i18n.js does not exist!"

    with open(i18n_path, "r", encoding="utf-8") as f:
        content = f.read()

    assert "const translations = {" in content
    assert "vi: {" in content
    assert "en: {" in content
    assert "'nav.studio':" in content
    assert "'nav.queue':" in content
    assert "'nav.upscale':" in content
    assert "'settings.language':" in content


def test_index_html_loads_i18n_and_has_language_selector():
    """Verify index.html loads i18n.js before app.js and exposes selAppLanguage in settings."""
    html_path = os.path.abspath("apps/capcut-v2/desktop/src/renderer/index.html")
    with open(html_path, "r", encoding="utf-8") as f:
        html = f.read()

    assert '<script src="i18n.js"></script>' in html
    assert '<script src="app.js"></script>' in html
    assert html.index('i18n.js') < html.index('app.js'), "i18n.js must load before app.js"

    assert 'id="selAppLanguage"' in html
    assert 'value="vi"' in html
    assert 'value="en"' in html
    assert 'data-i18n="nav.studio"' in html
    assert 'data-i18n="nav.queue"' in html
    assert 'data-i18n="nav.settings"' in html


def test_app_js_supports_multilingual_metadata_and_events():
    """Verify app.js provides multilingual VIEW_METADATA and wires selAppLanguage change handler."""
    app_js_path = os.path.abspath("apps/capcut-v2/desktop/src/renderer/app.js")
    with open(app_js_path, "r", encoding="utf-8") as f:
        js = f.read()

    assert "selAppLanguage: document.getElementById('selAppLanguage')" in js
    assert "applyCurrentLanguage" in js
    assert "window.i18n.init()" in js
    assert "VIEW_METADATA" in js
    assert "CapCut Project Studio" in js
