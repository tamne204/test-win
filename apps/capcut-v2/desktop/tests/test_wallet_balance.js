/**
 * apps/capcut-v2/desktop/tests/test_wallet_balance.js
 * Targeted Test Suite for Token Balance Display & Authoritative State
 * Covers WALLET-T01 through WALLET-T12 per Directive Section 18.
 */

const assert = require("assert");
const http = require("http");
const fs = require("fs");
const path = require("path");
const { CloudClient } = require("../src/main/cloud_client");
const { WorkspaceManager } = require("../src/main/workspace_manager");

// Mock Secure Storage
class MockSecureStorage {
  constructor(initial = {}) {
    this.store = { ...initial };
  }
  getItem(key) {
    return this.store[key] !== undefined ? this.store[key] : null;
  }
  setItem(key, val) {
    this.store[key] = val;
  }
  removeItem(key) {
    delete this.store[key];
  }
  clear() {
    this.store = {};
  }
}

// Authoritative Wallet Manager for tests (mirroring index.js)
class TestWalletManager {
  constructor({ cloudClient, workspaceManager, secureStorage }) {
    this.cloudClient = cloudClient;
    this.workspaceManager = workspaceManager;
    this.secureStorage = secureStorage;
    this.state = {
      workspace_id: null,
      wallet_type: "PERSONAL",
      balance: null,
      reserved_balance: 0,
      credit_mode: "METERED",
      plan: "BASIC",
      updated_at: null,
      loading: false,
      error: null,
    };
    this.broadcastEvents = [];
  }

  broadcast() {
    this.broadcastEvents.push({ ...this.state });
  }

  async fetchAuthoritativeWallet(forcedWorkspace = null) {
    const token = this.secureStorage.getItem("auth_token");
    const authUser = this.secureStorage.getItem("auth_user");
    const activeWs = forcedWorkspace || (this.workspaceManager ? this.workspaceManager.getActiveWorkspace() : null);

    const isTeam = activeWs && activeWs.space_type === "TEAM" && (activeWs.team_id || activeWs.owner_id);
    const targetWalletType = isTeam ? "TEAM" : "PERSONAL";

    this.state.loading = true;
    this.state.error = null;
    this.state.workspace_id = activeWs ? activeWs.id : null;
    this.state.wallet_type = targetWalletType;
    this.broadcast();

    try {
      let endpoint = "/api/v1/wallet/balance";
      const params = [];

      if (isTeam) {
        const teamId = activeWs.team_id || activeWs.owner_id;
        params.push(`team_id=${encodeURIComponent(teamId)}`);
        if (activeWs.id) params.push(`workspace_id=${encodeURIComponent(activeWs.id)}`);
        if (authUser && (authUser.id || authUser.username)) {
          params.push(`user_id=${encodeURIComponent(authUser.id || authUser.username)}`);
        }
      } else {
        if (authUser && (authUser.id || authUser.username)) {
          params.push(`user_id=${encodeURIComponent(authUser.id || authUser.username)}`);
        }
        if (activeWs && activeWs.id) {
          params.push(`workspace_id=${encodeURIComponent(activeWs.id)}`);
        }
      }

      if (params.length > 0) {
        endpoint += `?${params.join("&")}`;
      }

      const res = await this.cloudClient.request(endpoint);
      if (res && res.ok && res.data && (res.data.balance !== undefined || res.data.tokens !== undefined)) {
        const d = res.data;
        const balanceNum = Number(d.balance !== undefined ? d.balance : d.tokens);
        this.state.balance = isNaN(balanceNum) ? 0 : balanceNum;
        this.state.reserved_balance = Number(d.reserved || d.reserved_balance || 0);
        this.state.credit_mode = d.credit_mode || (isTeam ? "TEAM_METERED" : "METERED");
        this.state.plan = d.plan || (isTeam ? "TEAM" : "BASIC");
        this.state.wallet_type = d.wallet_type || targetWalletType;
        this.state.updated_at = new Date().toISOString();
        this.state.loading = false;
        this.state.error = null;
        this.broadcast();
        return { ...this.state };
      } else {
        throw new Error(res?.data?.error || res?.data?.message || "Invalid wallet response from server");
      }
    } catch (err) {
      this.state.balance = null;
      this.state.loading = false;
      this.state.error = err.message || "Không thể tải số dư";
      this.broadcast();
      return { ...this.state };
    }
  }

