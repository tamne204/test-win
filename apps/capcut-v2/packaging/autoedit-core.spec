# -*- mode: python ; coding: utf-8 -*-
from PyInstaller.utils.hooks import collect_data_files

datas = []
datas += collect_data_files('faster_whisper')


a = Analysis(
    ['/Users/2tamne/tool ffmpeg/apps/capcut-v2/desktop_bridge/sidecar_main.py'],
    pathex=['/Users/2tamne/tool ffmpeg/apps/capcut-v2'],
    binaries=[],
    datas=datas,
    hiddenimports=['core', 'core.edit_plan', 'core.rule_engine', 'core.preset_manager', 'core.srt_timeline', 'core.timeline_builder', 'core.subtitles', 'core.subtitles.models', 'core.subtitles.script_normalizer', 'core.subtitles.speech_timestamp_provider', 'core.subtitles.script_aligner', 'core.subtitles.subtitle_segmenter', 'core.subtitles.srt_generator', 'core.subtitles.pipeline', 'adapters', 'adapters.capcut', 'adapters.capcut.detector', 'adapters.capcut.adapter', 'adapters.capcut.registry', 'adapters.capcut.version_9_3', 'adapters.capcut.validator', 'adapters.capcut.project_manager', 'adapters.capcut.launcher', 'desktop_bridge', 'desktop_bridge.protocol', 'desktop_bridge.bridge', 'desktop_bridge.sidecar_main', 'PIL', 'PIL.Image', 'PIL.ImageDraw', 'PIL.ImageFont', 'fcntl', 'plistlib'],
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=[],
    noarchive=False,
    optimize=0,
)
pyz = PYZ(a.pure)

exe = EXE(
    pyz,
    a.scripts,
    [],
    exclude_binaries=True,
    name='autoedit-core',
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=True,
    console=True,
    disable_windowed_traceback=False,
    argv_emulation=False,
    target_arch=None,
    codesign_identity=None,
    entitlements_file=None,
)
coll = COLLECT(
    exe,
    a.binaries,
    a.datas,
    strip=False,
    upx=True,
    upx_exclude=[],
    name='autoedit-core',
)
