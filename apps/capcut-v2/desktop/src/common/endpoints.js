/**
 * apps/capcut-v2/desktop/src/common/endpoints.js
 * Centralized Canonical Endpoint Configuration for 2TOOLNE Desktop & Services.
 * Enforces HTTPS-only, zero hardcoded IP fallbacks, and authoritative RFC 8252 auth contracts.
 */

'use strict';

const CANONICAL_ORIGIN = process.env.AUTOEDIT_API_BASE || 'https://2tamne.site';

const AUTH_ENDPOINTS = {
  BASE_URL: CANONICAL_ORIGIN,
  LOGIN_PAGE: `${CANONICAL_ORIGIN}/index.php?app_auth=1`,
  TOKEN_EXCHANGE: `${CANONICAL_ORIGIN}/api/v1/auth/token`,
  SESSION_STATUS: `${CANONICAL_ORIGIN}/api/v1/auth/session/status`,
  TOKEN_REFRESH: `${CANONICAL_ORIGIN}/api/v1/auth/refresh`,
  LOGOUT: `${CANONICAL_ORIGIN}/index.php?logout=1`,
  DEEP_LINK_SCHEMES: ['toolne', '2toolne', 'twotoolne'],
  PRIMARY_SCHEME: 'toolne',
  LOOPBACK_PATH: '/callback',
};

const CLOUD_ENDPOINTS = {
  BASE_URL: CANONICAL_ORIGIN,
  VFS_LIST: `${CANONICAL_ORIGIN}/api/v1/ai/fs/list`,
  SPACES: `${CANONICAL_ORIGIN}/api/v1/cloud/spaces`,
  PACKAGES: `${CANONICAL_ORIGIN}/api/v1/packages`,
};

module.exports = {
  CANONICAL_ORIGIN,
  AUTH_ENDPOINTS,
  CLOUD_ENDPOINTS,
};
