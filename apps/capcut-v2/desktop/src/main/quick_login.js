/**
 * apps/capcut-v2/desktop/src/main/quick_login.js
 * Browser Quick Login Manager conforming to RFC 8252 (OAuth 2.0 for Native Apps).
 * Generates PKCE code_verifier/code_challenge (S256), binds an ephemeral loopback HTTP server,
 * opens system default browser, and exchanges one-time authorization code for desktop session.
 */

const http = require('http');
const crypto = require('crypto');
const url = require('url');

class QuickLoginManager {
  constructor() {
    this.server = null;
    this.activeVerifier = null;
    this.activeChallenge = null;
    this.activeState = null;
    this.timeoutId = null;
    this.deferred = null;
  }

  /**
   * Start the Browser Quick Login flow.
   * @param {string} apiBaseUrl e.g. "https://www.2tamne.site"
   * @param {Function} openExternalFn e.g. shell.openExternal
   * @returns {Promise<{code: string, verifier: string}>}
   */
  start(apiBaseUrl, openExternalFn) {
    this.cancel(); // Abort any lingering session

    return new Promise((resolve, reject) => {
      this.deferred = { resolve, reject };

      try {
        // 1. Generate PKCE Verifier & S256 Challenge
        this.activeVerifier = crypto.randomBytes(48).toString('base64url');
        this.activeChallenge = crypto
          .createHash('sha256')
          .update(this.activeVerifier)
          .digest('base64url');

        // 2. Generate anti-CSRF State
        this.activeState = crypto.randomBytes(24).toString('hex');

        // 3. Bind Ephemeral Loopback Server
        this.server = http.createServer((req, res) => {
          this._handleHttpRequest(req, res);
        });

        this.server.on('error', (err) => {
          console.warn('[QuickLogin] Loopback server error:', err.message);
          this._cleanup();
          reject(new Error(`Lỗi khởi tạo cổng đăng nhập nội bộ: ${err.message}`));
        });

        this.server.listen(0, '127.0.0.1', () => {
          const port = this.server.address().port;
          const authUrl = `${apiBaseUrl}/index.php?app_auth=1&port=${port}&challenge=${encodeURIComponent(
            this.activeChallenge
          )}&state=${encodeURIComponent(this.activeState)}`;

          console.log(`[QuickLogin] Ephemeral loopback listening on port ${port}. Opening browser: ${authUrl}`);
          openExternalFn(authUrl);

          // 5-minute timeout safeguard
          this.timeoutId = setTimeout(() => {
            console.warn('[QuickLogin] Login session timed out after 5 minutes.');
            this.cancel('Hết thời gian chờ đăng nhập (5 phút). Vui lòng thử lại.');
          }, 300000);
        });
      } catch (err) {
        this._cleanup();
        reject(err);
      }
    });
  }

  _handleHttpRequest(req, res) {
    try {
      const parsedUrl = new URL(req.url, `http://${req.headers.host || '127.0.0.1'}`);
      if (parsedUrl.pathname !== '/callback') {
        res.writeHead(404, { 'Content-Type': 'text/plain' });
        res.end('Not Found');
        return;
      }

      const state = parsedUrl.searchParams.get('state');
      const code = parsedUrl.searchParams.get('code');

      if (!state || state !== this.activeState) {
        res.writeHead(400, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end('<h3>Lỗi xác thực: Mã trạng thái (State) không khớp hoặc phiên đã hết hạn.</h3>');
        return;
      }

      if (!code) {
        res.writeHead(400, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end('<h3>Lỗi xác thực: Không nhận được mã ủy quyền (Authorization Code).</h3>');
        return;
      }

      // Render friendly celebration page in user's browser
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      res.end(`
        <!DOCTYPE html>
        <html>
        <head>
          <meta charset="utf-8">
          <title>Đăng nhập thành công - 2TOOLNE AutoEdit</title>
          <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0b0c10; color: #fff; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
            .card { background: #161821; border: 1px solid rgba(59,130,246,0.3); border-radius: 18px; padding: 40px 32px; text-align: center; max-width: 440px; box-shadow: 0 20px 50px rgba(0,0,0,0.6); }
            .icon { font-size: 54px; margin-bottom: 16px; }
            h2 { font-size: 20px; font-weight: 700; margin: 0 0 10px 0; color: #60a5fa; }
            p { font-size: 14px; color: rgba(255,255,255,0.7); line-height: 1.5; margin: 0 0 20px 0; }
            .btn { background: #2563eb; color: #fff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; font-size: 13px; }
          </style>
        </head>
        <body>
          <div class="card">
            <div class="icon">⚡</div>
            <h2>Đăng Nhập Thành Công!</h2>
            <p>Ứng dụng <b>2TOOLNE AutoEdit Desktop</b> đã được kết nối an toàn. Bạn có thể đóng tab trình duyệt này và quay lại ứng dụng.</p>
            <button class="btn" onclick="window.close()">Đóng Tab Này</button>
          </div>
          <script>
            setTimeout(function() { window.close(); }, 4000);
          </script>
        </body>
        </html>
      `);

      const result = {
        code,
        verifier: this.activeVerifier,
      };

      this._cleanup();
      if (this.deferred) {
        this.deferred.resolve(result);
        this.deferred = null;
      }
    } catch (e) {
      console.warn('[QuickLogin] Error processing callback request:', e.message);
    }
  }

  /**
   * Fallback handler for toolne://auth/callback?code=...&state=...
   */
  handleCustomProtocol(rawUrl) {
    if (!this.deferred || !this.activeState) return false;
    try {
      const parsedUrl = new URL(rawUrl);
      const proto = parsedUrl.protocol;
      if ((proto !== 'toolne:' && proto !== 'twotoolne:' && proto !== '2toolne:') || parsedUrl.hostname !== 'auth') return false;

      const params = parsedUrl.searchParams;
      const state = params.get('state');
      const code = params.get('code');

      if (state && state === this.activeState && code) {
        console.log('[QuickLogin] Custom protocol callback matched successfully!');
        const result = {
          code,
          verifier: this.activeVerifier,
        };
        this._cleanup();
        this.deferred.resolve(result);
        this.deferred = null;
        return true;
      }
    } catch (err) {
      console.warn('[QuickLogin] Failed to parse custom protocol URL:', err.message);
    }
    return false;
  }

  cancel(reason = 'Đã hủy phiên đăng nhập.') {
    this._cleanup();
    if (this.deferred) {
      this.deferred.reject(new Error(reason));
      this.deferred = null;
    }
  }

  _cleanup() {
    if (this.timeoutId) {
      clearTimeout(this.timeoutId);
      this.timeoutId = null;
    }
    if (this.server) {
      try {
        this.server.close();
      } catch (_) {}
      this.server = null;
    }
    this.activeState = null;
    this.activeVerifier = null;
    this.activeChallenge = null;
  }
}

module.exports = {
  QuickLoginManager,
};
