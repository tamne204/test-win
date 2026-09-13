#!/usr/bin/env python3
"""
generate_brand_icons.py

Generates complete multi-resolution icon assets for 2TOOLNE AutoEdit:
1. High-precision antialiased squircle masking.
2. Master 1024x1024 PNG.
3. Multi-resolution Windows .ico (16, 24, 32, 48, 64, 128, 256).
4. macOS .icns via native iconutil.
5. Web favicon and PWA icons.
"""

import os
import shutil
import subprocess
from PIL import Image, ImageDraw

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
REPO_ROOT = os.path.abspath(os.path.join(SCRIPT_DIR, "../../../.."))
ASSETS_DIR = os.path.join(REPO_ROOT, "apps/capcut-v2/desktop/assets")
RENDERER_ASSETS_DIR = os.path.join(REPO_ROOT, "apps/capcut-v2/desktop/src/renderer/assets")
WEBSITE_ASSETS_DIR = os.path.join(REPO_ROOT, "website/assets")
WEBSITE_ROOT = os.path.join(REPO_ROOT, "website")
MASTER_PNG = os.path.join(ASSETS_DIR, "icon.png")
print(f"Loading official brand master: {MASTER_PNG}...")
master_rgba = Image.open(MASTER_PNG).convert('RGBA')

renderer_png_path = os.path.join(RENDERER_ASSETS_DIR, "icon.png")
master_rgba.save(renderer_png_path, "PNG")
print(f"Saved renderer icon: {renderer_png_path}")

# 4. Generate Windows .ico with multi-resolution mipmaps (16, 24, 32, 48, 64, 128, 256)
ico_sizes = [(16, 16), (24, 24), (32, 32), (48, 48), (64, 64), (128, 128), (256, 256)]
ico_path = os.path.join(ASSETS_DIR, "icon.ico")
master_rgba.save(ico_path, format="ICO", sizes=ico_sizes)
print(f"Saved Windows multi-res ICO: {ico_path}")

# Also copy installer icons
shutil.copy2(ico_path, os.path.join(ASSETS_DIR, "installerIcon.ico"))
shutil.copy2(ico_path, os.path.join(ASSETS_DIR, "uninstallerIcon.ico"))

# 5. Generate macOS .icns via iconutil
iconset_dir = os.path.join(ASSETS_DIR, "icon.iconset")
if os.path.exists(iconset_dir):
    shutil.rmtree(iconset_dir)
os.makedirs(iconset_dir, exist_ok=True)

iconset_specs = [
    ("icon_16x16.png", (16, 16)),
    ("icon_16x16@2x.png", (32, 32)),
    ("icon_32x32.png", (32, 32)),
    ("icon_32x32@2x.png", (64, 64)),
    ("icon_128x128.png", (128, 128)),
    ("icon_128x128@2x.png", (256, 256)),
    ("icon_256x256.png", (256, 256)),
    ("icon_256x256@2x.png", (512, 512)),
    ("icon_512x512.png", (512, 512)),
    ("icon_512x512@2x.png", (1024, 1024)),
]

for filename, size in iconset_specs:
    resized = master_rgba.resize(size, Image.Resampling.LANCZOS)
    resized.save(os.path.join(iconset_dir, filename), "PNG")

icns_path = os.path.join(ASSETS_DIR, "icon.icns")
try:
    subprocess.run(["iconutil", "-c", "icns", iconset_dir, "-o", icns_path], check=True)
    print(f"Saved macOS ICNS: {icns_path}")
finally:
    shutil.rmtree(iconset_dir)

# 6. Generate Web & PWA assets
web_fav_ico = os.path.join(WEBSITE_ROOT, "favicon.ico")
master_rgba.save(web_fav_ico, format="ICO", sizes=[(16, 16), (32, 32), (48, 48)])
print(f"Saved web favicon: {web_fav_ico}")

web_specs = [
    ("favicon.png", (64, 64)),
    ("favicon-32.png", (32, 32)),
    ("apple-touch-icon.png", (180, 180)),
    ("icon-192.png", (192, 192)),
    ("icon-512.png", (512, 512)),
]

for filename, size in web_specs:
    target = os.path.join(WEBSITE_ASSETS_DIR, filename)
    resized = master_rgba.resize(size, Image.Resampling.LANCZOS)
    resized.save(target, "PNG")
    print(f"Saved web asset: {target}")

print("All brand icon assets successfully generated!")
