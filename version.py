"""
version.py
Single source of truth for application versioning.
Follows Semantic Versioning (MAJOR.MINOR.PATCH).
"""

__version__ = "2.2.3.17"
APP_NAME = "Slideshow Builder AI"
GITHUB_REPO = "tamne204/ffmpeg-tool"
RELEASE_PROXY_URL = "https://www.2tamne.site/api/license/check_update.php"


def get_version() -> str:
    """Return current application semantic version string."""
    return __version__


def get_app_name() -> str:
    """Return user-facing application name."""
    return APP_NAME


def get_github_repo() -> str:
    """Return official GitHub repository identifier."""
    return GITHUB_REPO
