/**
 * SEWN Connect Overlay — Wirebot + Network widget on member sites
 * 
 * A floating button that expands into a slide-out panel showing:
 * - Wirebot AI chat (via scoreboard /v1/chat proxy)
 * - Network stats from Ring Leader
 * - Member's scoreboard summary
 * - Quick links to the ecosystem
 * 
 * Loaded on member sites via the Connect plugin.
 * Auth: Ring Leader JWT stored in cookie or passed from WP session.
 */
(function() {
  'use strict';

  const RING_LEADER = window.sewnConnect?.ringLeaderUrl || 'https://startempirewire.network/wp-json/sewn/v1';
  const SCOREBOARD = window.sewnConnect?.scoreboardUrl || 'https://wins.wirebot.chat';
  const SITE_NAME = window.sewnConnect?.siteName || 'Startempire Wire';

  let state = {
    open: false,
    tab: 'chat', // chat | score | network | ring
    jwt: null,
    user: null,
    loading: true,
    chatMessages: [],
    chatLoading: false,
    chatSessionId: null,
    scoreData: null,
    networkStats: null,
    profileData: null, // Pairing profile effective
    webRing: null, // WebRing navigation data
    webRingLoading: false,
  };

  // ── Auth ──────────────────────────────────────────────────

  async function initAuth() {
    // Try to get JWT from cookie or WP session
    const cookie = getCookie('sewn_jwt');
    if (cookie) {
      state.jwt = cookie;
      await validateAndLoadUser();
      return;
    }

    // Try auth exchange if user is logged into this WP site
    if (window.sewnConnect?.nonce) {
      try {
        const resp = await fetch(window.sewnConnect.ajaxUrl || '/wp-json/sewn-connect/v1/auth/exchange', {
          method: 'POST',
          headers: { 
            'Content-Type': 'application/json',
            'X-WP-Nonce': window.sewnConnect.nonce 
          },
          credentials: 'same-origin'
        });
        if (resp.ok) {
          const data = await resp.json();
          if (data.jwt) {
            state.jwt = data.jwt;
            setCookie('sewn_jwt', data.jwt, 24); // 24hr
            await validateAndLoadUser();
            return;
          }
        }
      } catch (e) {
        console.warn('[SEWN] Auth exchange failed:', e);
      }
    }

    state.loading = false;
    render();
  }

  async function validateAndLoadUser() {
    try {
      const resp = await fetch(`${RING_LEADER}/auth/validate`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${state.jwt}` }
      });
      const data = await resp.json();
      if (data.valid) {
        state.user = data.user;
        // Load data in parallel
        loadScoreboard();
        loadNetworkStats();
      } else {
        state.jwt = null;
        removeCookie('sewn_jwt');
      }
    } catch (e) {
      console.warn('[SEWN] Validation failed:', e);
    }
    state.loading = false;
    render();
  }

  // ── Data Loading ──────────────────────────────────────────

  async function loadScoreboard() {
    if (!state.jwt) return;
    try {
      const resp = await fetch(`${SCOREBOARD}/v1/scoreboard?mode=dashboard`, {
        headers: { 'Authorization': `Bearer ${state.jwt}` }
      });
      if (resp.ok) {
        const data = await resp.json();
        state.scoreData = data.score || data.scoreboard?.score || null;
        render();
      }
    } catch (e) { /* silent */ }
    // Also load pairing profile + drift
    loadProfile();
    loadDrift();
  }

  async function loadProfile() {
    if (!state.jwt) return;
    try {
      const resp = await fetch(`${SCOREBOARD}/v1/pairing/profile/effective`, {
        headers: { 'Authorization': `Bearer ${state.jwt}` }
      });
      if (resp.ok) {
        state.profileData = await resp.json();
        render();
      }
    } catch (e) { /* silent */ }
  }

  async function loadDrift() {
    if (!state.jwt) return;
    try {
      const resp = await fetch(`${SCOREBOARD}/v1/pairing/neural-drift`, {
        headers: { 'Authorization': `Bearer ${state.jwt}` }
      });
      if (resp.ok) {
        state.driftData = await resp.json();
        render();
      }
    } catch (e) { /* silent */ }
  }

  async function loadNetworkStats() {
    try {
      const resp = await fetch(`${RING_LEADER}/network/stats`);
      if (resp.ok) {
        state.networkStats = await resp.json();
        render();
      }
    } catch (e) { /* silent */ }
  }

  async function loadWebRing() {
    if (state.webRingLoading) return;
    state.webRingLoading = true;
    render();

    try {
      // Get current site's position in the ring + neighbors
      const currentHost = window.location.hostname;
      const resp = await fetch(`${RING_LEADER}/webring/position?site=${encodeURIComponent(currentHost)}`);
      if (resp.ok) {
        state.webRing = await resp.json();
      } else {
        // Fallback: just get the full directory
        const dirResp = await fetch(`${RING_LEADER}/content/directory?limit=20`);
        if (dirResp.ok) {
          const dir = await dirResp.json();
          state.webRing = {
            prev: null,
            next: null,
            current: { name: SITE_NAME, url: window.location.origin },
            nearby: dir.sites || dir.members || [],
            total: dir.total || 0,
          };
        }
      }
    } catch (e) {
      console.warn('[SEWN] WebRing load failed:', e);
      // Create minimal fallback
      state.webRing = {
        prev: null,
        next: null,
        current: { name: SITE_NAME, url: window.location.origin },
        nearby: [],
        total: 0,
      };
    }
    state.webRingLoading = false;
    render();
  }

  function navigateRing(direction) {
    if (!state.webRing) return;
    const target = direction === 'prev' ? state.webRing.prev : 
                   direction === 'next' ? state.webRing.next :
                   direction === 'random' ? getRandomRingSite() : null;
    if (target?.url) {
      window.open(target.url, '_blank');
    }
  }

  function getRandomRingSite() {
    const nearby = state.webRing?.nearby || [];
    if (nearby.length === 0) return null;
    return nearby[Math.floor(Math.random() * nearby.length)];
  }

  // ── Chat ──────────────────────────────────────────────────

  async function sendMessage(text) {
    if (!text.trim() || state.chatLoading) return;
    
    state.chatMessages.push({ role: 'user', content: text });
    state.chatLoading = true;
    render();

    try {
      const body = { message: text, stream: false };
      if (state.chatSessionId) body.session_id = state.chatSessionId;

      const resp = await fetch(`${SCOREBOARD}/v1/chat`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          ...(state.jwt ? { 'Authorization': `Bearer ${state.jwt}` } : {})
        },
        body: JSON.stringify(body)
      });

      if (resp.ok) {
        const data = await resp.json();
        const content = data.choices?.[0]?.message?.content || data.content || 'No response';
        if (data.session_id) state.chatSessionId = data.session_id;
        state.chatMessages.push({ role: 'assistant', content });
      } else {
        state.chatMessages.push({ role: 'assistant', content: '⚠️ Wirebot is currently unavailable.' });
      }
    } catch (e) {
      state.chatMessages.push({ role: 'assistant', content: '⚠️ Connection failed. Try again.' });
    }

    state.chatLoading = false;
    render();
    scrollChatToBottom();
  }

  // ── Render ────────────────────────────────────────────────

  let container = null;

  function createContainer() {
    container = document.createElement('div');
    container.id = 'sewn-overlay-root';
    document.body.appendChild(container);
    injectStyles();
  }

  function render() {
    if (!container) return;
    container.innerHTML = `
      ${renderButton()}
      ${state.open ? renderPanel() : ''}
    `;
    bindEvents();
  }

  function renderButton() {
    return `
      <button id="sewn-fab" class="sewn-fab ${state.open ? 'sewn-fab-active' : ''}" 
              title="Open ${SITE_NAME}">
        ${state.open ? '✕' : '⚡'}
      </button>
    `;
  }

  function renderPanel() {
    return `
      <div class="sewn-panel">
        <div class="sewn-panel-header">
          <span class="sewn-panel-title">⚡ ${SITE_NAME}</span>
          ${state.user ? `<span class="sewn-panel-user">${esc(state.user.display_name || '')}</span>` : ''}
        </div>
        <div class="sewn-panel-tabs">
          <button class="sewn-tab ${state.tab === 'chat' ? 'sewn-tab-active' : ''}" data-tab="chat">💬 Chat</button>
          <button class="sewn-tab ${state.tab === 'score' ? 'sewn-tab-active' : ''}" data-tab="score">📊 Score</button>
          <button class="sewn-tab ${state.tab === 'ring' ? 'sewn-tab-active' : ''}" data-tab="ring">🔗 Ring</button>
          <button class="sewn-tab ${state.tab === 'network' ? 'sewn-tab-active' : ''}" data-tab="network">🌐 Network</button>
        </div>
        <div class="sewn-panel-body">
          ${state.loading ? '<div class="sewn-loading">Loading...</div>' : renderTabContent()}
        </div>
      </div>
    `;
  }

  function renderTabContent() {
    // WebRing is public - doesn't require login
    if (state.tab === 'ring') {
      return renderWebRing();
    }
    if (!state.user && !state.jwt) {
      return renderLoginPrompt();
    }
    switch (state.tab) {
      case 'chat': return renderChat();
      case 'score': return renderScore();
      case 'network': return renderNetwork();
      default: return renderChat();
    }
  }

  function renderLoginPrompt() {
    return `
      <div class="sewn-login-prompt">
        <div class="sewn-login-icon">⚡</div>
        <h3>Welcome to ${esc(SITE_NAME)}</h3>
        <p>Sign in to access Wirebot AI, your scoreboard, and the network.</p>
        <a href="https://startempirewire.com/login/?redirect_to=${encodeURIComponent(window.location.href)}" 
           class="sewn-btn-primary" target="_blank">
          Sign in with Startempire Wire
        </a>
      </div>
    `;
  }

  function renderChat() {
    const msgs = state.chatMessages.map(m => `
      <div class="sewn-msg sewn-msg-${m.role}">
        <div class="sewn-msg-label">${m.role === 'user' ? 'You' : '⚡ Wirebot'}</div>
        <div class="sewn-msg-content">${esc(m.content)}</div>
      </div>
    `).join('');

    return `
      <div class="sewn-chat">
        <div class="sewn-chat-messages" id="sewn-chat-scroll">
          ${msgs || '<div class="sewn-chat-empty">Ask Wirebot anything about your business.</div>'}
          ${state.chatLoading ? '<div class="sewn-msg sewn-msg-assistant"><div class="sewn-msg-label">⚡ Wirebot</div><div class="sewn-msg-content sewn-typing">Thinking...</div></div>' : ''}
        </div>
        <div class="sewn-chat-input">
          <input type="text" id="sewn-chat-field" placeholder="Ask Wirebot..." 
                 ${state.chatLoading ? 'disabled' : ''} />
          <button id="sewn-chat-send" ${state.chatLoading ? 'disabled' : ''}>➡️</button>
        </div>
      </div>
    `;
  }

  function renderPairingNudge() {
    const p = state.profileData;
    if (!p) return '';
    const score = p.pairing_score || 0;
    const level = p.level || 'Initializing';
    const acc = ((p.accuracy || 0) * 100).toFixed(0);

    if (score >= 60) {
      return `<div class="sewn-pairing-badge">🧬 Profile: ${score}/100 (${level}) • ${acc}% accurate</div>`;
    }

    // Nudge if pairing is incomplete
    return `
      <div class="sewn-pairing-nudge">
        <div class="sewn-pairing-score">🧬 Pairing: ${score}/100</div>
        <div class="sewn-pairing-hint">
          ${score < 20 ? 'Complete assessments to calibrate Wirebot' : 'Chat more to improve accuracy'}
        </div>
        <a href="${SCOREBOARD}/#profile" target="_blank" class="sewn-btn-pairing">
          ${score < 20 ? 'Start Profile Assessment →' : 'View Profile →'}
        </a>
      </div>
    `;
  }

  function renderScore() {
    if (!state.scoreData) {
      return '<div class="sewn-loading">Loading scoreboard...</div>';
    }
    const s = state.scoreData;
    const signal = (s.execution_score || 0) >= 60 ? 'green' : (s.execution_score || 0) >= 30 ? 'yellow' : 'red';
    
    return `
      <div class="sewn-score">
        <div class="sewn-score-big sewn-signal-${signal}">${s.execution_score || 0}</div>
        <div class="sewn-score-label">Execution Score</div>
        <div class="sewn-score-lanes">
          ${renderLane('📦', 'Shipping', s.shipping_score || 0, 40)}
          ${renderLane('📣', 'Distribution', s.distribution_score || 0, 25)}
          ${renderLane('💰', 'Revenue', s.revenue_score || 0, 20)}
          ${renderLane('⚙️', 'Systems', s.systems_score || 0, 15)}
        </div>
        ${s.intent ? `<div class="sewn-intent">🎯 ${esc(s.intent)}</div>` : ''}
        ${renderDriftBar()}
        ${renderRabbitAlert()}
        ${renderPairingNudge()}
        <a href="${SCOREBOARD}" target="_blank" class="sewn-btn-secondary">Open Full Scoreboard →</a>
      </div>
    `;
  }

  function renderDriftBar() {
    const d = state.driftData?.drift;
    if (!d) return '';
    const color = d.signal === 'deep_sync' ? '#00ff64' : d.signal === 'in_drift' ? '#4a9eff' : d.signal === 'weak' || d.signal === 'disconnected' ? '#ff3232' : '#ffc800';
    const label = (d.signal || 'unknown').replace(/_/g, ' ').toUpperCase();
    return `
      <div class="sewn-drift" style="border-color:${color}30;background:${color}08">
        <span class="sewn-drift-label">🧠 DRIFT</span>
        <div class="sewn-drift-track">
          <div class="sewn-drift-fill" style="width:${d.score||0}%;background:${color}"></div>
        </div>
        <span class="sewn-drift-val" style="color:${color}">${d.score||0}%</span>
      </div>
    `;
  }

  function renderRabbitAlert() {
    const r = state.driftData?.drift?.rabbit;
    if (!r || !r.active) return '';
    return `
      <div class="sewn-rabbit">
        🐇 <strong>R.A.B.I.T.</strong> — ${esc(r.message || 'Spiral detected')}
      </div>
    `;
  }

  function renderLane(icon, label, score, max) {
    const pct = Math.min(100, (score / max) * 100);
    return `
      <div class="sewn-lane">
        <span class="sewn-lane-icon">${icon}</span>
        <span class="sewn-lane-label">${label}</span>
        <div class="sewn-lane-bar"><div class="sewn-lane-fill" style="width:${pct}%"></div></div>
        <span class="sewn-lane-score">${score}/${max}</span>
      </div>
    `;
  }

  function renderNetwork() {
    const stats = state.networkStats || {};
    return `
      <div class="sewn-network">
        <div class="sewn-stats-grid">
          <div class="sewn-stat-card">
            <div class="sewn-stat-value">${stats.total_members || 0}</div>
            <div class="sewn-stat-label">Members</div>
          </div>
          <div class="sewn-stat-card">
            <div class="sewn-stat-value">${(stats.membership_tiers || []).length || 0}</div>
            <div class="sewn-stat-label">Tiers</div>
          </div>
          <div class="sewn-stat-card">
            <div class="sewn-stat-value">${stats.total_content || 0}</div>
            <div class="sewn-stat-label">Content</div>
          </div>
        </div>
        <div class="sewn-network-links">
          <a href="https://startempirewire.com" target="_blank" class="sewn-link">🏠 Startempire Wire</a>
          <a href="https://startempirewire.com/activity/" target="_blank" class="sewn-link">💬 Community</a>
          <a href="${SCOREBOARD}" target="_blank" class="sewn-link">📊 Scoreboard</a>
        </div>
      </div>
    `;
  }

  function renderWebRing() {
    // Load WebRing data if not already loaded
    if (!state.webRing && !state.webRingLoading) {
      loadWebRing();
    }

    if (state.webRingLoading) {
      return '<div class="sewn-loading">Loading WebRing...</div>';
    }

    const ring = state.webRing || { prev: null, next: null, nearby: [], total: 0 };
    const currentSite = ring.current || { name: SITE_NAME, url: window.location.origin };
    
    return `
      <div class="sewn-webring">
        <!-- WebRing Header -->
        <div class="sewn-ring-header">
          <div class="sewn-ring-logo">🔗</div>
          <div class="sewn-ring-title">Startempire Wire Network</div>
          <div class="sewn-ring-subtitle">${ring.total || 0} connected sites</div>
        </div>

        <!-- Navigation Controls -->
        <div class="sewn-ring-nav">
          <button class="sewn-ring-btn sewn-ring-prev" data-ring-nav="prev" 
                  ${ring.prev ? '' : 'disabled'} title="${ring.prev?.name || 'No previous site'}">
            ← Prev
          </button>
          <button class="sewn-ring-btn sewn-ring-random" data-ring-nav="random"
                  ${ring.nearby?.length > 0 ? '' : 'disabled'} title="Visit random site">
            🎲 Random
          </button>
          <button class="sewn-ring-btn sewn-ring-next" data-ring-nav="next"
                  ${ring.next ? '' : 'disabled'} title="${ring.next?.name || 'No next site'}">
            Next →
          </button>
        </div>

        <!-- Current Site Badge -->
        <div class="sewn-ring-current">
          <span class="sewn-ring-you-label">You're visiting:</span>
          <span class="sewn-ring-you-site">${esc(currentSite.name)}</span>
        </div>

        <!-- Nearby Sites List -->
        ${ring.nearby?.length > 0 ? `
          <div class="sewn-ring-nearby">
            <div class="sewn-ring-nearby-label">Explore the Network</div>
            <div class="sewn-ring-sites">
              ${ring.nearby.slice(0, 8).map(site => `
                <a href="${esc(site.url)}" target="_blank" class="sewn-ring-site" 
                   title="${esc(site.description || site.name)}">
                  ${site.screenshot ? `<img src="${esc(site.screenshot)}" class="sewn-ring-thumb" alt="${esc(site.name)}" />` : 
                    `<div class="sewn-ring-thumb sewn-ring-thumb-placeholder">${(site.name || '?')[0].toUpperCase()}</div>`}
                  <span class="sewn-ring-site-name">${esc(site.name)}</span>
                  ${site.tier ? `<span class="sewn-ring-site-tier">${esc(site.tier)}</span>` : ''}
                </a>
              `).join('')}
            </div>
          </div>
        ` : `
          <div class="sewn-ring-empty">
            <p>No other sites in the ring yet.</p>
            <a href="https://startempirewire.com/join" target="_blank" class="sewn-btn-primary">
              Join the Network →
            </a>
          </div>
        `}

        <!-- Join CTA -->
        <div class="sewn-ring-join">
          <a href="https://startempirewire.com/ring-leader" target="_blank" class="sewn-btn-secondary">
            Add Your Site to the Ring →
          </a>
        </div>
      </div>
    `;
  }

  // ── Events ────────────────────────────────────────────────

  function bindEvents() {
    const fab = document.getElementById('sewn-fab');
    if (fab) fab.addEventListener('click', togglePanel);

    document.querySelectorAll('.sewn-tab').forEach(btn => {
      btn.addEventListener('click', () => {
        state.tab = btn.dataset.tab;
        render();
      });
    });

    // WebRing navigation buttons
    document.querySelectorAll('[data-ring-nav]').forEach(btn => {
      btn.addEventListener('click', () => {
        const dir = btn.dataset.ringNav;
        navigateRing(dir);
      });
    });

    const chatField = document.getElementById('sewn-chat-field');
    const chatSend = document.getElementById('sewn-chat-send');
    if (chatField) {
      chatField.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          sendMessage(chatField.value);
          chatField.value = '';
        }
      });
    }
    if (chatSend) {
      chatSend.addEventListener('click', () => {
        const field = document.getElementById('sewn-chat-field');
        if (field) {
          sendMessage(field.value);
          field.value = '';
        }
      });
    }
  }

  function togglePanel() {
    state.open = !state.open;
    render();
    if (state.open && state.user && !state.scoreData) {
      loadScoreboard();
    }
  }

  function scrollChatToBottom() {
    setTimeout(() => {
      const el = document.getElementById('sewn-chat-scroll');
      if (el) el.scrollTop = el.scrollHeight;
    }, 50);
  }

  // ── Helpers ───────────────────────────────────────────────

  function esc(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    return match ? decodeURIComponent(match[2]) : null;
  }

  function setCookie(name, value, hours) {
    const d = new Date();
    d.setTime(d.getTime() + hours * 3600000);
    document.cookie = `${name}=${encodeURIComponent(value)};expires=${d.toUTCString()};path=/;SameSite=Lax`;
  }

  function removeCookie(name) {
    document.cookie = `${name}=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/`;
  }

  // ── Styles ────────────────────────────────────────────────

  function injectStyles() {
    const style = document.createElement('style');
    style.textContent = `
      #sewn-overlay-root { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
      
      .sewn-fab {
        position: fixed; bottom: 20px; right: 20px; z-index: 999999;
        width: 56px; height: 56px; border-radius: 50%;
        background: linear-gradient(135deg, #2563eb, #7c3aed);
        color: white; border: none; cursor: pointer;
        font-size: 24px; box-shadow: 0 4px 16px rgba(37,99,235,0.4);
        transition: all 0.3s ease;
        display: flex; align-items: center; justify-content: center;
      }
      .sewn-fab:hover { transform: scale(1.1); box-shadow: 0 6px 24px rgba(37,99,235,0.5); }
      .sewn-fab-active { background: #374151; }
      
      .sewn-panel {
        position: fixed; bottom: 88px; right: 20px; z-index: 999998;
        width: 380px; max-width: calc(100vw - 40px);
        max-height: calc(100vh - 120px);
        background: #111827; border-radius: 16px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        display: flex; flex-direction: column;
        overflow: hidden; animation: sewn-slide-up 0.2s ease-out;
        border: 1px solid #1f2937;
      }
      
      @keyframes sewn-slide-up {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
      }
      
      .sewn-panel-header {
        padding: 16px; display: flex; align-items: center; justify-content: space-between;
        border-bottom: 1px solid #1f2937;
      }
      .sewn-panel-title { font-weight: 600; color: #f9fafb; font-size: 14px; }
      .sewn-panel-user { font-size: 12px; color: #9ca3af; }
      
      .sewn-panel-tabs {
        display: flex; border-bottom: 1px solid #1f2937;
      }
      .sewn-tab {
        flex: 1; padding: 10px; background: none; border: none;
        color: #9ca3af; font-size: 12px; cursor: pointer;
        border-bottom: 2px solid transparent; transition: all 0.2s;
      }
      .sewn-tab:hover { color: #d1d5db; }
      .sewn-tab-active { color: #60a5fa; border-bottom-color: #60a5fa; }
      
      .sewn-panel-body {
        flex: 1; overflow-y: auto; min-height: 300px; max-height: 400px;
      }
      
      .sewn-loading {
        display: flex; align-items: center; justify-content: center;
        height: 200px; color: #6b7280; font-size: 14px;
      }
      
      /* Login */
      .sewn-login-prompt {
        text-align: center; padding: 32px 24px;
      }
      .sewn-login-icon { font-size: 48px; margin-bottom: 16px; }
      .sewn-login-prompt h3 { color: #f9fafb; font-size: 18px; margin: 0 0 8px; }
      .sewn-login-prompt p { color: #9ca3af; font-size: 13px; margin: 0 0 20px; line-height: 1.5; }
      .sewn-btn-primary {
        display: inline-block; padding: 10px 20px; background: #2563eb;
        color: white; border-radius: 8px; text-decoration: none;
        font-size: 14px; font-weight: 500; transition: background 0.2s;
      }
      .sewn-btn-primary:hover { background: #1d4ed8; }
      .sewn-btn-secondary {
        display: block; text-align: center; padding: 8px; margin-top: 12px;
        color: #60a5fa; text-decoration: none; font-size: 13px;
        border: 1px solid #374151; border-radius: 8px;
      }
      .sewn-btn-secondary:hover { background: #1f2937; }
      
      /* Pairing */
      .sewn-pairing-nudge {
        margin-top: 12px; padding: 10px; border-radius: 8px;
        background: rgba(124,124,255,0.08); border: 1px solid rgba(124,124,255,0.2);
      }
      .sewn-pairing-score { font-size: 13px; font-weight: 600; color: #7c7cff; }
      .sewn-pairing-hint { font-size: 11px; color: #9ca3af; margin: 4px 0 8px; }
      .sewn-btn-pairing {
        display: block; text-align: center; padding: 6px; font-size: 12px;
        color: #a78bfa; background: rgba(124,124,255,0.12);
        border: 1px solid rgba(124,124,255,0.3); border-radius: 6px;
        text-decoration: none;
      }
      .sewn-btn-pairing:hover { background: rgba(124,124,255,0.2); }
      .sewn-pairing-badge {
        margin-top: 8px; text-align: center; font-size: 11px; color: #7c7cff;
      }
      
      /* Chat */
      .sewn-chat { display: flex; flex-direction: column; height: 100%; }
      .sewn-chat-messages {
        flex: 1; overflow-y: auto; padding: 12px; min-height: 240px;
      }
      .sewn-chat-empty {
        text-align: center; color: #6b7280; padding: 40px 16px;
        font-size: 14px;
      }
      .sewn-msg { margin-bottom: 12px; }
      .sewn-msg-label { font-size: 11px; color: #6b7280; margin-bottom: 2px; }
      .sewn-msg-content {
        padding: 8px 12px; border-radius: 12px; font-size: 13px;
        line-height: 1.5; white-space: pre-wrap; word-break: break-word;
      }
      .sewn-msg-user .sewn-msg-content { background: #1e3a5f; color: #dbeafe; border-bottom-right-radius: 4px; }
      .sewn-msg-assistant .sewn-msg-content { background: #1f2937; color: #e5e7eb; border-bottom-left-radius: 4px; }
      .sewn-typing { color: #60a5fa; font-style: italic; }
      .sewn-chat-input {
        display: flex; gap: 8px; padding: 12px; border-top: 1px solid #1f2937;
      }
      .sewn-chat-input input {
        flex: 1; padding: 8px 12px; background: #1f2937; border: 1px solid #374151;
        border-radius: 8px; color: #e5e7eb; font-size: 13px; outline: none;
      }
      .sewn-chat-input input:focus { border-color: #60a5fa; }
      .sewn-chat-input input:disabled { opacity: 0.5; }
      .sewn-chat-input button {
        padding: 8px 12px; background: #2563eb; border: none;
        border-radius: 8px; cursor: pointer; font-size: 14px;
      }
      .sewn-chat-input button:disabled { opacity: 0.3; cursor: default; }
      
      /* Score */
      .sewn-score { padding: 16px; text-align: center; }
      .sewn-score-big { font-size: 64px; font-weight: 700; }
      .sewn-signal-green { color: #34d399; }
      .sewn-signal-yellow { color: #fbbf24; }
      .sewn-signal-red { color: #f87171; }
      .sewn-score-label { color: #9ca3af; font-size: 13px; margin-bottom: 16px; }
      .sewn-score-lanes { text-align: left; }
      .sewn-lane {
        display: flex; align-items: center; gap: 8px; margin-bottom: 8px; font-size: 12px;
      }
      .sewn-lane-icon { width: 20px; text-align: center; }
      .sewn-lane-label { width: 80px; color: #d1d5db; }
      .sewn-lane-bar {
        flex: 1; height: 6px; background: #374151; border-radius: 3px; overflow: hidden;
      }
      .sewn-lane-fill { height: 100%; background: #60a5fa; border-radius: 3px; transition: width 0.3s; }
      .sewn-lane-score { width: 40px; text-align: right; color: #9ca3af; }
      .sewn-intent { margin-top: 12px; padding: 8px; background: #1f2937; border-radius: 8px; font-size: 13px; color: #d1d5db; }
      
      /* Drift */
      .sewn-drift {
        display: flex; align-items: center; gap: 8px;
        margin-top: 10px; padding: 8px 12px; border-radius: 8px;
        border: 1px solid; font-size: 12px;
      }
      .sewn-drift-label { font-size: 9px; letter-spacing: 0.1em; opacity: 0.5; white-space: nowrap; }
      .sewn-drift-track { flex: 1; height: 5px; background: #374151; border-radius: 3px; overflow: hidden; }
      .sewn-drift-fill { height: 100%; border-radius: 3px; transition: width 0.8s ease; }
      .sewn-drift-val { font-weight: 700; font-size: 12px; min-width: 30px; text-align: right; }
      .sewn-rabbit {
        margin-top: 8px; padding: 8px 12px; border-radius: 8px;
        background: rgba(245,158,11,0.1); border: 1px solid rgba(245,158,11,0.3);
        font-size: 12px; color: #fbbf24; animation: sewn-blink 1.5s infinite;
      }
      @keyframes sewn-blink { 0%,100%{opacity:1} 50%{opacity:0.6} }

      /* Network */
      .sewn-network { padding: 16px; }
      .sewn-stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 16px; }
      .sewn-stat-card {
        text-align: center; padding: 12px; background: #1f2937; border-radius: 8px;
      }
      .sewn-stat-value { font-size: 24px; font-weight: 700; color: #60a5fa; }
      .sewn-stat-label { font-size: 11px; color: #9ca3af; margin-top: 2px; }
      .sewn-network-links { display: flex; flex-direction: column; gap: 8px; }
      .sewn-link {
        display: block; padding: 10px 12px; background: #1f2937; border-radius: 8px;
        color: #d1d5db; text-decoration: none; font-size: 13px; transition: background 0.2s;
      }
      .sewn-link:hover { background: #374151; }

      /* WebRing Styles */
      .sewn-webring { padding: 16px; }
      .sewn-ring-header { text-align: center; margin-bottom: 16px; }
      .sewn-ring-logo { font-size: 28px; }
      .sewn-ring-title { font-size: 14px; font-weight: 600; color: #f3f4f6; margin-top: 4px; }
      .sewn-ring-subtitle { font-size: 11px; color: #6b7280; }
      .sewn-ring-nav { display: flex; gap: 8px; justify-content: center; margin-bottom: 16px; }
      .sewn-ring-btn {
        flex: 1; padding: 10px 12px; border: none; border-radius: 8px;
        font-size: 12px; font-weight: 500; cursor: pointer; transition: all 0.2s;
        background: #374151; color: #d1d5db;
      }
      .sewn-ring-btn:hover:not(:disabled) { background: #4b5563; color: #f3f4f6; }
      .sewn-ring-btn:disabled { opacity: 0.4; cursor: not-allowed; }
      .sewn-ring-random { background: #1d4ed8; color: #fff; }
      .sewn-ring-random:hover:not(:disabled) { background: #2563eb; }
      .sewn-ring-current {
        text-align: center; padding: 10px; background: #1f2937; border-radius: 8px;
        margin-bottom: 16px; border: 1px solid #374151;
      }
      .sewn-ring-you-label { font-size: 10px; color: #6b7280; display: block; }
      .sewn-ring-you-site { font-size: 13px; color: #60a5fa; font-weight: 500; }
      .sewn-ring-nearby { margin-bottom: 16px; }
      .sewn-ring-nearby-label { font-size: 11px; color: #9ca3af; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.05em; }
      .sewn-ring-sites { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
      .sewn-ring-site {
        display: flex; flex-direction: column; align-items: center;
        padding: 10px; background: #1f2937; border-radius: 8px;
        text-decoration: none; transition: all 0.2s; border: 1px solid transparent;
      }
      .sewn-ring-site:hover { background: #374151; border-color: #4b5563; }
      .sewn-ring-thumb {
        width: 48px; height: 32px; border-radius: 4px; object-fit: cover;
        background: #374151; margin-bottom: 6px;
      }
      .sewn-ring-thumb-placeholder {
        display: flex; align-items: center; justify-content: center;
        font-size: 16px; font-weight: 700; color: #6b7280;
      }
      .sewn-ring-site-name { font-size: 11px; color: #d1d5db; text-align: center; line-height: 1.2; }
      .sewn-ring-site-tier { font-size: 9px; color: #6b7280; margin-top: 2px; }
      .sewn-ring-empty { text-align: center; padding: 20px; color: #9ca3af; }
      .sewn-ring-empty p { margin-bottom: 12px; font-size: 13px; }
      .sewn-ring-join { text-align: center; margin-top: 12px; }
      
      @media (max-width: 440px) {
        .sewn-panel { width: calc(100vw - 24px); right: 12px; bottom: 80px; }
        .sewn-fab { bottom: 16px; right: 16px; }
        .sewn-ring-sites { grid-template-columns: 1fr; }
      }
    `;
    document.head.appendChild(style);
  }

  // ── Init ──────────────────────────────────────────────────

  function init() {
    // Don't load on admin pages
    if (document.body.classList.contains('wp-admin')) return;
    
    createContainer();
    render();
    initAuth();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
