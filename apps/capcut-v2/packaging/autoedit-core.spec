# -*- mode: python ; coding: utf-8 -*-
import os
import sys
from PyInstaller.utils.hooks import collect_data_files, collect_submodules

SPEC_DIR = os.path.dirname(os.path.abspath(SPEC))
V2_ROOT = os.path.abspath(os.path.join(SPEC_DIR, '..'))
ENTRY_POINT = os.path.join(V2_ROOT, 'desktop_bridge', 'sidecar_main.py')

datas = []
try:
    datas += collect_data_files('faster_whisper')
except Exception:
    pass

hiddenimports = [
    'PIL', 'PIL.Image', 'PIL.ImageDraw', 'PIL.ImageFont',
    'cryptography', 'requests', 'psutil', 'numpy',
]
try:
    hiddenimports += collect_submodules('core')
    hiddenimports += collect_submodules('adapters')
    hiddenimports += collect_submodules('desktop_bridge')
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
