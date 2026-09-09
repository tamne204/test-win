# -*- mode: python ; coding: utf-8 -*-
import os
import sys
SPEC_DIR = os.path.dirname(os.path.abspath(SPEC))
V2_ROOT = os.path.abspath(os.path.join(SPEC_DIR, '..'))
if V2_ROOT not in sys.path:
    sys.path.insert(0, V2_ROOT)

from PyInstaller.utils.hooks import collect_data_files, collect_submodules

ENTRY_POINT = os.path.join(V2_ROOT, 'desktop_bridge', 'sidecar_main.py')

datas = []
for pkg in ('faster_whisper', 'ctranslate2'):
    try:
        datas += collect_data_files(pkg)
    except Exception:
        pass

hiddenimports = [
    'PIL', 'PIL.Image', 'PIL.ImageDraw', 'PIL.ImageFont',
    'cryptography', 'requests', 'psutil', 'numpy', 'soundfile',
    'capcut_version',
]
for pkg in ('core', 'adapters', 'desktop_bridge', 'faster_whisper', 'ctranslate2'):
    try:
        subs = collect_submodules(pkg)
        if subs:
            hiddenimports += subs
    except Exception:
        pass

if not sys.platform.startswith('win'):
    hiddenimports += ['fcntl', 'plistlib']
excludes = ['fcntl', 'plistlib'] if sys.platform.startswith('win') else []

a = Analysis(
    [ENTRY_POINT],
    pathex=[V2_ROOT],
    binaries=[],
    datas=datas,
    hiddenimports=hiddenimports,
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=excludes,
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
    upx=False,
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
    upx=False,
    upx_exclude=[],
    name='autoedit-core',
)