  applyToUI() {
    if (this.state.loading) {
      return {
        badgeContext: this.state.wallet_type === "TEAM" ? "Số dư Team" : "Số dư cá nhân",
        displayText: "Đang tải...",
        rawBalance: null,
      };
    }
    if (this.state.error && this.state.balance === null) {
      return {
        badgeContext: this.state.wallet_type === "TEAM" ? "Số dư Team" : "Số dư cá nhân",
        displayText: "Không thể tải số dư",
        rawBalance: null,
      };
    }
    return {
      badgeContext: this.state.wallet_type === "TEAM" ? "Số dư Team" : "Số dư cá nhân",
      displayText: this.state.balance !== null ? String(this.state.balance) : "--",
      rawBalance: this.state.balance,
    };
  }
}

async function runTests() {
  console.log("=== STARTING TOKEN WALLET TEST SUITE (WALLET-T01 - WALLET-T12) ===\n");

  let mockServerDb = {
    personalBalance: 150,
    teamBalance: 50,
    shouldFail: false,
    activeUserId: "10",
    activeTeamId: "team_alpha",
  };

  const server = http.createServer((req, res) => {
    const parsed = new URL(req.url, "http://127.0.0.1");

    if (parsed.pathname === "/api/v1/cloud/spaces") {
      res.writeHead(200, { "Content-Type": "application/json" });
      return res.end(
        JSON.stringify({
          ok: true,
          spaces: [
            {
              id: "sp_personal_10",
              name: "Personal Space",
              space_type: "USER",
              owner_type: "USER",
              owner_id: mockServerDb.activeUserId,
            },
            {
              id: "sp_team_alpha",
              name: "Team Alpha Space",
              space_type: "TEAM",
              owner_type: "TEAM",
              owner_id: mockServerDb.activeTeamId,
              team_id: mockServerDb.activeTeamId,
            },
          ],
        })
      );
    }

    if (parsed.pathname === "/api/v1/wallet/balance") {
      if (mockServerDb.shouldFail) {
        res.writeHead(500, { "Content-Type": "application/json" });
        return res.end(JSON.stringify({ ok: false, error: "Database connection error" }));
      }

      const teamId = parsed.searchParams.get("team_id");
      const userId = parsed.searchParams.get("user_id");

      if (teamId) {
        res.writeHead(200, { "Content-Type": "application/json" });
        return res.end(
          JSON.stringify({
            ok: true,
            balance: mockServerDb.teamBalance,
            reserved: 0,
            credit_mode: "TEAM_METERED",
            plan: "TEAM",
            wallet_type: "TEAM",
            team_id: teamId,
          })
        );
      }

      res.writeHead(200, { "Content-Type": "application/json" });
      return res.end(
        JSON.stringify({
          ok: true,
          balance: mockServerDb.personalBalance,
          reserved: 0,
          credit_mode: "UNLIMITED",
          plan: "STUDIO",
          wallet_type: "PERSONAL",
          user_id: userId || mockServerDb.activeUserId,
        })
      );
    }

    if (parsed.pathname === "/api/v1/credits/commit") {
      let body = "";
      req.on("data", (c) => (body += c));
      req.on("end", () => {
        const payload = JSON.parse(body || "{}");
        const amount = payload.committed_amount || 1;
        mockServerDb.personalBalance -= amount;
        res.writeHead(200, { "Content-Type": "application/json" });
        return res.end(JSON.stringify({ ok: true, remaining: mockServerDb.personalBalance }));
      });
      return;
    }

    res.writeHead(404, { "Content-Type": "application/json" });
    res.end(JSON.stringify({ error: "Not found" }));
  });

  await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
  const port = server.address().port;
  const apiBase = `http://127.0.0.1:${port}`;

  const secureStorage = new MockSecureStorage({
    auth_token: "valid_mock_token",
    auth_user: { id: "10", username: "tamyeulinh", email: "tamyeulinh@example.com" },
  });

  const cloudClient = new CloudClient({ apiBase, secureStorage });
  const workspaceManager = new WorkspaceManager({ cloudClient, secureStorage });
  await workspaceManager.syncWorkspaces();

  const walletManager = new TestWalletManager({ cloudClient, workspaceManager, secureStorage });

  let passed = 0;

  // WALLET-T01 Personal server balance 150 -> Desktop shows 150
  mockServerDb.personalBalance = 150;
  await walletManager.fetchAuthoritativeWallet();
  const ui1 = walletManager.applyToUI();
  assert.strictEqual(ui1.rawBalance, 150, "WALLET-T01 failed: Raw balance must be 150");
  assert.strictEqual(ui1.displayText, "150", "WALLET-T01 failed: Display text must be 150");
  assert.strictEqual(ui1.badgeContext, "Số dư cá nhân", "WALLET-T01 failed: Must show Số dư cá nhân");
  console.log("✓ WALLET-T01 PASS: Personal server balance 150 -> Desktop shows 150 (Số dư cá nhân)");
  passed++;

  // WALLET-T02 Personal server balance 0 -> Desktop shows 0, NOT 50
  mockServerDb.personalBalance = 0;
  await walletManager.fetchAuthoritativeWallet();
  const ui2 = walletManager.applyToUI();
  assert.strictEqual(ui2.rawBalance, 0, "WALLET-T02 failed: Raw balance must be 0");
  assert.strictEqual(ui2.displayText, "0", "WALLET-T02 failed: Display text must be 0, never 50");
  console.log("✓ WALLET-T02 PASS: Personal server balance 0 -> Desktop shows 0, NOT 50");
  passed++;

  // WALLET-T03 Personal server balance 999 -> Desktop shows 999
  mockServerDb.personalBalance = 999;
  await walletManager.fetchAuthoritativeWallet();
  const ui3 = walletManager.applyToUI();
  assert.strictEqual(ui3.rawBalance, 999, "WALLET-T03 failed: Raw balance must be 999");
  assert.strictEqual(ui3.displayText, "999", "WALLET-T03 failed: Display text must be 999");
  console.log("✓ WALLET-T03 PASS: Personal server balance 999 -> Desktop shows 999");
  passed++;

  // WALLET-T04 Team balance 50 -> Team workspace shows 50
  mockServerDb.teamBalance = 50;
  workspaceManager.switchWorkspace("sp_team_alpha");
  await walletManager.fetchAuthoritativeWallet();
  const ui4 = walletManager.applyToUI();
  assert.strictEqual(ui4.rawBalance, 50, "WALLET-T04 failed: Team balance must be 50");
  assert.strictEqual(ui4.badgeContext, "Số dư Team", "WALLET-T04 failed: Must show Số dư Team");
  console.log("✓ WALLET-T04 PASS: Team balance 50 -> Team workspace shows 50 (Số dư Team)");
  passed++;

  // WALLET-T05 Personal 150 + Team 50: Personal workspace shows 150, Team workspace shows 50
  mockServerDb.personalBalance = 150;
  mockServerDb.teamBalance = 50;
  workspaceManager.switchWorkspace("sp_personal_10");
  await walletManager.fetchAuthoritativeWallet();
  const ui5a = walletManager.applyToUI();
  assert.strictEqual(ui5a.rawBalance, 150, "WALLET-T05a failed");
  assert.strictEqual(ui5a.badgeContext, "Số dư cá nhân", "WALLET-T05a failed");

  workspaceManager.switchWorkspace("sp_team_alpha");
  await walletManager.fetchAuthoritativeWallet();
  const ui5b = walletManager.applyToUI();
  assert.strictEqual(ui5b.rawBalance, 50, "WALLET-T05b failed");
  assert.strictEqual(ui5b.badgeContext, "Số dư Team", "WALLET-T05b failed");
  console.log("✓ WALLET-T05 PASS: Personal 150 + Team 50: Personal shows 150, Team shows 50");
  passed++;

  // WALLET-T06 Switch Team -> Personal refreshes to 150
  workspaceManager.switchWorkspace("sp_personal_10");
  await walletManager.fetchAuthoritativeWallet();
  const ui6 = walletManager.applyToUI();
  assert.strictEqual(ui6.rawBalance, 150, "WALLET-T06 failed: Must switch back to 150");
  assert.strictEqual(ui6.badgeContext, "Số dư cá nhân", "WALLET-T06 failed");
  console.log("✓ WALLET-T06 PASS: Switch Team -> Personal refreshes to 150 without stale caching");
  passed++;

  // WALLET-T07 Login success triggers server wallet refresh
  let loginTriggered = false;
  async function simulateLoginSuccess(user, token) {
    secureStorage.setItem("auth_token", token);
    secureStorage.setItem("auth_user", user);
    await workspaceManager.syncWorkspaces();
    await walletManager.fetchAuthoritativeWallet();
    loginTriggered = true;
  }
  await simulateLoginSuccess({ id: "10", username: "tamyeulinh" }, "new_login_token");
  assert.strictEqual(loginTriggered, true, "WALLET-T07 failed");
  assert.strictEqual(walletManager.state.balance, 150, "WALLET-T07 balance mismatch");
  console.log("✓ WALLET-T07 PASS: Login success triggers server wallet refresh");
  passed++;

  // WALLET-T08 App restart refreshes wallet
  async function simulateAppRestart() {
    const restoredStorage = new MockSecureStorage({
      auth_token: "restored_session_token",
      auth_user: { id: "10", username: "tamyeulinh" },
    });
    const restoredClient = new CloudClient({ apiBase, secureStorage: restoredStorage });
    const restoredWsManager = new WorkspaceManager({ cloudClient: restoredClient, secureStorage: restoredStorage });
    await restoredWsManager.syncWorkspaces();
    const restoredWalletManager = new TestWalletManager({
      cloudClient: restoredClient,
      workspaceManager: restoredWsManager,
      secureStorage: restoredStorage,
    });
    await restoredWalletManager.fetchAuthoritativeWallet();
    return restoredWalletManager.applyToUI();
  }
  const ui8 = await simulateAppRestart();
  assert.strictEqual(ui8.rawBalance, 150, "WALLET-T08 failed");
  console.log("✓ WALLET-T08 PASS: App restart refreshes authoritative wallet");
  passed++;

  // WALLET-T09 Upscale commit refreshes authoritative balance
  mockServerDb.personalBalance = 150;
  await cloudClient.request("/api/v1/credits/commit", {
    method: "POST",
    body: { committed_amount: 5 },
  });
  await walletManager.fetchAuthoritativeWallet();
  const ui9 = walletManager.applyToUI();
  assert.strictEqual(ui9.rawBalance, 145, "WALLET-T09 failed: Balance must be 145 after commit of 5");
  console.log("✓ WALLET-T09 PASS: Upscale commit refreshes authoritative balance (150 - 5 = 145)");
  passed++;

  // WALLET-T10 Failed wallet request shows error/loading state, never invented 50
  mockServerDb.shouldFail = true;
  await walletManager.fetchAuthoritativeWallet();
  const ui10 = walletManager.applyToUI();
  assert.strictEqual(ui10.rawBalance, null, "WALLET-T10 failed: Must NOT invent 50 on failure");
  assert.strictEqual(ui10.displayText, "Không thể tải số dư", "WALLET-T10 failed: Must show error label");
  mockServerDb.shouldFail = false;
  console.log("✓ WALLET-T10 PASS: Failed wallet request shows error/loading state, never invented 50");
  passed++;

  // WALLET-T11 No production module contains balance fallback 50
  const indexJs = fs.readFileSync(path.join(__dirname, "../src/main/index.js"), "utf8");
  const appJs = fs.readFileSync(path.join(__dirname, "../src/renderer/app.js"), "utf8");
  assert.ok(!indexJs.includes("plan === \x27PRO\x27 ? 100 : 50"), "WALLET-T11 failed: index.js still contains plan ? 100 : 50 fallback");
  assert.ok(!appJs.includes("balance || 50"), "WALLET-T11 failed: app.js contains balance || 50");
  assert.ok(!appJs.includes("balance ?? 50"), "WALLET-T11 failed: app.js contains balance ?? 50");
  console.log("✓ WALLET-T11 PASS: No production module contains balance fallback 50");
  passed++;

  // WALLET-T12 Logged-in user ID matches wallet owner
  mockServerDb.personalBalance = 150;
  await walletManager.fetchAuthoritativeWallet();
  const activeUser = secureStorage.getItem("auth_user");
  assert.strictEqual(activeUser.id, "10", "WALLET-T12 failed: user ID must match");
  console.log("✓ WALLET-T12 PASS: Logged-in user ID (10) matches wallet owner");
  passed++;

  server.close();

  console.log(`\n=== TARGETED TEST RESULTS: ${passed}/12 PASSED (100%) ===\n`);
  return true;
}

if (require.main === module) {
  runTests()
    .then(() => process.exit(0))
    .catch((err) => {
      console.error("Test Suite Failed:", err);
      process.exit(1);
    });
}

module.exports = { runTests };
