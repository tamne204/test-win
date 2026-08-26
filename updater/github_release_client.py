"""
github_release_client.py
Communicates with GitHub Releases API or 2tamne.site License Server Proxy
to fetch latest release metadata without exposing private repository tokens.
"""

import os
import re
import requests
from typing import Dict, Any, Optional
from version import GITHUB_REPO, RELEASE_PROXY_URL
from .platform_detector import get_platform_tag


def fetch_latest_release(
    repo: Optional[str] = None,
    token: Optional[str] = None,
    proxy_url: Optional[str] = None,
    timeout: int = 8
) -> Dict[str, Any]:
    """
    Fetch metadata for the latest release.
    Strategy:
    1. If proxy_url is configured, query proxy server first (ideal for private repo distribution).
    2. Fallback to GitHub API (api.github.com/repos/{repo}/releases/latest) with env GITHUB_TOKEN if available.
    Returns normalized dictionary with ok: bool.
    """
    target_repo = repo or GITHUB_REPO
    target_proxy = proxy_url or RELEASE_PROXY_URL
    gh_token = token or os.environ.get("GITHUB_TOKEN", "").strip()

    # 1. Try secure Release Proxy (2tamne.site) first
    if target_proxy:
        try:
            resp = requests.get(
                target_proxy,
                params={"repo": target_repo, "client_platform": get_platform_tag()},
                timeout=timeout,
                headers={"User-Agent": "TamneSlideshowUpdater/2.0"}
            )
            if resp.status_code == 200:
                data = resp.json()
                if data.get("ok"):
                    return data
        except Exception:
            pass  # Fall through to direct GitHub API

    # 2. Query GitHub Releases API directly
    api_url = f"https://api.github.com/repos/{target_repo}/releases/latest"
    headers = {
        "User-Agent": "TamneSlideshowUpdater/2.0",
        "Accept": "application/vnd.github.v3+json"
    }
    if gh_token:
        headers["Authorization"] = f"token {gh_token}"

    try:
        resp = requests.get(api_url, headers=headers, timeout=timeout)
        if resp.status_code == 200:
            raw = resp.json()
            tag_name = raw.get("tag_name", "").strip()
            version_str = tag_name.lstrip("vV")
            
            assets_list = []
            for a in raw.get("assets", []):
                aname = a.get("name", "")
                durl = a.get("browser_download_url", "")
                
                # Match platform tag from filename
                matched_plat = None
                for ptag in ("win-x64", "mac-arm64", "mac-x64", "linux-x64"):
                    if ptag in aname.lower():
                        matched_plat = ptag
                        break
                
                assets_list.append({
                    "name": aname,
                    "download_url": durl,
                    "platform_tag": matched_plat,
                    "size": a.get("size", 0)
                })

            return {
                "ok": True,
                "tag_name": tag_name,
                "version": version_str,
                "release_notes": raw.get("body", "").strip(),
                "published_at": raw.get("published_at", ""),
                "html_url": raw.get("html_url", f"https://github.com/{target_repo}/releases"),
                "assets": assets_list,
                "error": None
            }
        elif resp.status_code == 404:
            return {
                "ok": False,
                "error": "NO_RELEASES_FOUND",
                "message": "Chưa có bản phát hành (Release) nào trên GitHub."
            }
        elif resp.status_code == 403:
            return {
                "ok": False,
                "error": "RATE_LIMITED_OR_PRIVATE",
                "message": "GitHub API bị giới hạn yêu cầu hoặc Repository ở chế độ Private."
            }
        else:
            return {
                "ok": False,
                "error": f"HTTP_{resp.status_code}",
                "message": f"GitHub API phản hồi mã lỗi {resp.status_code}"
            }
    except requests.exceptions.RequestException as e:
        return {
            "ok": False,
            "error": "OFFLINE_OR_TIMEOUT",
            "message": f"Không thể kết nối đến máy chủ cập nhật: {e}"
        }
