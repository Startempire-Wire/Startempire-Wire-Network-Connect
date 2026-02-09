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
  // Strip emoji prefix from WP site title (blogname may contain 🚀 etc.)
  const SITE_NAME = (window.sewnConnect?.siteName || 'Startempire Wire').replace(/^[\p{Emoji}\s]+/u, '').trim() || 'Startempire Wire';
  const LOGO_URL = window.sewnConnect?.logoUrl || '/wp-content/uploads/2024/02/sew-logo-white-header.png';
  
  // Configuration constants
  const FEED_PAGE_SIZE = 10;
  const RING_DISPLAY_SIZE = 8;
  const DIRECTORY_LIMIT = 20;

  // ── Glyph SVG Icons (Lucide-style, stroke-based) ──────────
  const ICON = {
    bolt:     '<svg class="sewn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
    feed:     '<svg class="sewn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22h16a2 2 0 002-2V4a2 2 0 00-2-2H8a2 2 0 00-2 2v16a2 2 0 01-2 2zm0 0a2 2 0 01-2-2v-9c0-1.1.9-2 2-2h2"/></svg>',
    ring:     '<svg class="sewn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>',
    globe:    '<svg class="sewn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>',
    chat:     '<svg class="sewn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>',
    chart:    '<svg class="sewn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
    maximize: '<svg class="sewn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>',
    minimize: '<svg class="sewn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="3" y1="21" x2="10" y2="14"/></svg>',
    close:    '<svg class="sewn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
    empty:    '<svg class="sewn-icon sewn-icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>',
    error:    '<svg class="sewn-icon sewn-icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    lock:     '<svg class="sewn-icon sewn-icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>',
    search:   '<svg class="sewn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
    users:    '<svg class="sewn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>',
    box:      '<svg class="sewn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
    megaphone:'<svg class="sewn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>',
    dollar:   '<svg class="sewn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>',
    gear:     '<svg class="sewn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>',
    target:   '<svg class="sewn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
    brain:    '<svg class="sewn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a7 7 0 017 7c0 2.38-1.19 4.47-3 5.74V17a2 2 0 01-2 2h-4a2 2 0 01-2-2v-2.26C6.19 13.47 5 11.38 5 9a7 7 0 017-7z"/><line x1="9" y1="22" x2="15" y2="22"/></svg>',
    rabbit:   '<svg class="sewn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>',
    home:     '<svg class="sewn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
    dna:      '<svg class="sewn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 15c6.667-6 13.333 0 20-6"/><path d="M9 22c1.798-1.998 2.518-3.995 2.807-5.993"/><path d="M15 2c-1.798 1.998-2.518 3.995-2.807 5.993"/><path d="M2 9c6.667 6 13.333 0 20 6"/></svg>',
    shuffle:  '<svg class="sewn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/><line x1="4" y1="4" x2="9" y2="9"/></svg>',
  };

  let state = {
    open: false,
    mode: 'panel', // 'panel' | 'overlay' - display mode
    tab: 'feed', // feed | chat | score | network | ring
    jwt: null,
    user: null,
    loading: true,
    chatMessages: [],
    chatLoading: false,
    chatSessionId: null,
    scoreData: null,
    networkStats: null,
    networkMembers: null, // Member directory data
    networkMembersLoading: false,
    profileData: null, // Pairing profile effective
    driftData: null, // Neural drift status
    webRing: null, // WebRing navigation data
    webRingLoading: false,
    feedData: null, // Public content feed (null=not loaded, []=empty, false=error)
    feedLoading: false,
  };

  // ── Auth ──────────────────────────────────────────────────

  async function initAuth() {
    // Try to get JWT from cookie or WP session
    const cookie = getCookie('sewn_jwt');
    if (cookie) {
      state.jwt = cookie;
      try {
        await validateAndLoadUser();
      } catch (e) {
        console.warn('[SEWN] Init auth validation failed:', e);
        state.loading = false;
        render();
      }
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
            try {
              await validateAndLoadUser();
            } catch (e) {
              console.warn('[SEWN] Exchange auth validation failed:', e);
              state.loading = false;
              render();
            }
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
      if (data.valid && data.user) {
        state.user = data.user;
        // Load authenticated data + public stats in parallel, then render once
        await Promise.all([loadScoreboard(), loadNetworkStats()]);
      } else {
        state.jwt = null;
        state.user = null;
        removeCookie('sewn_jwt');
        // Still load public stats for non-auth users
        await loadNetworkStats();
      }
    } catch (e) {
      console.warn('[SEWN] Validation failed:', e);
      state.jwt = null;
      state.user = null;
      // Load public stats even on validation failure
      await loadNetworkStats();
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
        // Use nullish coalescing to preserve 0 scores (valid execution_score value)
        state.scoreData = data.score ?? data.scoreboard?.score ?? null;
      }
    } catch (e) { 
      console.warn('[SEWN] Scoreboard load failed:', e);
    }
    // Load pairing profile + drift in parallel
    await Promise.all([loadProfile(), loadDrift()]);
    // Note: Caller is responsible for render()
  }

  async function loadProfile() {
    if (!state.jwt) return;
    try {
      const resp = await fetch(`${SCOREBOARD}/v1/pairing/profile/effective`, {
        headers: { 'Authorization': `Bearer ${state.jwt}` }
      });
      if (resp.ok) {
        state.profileData = await resp.json();
      }
    } catch (e) { 
      console.warn('[SEWN] Profile load failed:', e);
    }
  }

  async function loadDrift() {
    if (!state.jwt) return;
    try {
      const resp = await fetch(`${SCOREBOARD}/v1/pairing/neural-drift`, {
        headers: { 'Authorization': `Bearer ${state.jwt}` }
      });
      if (resp.ok) {
        state.driftData = await resp.json();
      }
    } catch (e) { 
      console.warn('[SEWN] Neural drift load failed:', e);
    }
  }

  async function loadNetworkStats() {
    try {
      const resp = await fetch(`${RING_LEADER}/network/stats`);
      if (resp.ok) {
        state.networkStats = await resp.json();
      }
    } catch (e) { 
      console.warn('[SEWN] Network stats load failed:', e);
    }
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
        const data = await resp.json();
        // Validate WebRing data structure
        if (data && typeof data === 'object') {
          state.webRing = data;
        } else {
          throw new Error('Invalid WebRing data format');
        }
      } else {
        // Fallback: just get the full directory
        const dirResp = await fetch(`${RING_LEADER}/content/directory?limit=${DIRECTORY_LIMIT}`);
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

  async function loadPublicFeed() {
    // Prevent duplicate loads (null=not loaded, []=empty, false=error)
    // Allow retry if feedData is false (error state) via manual retry button
    if (state.feedLoading) return;
    if (state.feedData !== null && state.feedData !== false) return;
    
    state.feedLoading = true;
    render();

    try {
      // Fetch public content from Ring Leader API
      // Endpoint: content?type=posts (appended to RING_LEADER base URL)
      const url = `${RING_LEADER}/content?type=posts&per_page=${FEED_PAGE_SIZE}`;
      console.log('[SEWN] Loading public feed from:', url);
      
      const resp = await fetch(url);
      console.log('[SEWN] Feed response status:', resp.status, resp.ok);
      
      if (resp.ok) {
        const result = await resp.json();
        console.log('[SEWN] Feed API response:', result);
        console.log('[SEWN] Feed data array:', result?.data);
        console.log('[SEWN] Feed items count:', result?.data?.length);
        
        // Validate response is array to prevent .map() crashes on malformed API data
        state.feedData = Array.isArray(result?.data) ? result.data : [];
        console.log('[SEWN] Feed loaded successfully:', state.feedData.length, 'items');
      } else {
        console.error('[SEWN] Public feed API error:', resp.status, resp.statusText);
        state.feedData = false; // false = error, allow retry
      }
    } catch (e) {
      console.error('[SEWN] Public feed load failed:', e);
      state.feedData = false; // false = error, allow retry
    }
    state.feedLoading = false;
    render();
  }

  function retryFeed() {
    state.feedData = null; // Reset to allow retry
    loadPublicFeed();
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
      console.warn('[SEWN] Chat send failed:', e);
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
      ${state.open && state.mode === 'overlay' ? renderOverlay() : ''}
      ${state.open && state.mode === 'panel' ? renderPanel() : ''}
    `;
    bindEvents();
  }

  function renderButton() {
    return `
      <button id="sewn-fab" class="sewn-fab ${state.open ? 'sewn-fab-active' : ''}" 
              title="Open ${SITE_NAME}">
        ${state.open ? ICON.close : ICON.bolt}
      </button>
    `;
  }

  function renderPanel() {
    // Determine which tabs to show
    const publicTabs = [
      {id: 'feed', icon: ICON.feed, label: 'Feed'},
      {id: 'ring', icon: ICON.ring, label: 'Ring'},
      {id: 'network', icon: ICON.globe, label: 'Network'},
    ];
    
    const authTabs = [
      {id: 'chat', icon: ICON.chat, label: 'Chat'},
      {id: 'score', icon: ICON.chart, label: 'Score'},
    ];
    
    const tabs = state.user ? [...publicTabs, ...authTabs] : publicTabs;
    
    const tabButtons = tabs.map(tab => `
      <button class="sewn-tab ${state.tab === tab.id ? 'sewn-tab-active' : ''}" data-tab="${tab.id}">
        ${tab.icon} ${tab.label}
      </button>
    `).join('');
    
    return `
      <div class="sewn-panel">
        <div class="sewn-panel-header">
          <span class="sewn-panel-title"><img src="${LOGO_URL}" alt="${SITE_NAME}" class="sewn-panel-logo"></span>
          <button class="sewn-fullscreen-btn" title="Open Fullscreen">${ICON.maximize}</button>
          ${state.user ? `<span class="sewn-panel-user">${esc(state.user.display_name || '')}</span>` : ''}
        </div>
        <div class="sewn-panel-tabs">
          ${tabButtons}
        </div>
        <div class="sewn-panel-body">
          ${state.loading ? '<div class="sewn-loading">Loading...</div>' : renderTabContent()}
        </div>
      </div>
    `;
  }

  function renderOverlay() {
    // Determine which tabs to show
    const publicTabs = [
      {id: 'feed', icon: ICON.feed, label: 'Feed'},
      {id: 'ring', icon: ICON.ring, label: 'Ring'},
      {id: 'network', icon: ICON.globe, label: 'Network'},
    ];
    
    const authTabs = [
      {id: 'chat', icon: ICON.chat, label: 'Chat'},
      {id: 'score', icon: ICON.chart, label: 'Score'},
    ];
    
    const tabs = state.user ? [...publicTabs, ...authTabs] : publicTabs;
    
    const tabButtons = tabs.map(tab => `
      <button class="sewn-tab ${state.tab === tab.id ? 'sewn-tab-active' : ''}" data-tab="${tab.id}">
        ${tab.icon} ${tab.label}
      </button>
    `).join('');
    
    return `
      <div class="sewn-overlay-backdrop"></div>
      <div class="sewn-overlay-modal">
        <div class="sewn-overlay-header">
          <span class="sewn-overlay-title"><img src="${LOGO_URL}" alt="${SITE_NAME}" class="sewn-overlay-logo"></span>
          ${state.user ? `<span class="sewn-overlay-user">${esc(state.user.display_name || '')}</span>` : ''}
          <button class="sewn-minimize-btn" title="Minimize to Panel">${ICON.minimize}</button>
          <button class="sewn-overlay-close" title="Close">${ICON.close}</button>
        </div>
        <div class="sewn-overlay-tabs">
          ${tabButtons}
        </div>
        <div class="sewn-overlay-body">
          ${state.loading ? '<div class="sewn-loading">Loading...</div>' : renderTabContent()}
        </div>
      </div>
    `;
  }

  function renderTabContent() {
    // Public tabs (no auth required)
    if (state.tab === 'feed') {
      return renderFeed();
    }
    if (state.tab === 'ring') {
      return renderWebRing();
    }
    if (state.tab === 'network') {
      return renderNetwork();
    }
    
    // Auth required tabs (chat, score)
    if (!state.user && !state.jwt) {
      return `
        <div class="sewn-login-cta">
          <div class="sewn-login-icon">${ICON.lock}</div>
          <h3>Sign in for more features</h3>
          <p>Access Wirebot AI and your personal scoreboard.</p>
          <a href="https://startempirewire.com/login/?redirect_to=${encodeURIComponent(window.location.href)}" 
             class="sewn-btn-primary" target="_blank">
            Sign in with Startempire Wire
          </a>
        </div>
      `;
    }
    
    switch (state.tab) {
      case 'chat': return renderChat();
      case 'score': return renderScore();
      default: return renderFeed();
    }
  }


  function renderFeed() {
    // Lazy load: Trigger fetch on first render if not loaded
    // Handle loading states: null=not loaded, false=error, []=loaded
    if (state.feedData === null && !state.feedLoading) {
      loadPublicFeed();
      return `<div class="sewn-loading">Loading network content...</div>`;
    }
    
    if (state.feedLoading) {
      return `<div class="sewn-loading">Loading...</div>`;
    }
    
    // Handle error state
    if (state.feedData === false) {
      return `
        <div class="sewn-error">
          <div class="sewn-error-icon">${ICON.error}</div>
          <p>Failed to load feed. Please try again.</p>
          <button id="sewn-feed-retry" class="sewn-btn-primary">Retry</button>
        </div>
      `;
    }
    
    // Handle empty state
    if (!state.feedData || state.feedData.length === 0) {
      return `
        <div class="sewn-empty">
          <div class="sewn-empty-icon">${ICON.empty}</div>
          <p>No content available yet.</p>
        </div>
      `;
    }
    
    const feedItems = state.feedData.map(post => {
      const title = post.title?.rendered || 'Untitled';
      const link = post.link || '#';
      // Validate date before formatting
      let date = '';
      if (post.date) {
        const dateObj = new Date(post.date);
        if (!isNaN(dateObj.getTime())) {
          date = dateObj.toLocaleDateString();
        }
      }
      const author = post._embedded?.author?.[0]?.name || '';
      
      // Safely extract and sanitize excerpt
      let plainExcerpt = '';
      if (post.excerpt?.rendered) {
        // Decode HTML entities and strip tags
        const decoded = decodeHtml(post.excerpt.rendered);
        plainExcerpt = decoded.replace(/<[^>]*>/g, '').trim();
      }
      
      // Extract categories
      const categories = post._embedded?.['wp:term']?.[0] || [];
      const catTags = categories.slice(0, 3).map(cat => 
        `<span class="sewn-feed-tag">${esc(cat.name)}</span>`
      ).join('');
      
      return `
        <article class="sewn-feed-item">
          <h3 class="sewn-feed-title">
            <a href="${esc(link)}" target="_blank" rel="noopener">${esc(title)}</a>
          </h3>
          <div class="sewn-feed-meta">
            ${date ? `<span class="sewn-feed-date">${date}</span>` : ''}
            ${author ? `<span class="sewn-feed-author">by ${esc(author)}</span>` : ''}
          </div>
          ${plainExcerpt ? `<div class="sewn-feed-excerpt">${esc(plainExcerpt)}</div>` : ''}
          ${catTags ? `<div class="sewn-feed-tags">${catTags}</div>` : ''}
        </article>
      `;
    }).join('');
    
    return `
      <div class="sewn-feed">
        <div class="sewn-feed-header">
          <h2>Network Feed</h2>
          <p>Latest from Startempire Wire Network</p>
        </div>
        <div class="sewn-feed-list">${feedItems}</div>
      </div>
    `;
  }

  function renderChat() {
    const msgs = state.chatMessages.map(m => `
      <div class="sewn-msg sewn-msg-${m.role}">
        <div class="sewn-msg-label">${m.role === 'user' ? 'You' : ICON.bolt + ' Wirebot'}</div>
        <div class="sewn-msg-content">${esc(m.content)}</div>
      </div>
    `).join('');

    return `
      <div class="sewn-chat">
        <div class="sewn-chat-messages" id="sewn-chat-scroll">
          ${msgs || '<div class="sewn-chat-empty">Ask Wirebot anything about your business.</div>'}
          ${state.chatLoading ? '<div class="sewn-msg sewn-msg-assistant"><div class="sewn-msg-label">' + ICON.bolt + ' Wirebot</div><div class="sewn-msg-content sewn-typing">Thinking...</div></div>' : ''}
        </div>
        <div class="sewn-chat-input">
          <input type="text" id="sewn-chat-field" placeholder="Ask Wirebot..." 
                 ${state.chatLoading ? 'disabled' : ''} />
          <button id="sewn-chat-send" ${state.chatLoading ? 'disabled' : ''}><svg class="sewn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg></button>
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
      return `<div class="sewn-pairing-badge">${ICON.dna} Profile: ${score}/100 (${level}) • ${acc}% accurate</div>`;
    }

    // Nudge if pairing is incomplete
    return `
      <div class="sewn-pairing-nudge">
        <div class="sewn-pairing-score">${ICON.dna} Pairing: ${score}/100</div>
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
          ${renderLane(ICON.box, 'Shipping', s.shipping_score || 0, 40)}
          ${renderLane(ICON.megaphone, 'Distribution', s.distribution_score || 0, 25)}
          ${renderLane(ICON.dollar, 'Revenue', s.revenue_score || 0, 20)}
          ${renderLane(ICON.gear, 'Systems', s.systems_score || 0, 15)}
        </div>
        ${s.intent ? `<div class="sewn-intent">${ICON.target} ${esc(s.intent)}</div>` : ''}
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
    return `
      <div class="sewn-drift" style="border-color:${color}30;background:${color}08">
        <span class="sewn-drift-label">${ICON.brain} DRIFT</span>
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
        ${ICON.rabbit} <strong>R.A.B.I.T.</strong> — ${esc(r.message || 'Spiral detected')}
      </div>
    `;
  }

  function renderLane(icon, label, score, max) {
    // Clamp to 100% to handle over-achievement (score > max)
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

  async function loadNetworkMembers() {
    state.networkMembersLoading = true;
    render();

    try {
      // Use directory endpoint (GeoDirectory businesses) instead of members (MemberPress users)
      // This is public - no auth required
      const resp = await fetch(`${RING_LEADER}/content/directory?per_page=20`);
      
      if (!resp.ok) {
        console.error('[SEWN] Directory API failed:', resp.status, resp.statusText);
        state.networkMembers = { error: 'fetch_failed' };
      } else {
        const json = await resp.json();
        console.log('[SEWN] Directory API response:', json);
        console.log('[SEWN] Sample business:', json.data?.[0]);
        state.networkMembers = json.data || [];
      }
    } catch (err) {
      console.error('[SEWN] Directory fetch error:', err);
      state.networkMembers = { error: err.message };
    } finally {
      state.networkMembersLoading = false;
      render();
    }
  }

  function renderNetwork() {
    const stats = state.networkStats || {};
    
    // Load directory if not already loaded (public - no auth needed)
    if (!state.networkMembers && !state.networkMembersLoading) {
      loadNetworkMembers();
    }

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

        ${renderMemberDirectory()}

        <div class="sewn-network-links">
          <a href="https://startempirewire.com" target="_blank" class="sewn-link">${ICON.home} Startempire Wire</a>
          <a href="https://startempirewire.com/activity/" target="_blank" class="sewn-link">${ICON.users} Community</a>
          <a href="${SCOREBOARD}" target="_blank" class="sewn-link">${ICON.chart} Scoreboard</a>
        </div>
      </div>
    `;
  }

  function renderMemberDirectory() {
    if (state.networkMembersLoading) {
      return '<div class="sewn-loading">Loading business directory...</div>';
    }

    if (state.networkMembers?.error) {
      return '<div class="sewn-error">Could not load business directory</div>';
    }

    const members = state.networkMembers || [];
    if (members.length === 0) {
      return '<div class="sewn-empty">No businesses found</div>';
    }

    return `
      <div class="sewn-member-directory">
        <h3 class="sewn-directory-title">Network Businesses</h3>
        <div class="sewn-member-grid">
          ${members.map(renderMemberCard).join('')}
        </div>
      </div>
    `;
  }

  function renderMemberCard(member) {
    // Handle GeoDirectory business data
    const title = member.title?.rendered || 'Business';
    
    // Logo from GeoDirectory (featured_image.src or logo field)
    const logo = member.featured_image?.src || member.logo?.split('|')[0] || 'https://www.gravatar.com/avatar/?d=mp&s=120';
    
    // Website URL (business website, not profile page)
    const website = member.website || '';
    
    // Description from content
    const description = member.content?.rendered || '';
    const plainDescription = description.replace(/<[^>]*>/g, '').trim(); // Strip HTML
    
    // Category name
    const categoryName = member.post_category?.[0]?.name || member.default_category || 'Business';
    
    // Screenshot: Use SEWN Screenshots service (Ring Leader's screenshot API)
    // Format: /sewn-screenshots/v1/site/{member_id} OR capture endpoint
    // For now, use member ID or slug for cached screenshots
    const memberId = member.slug || member.id;
    const screenshot = website 
      ? `${RING_LEADER.replace('/sewn/v1', '')}/sewn-screenshots/v1/site/${memberId}` 
      : logo;

    return `
      <div class="sewn-member-card">
        ${website ? `<img src="${screenshot}" alt="${esc(title)}" class="sewn-member-screenshot" loading="lazy" onerror="this.onerror=null;this.src='${esc(logo)}'" />` : `<img src="${logo}" alt="${esc(title)}" class="sewn-member-screenshot" loading="lazy" />`}
        <div class="sewn-member-info">
          <img src="${logo}" alt="${esc(title)}" class="sewn-member-avatar" onerror="this.style.display='none'" />
          <h4 class="sewn-member-name">${esc(title)}</h4>
          <span class="sewn-member-tier sewn-tier-${categoryName.toLowerCase().replace(/[^a-z]/g, '')}">${esc(categoryName)}</span>
          ${plainDescription ? `<p class="sewn-member-bio">${esc(plainDescription.substring(0, 100))}${plainDescription.length > 100 ? '...' : ''}</p>` : ''}
          ${website ? `<a href="${website}" target="_blank" class="sewn-member-link">Visit Site →</a>` : ''}
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
          <div class="sewn-ring-logo">${ICON.ring}</div>
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
            ${ICON.shuffle} Random
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
              ${shuffleArray(ring.nearby || []).slice(0, RING_DISPLAY_SIZE)
                .filter(site => site.url) // Only render sites with valid URLs
                .map(site => `
                <a href="${esc(site.url)}" target="_blank" class="sewn-ring-site" 
                   title="${esc(site.description || site.name)}">
                  ${site.screenshot ? `<img src="${esc(site.screenshot)}" class="sewn-ring-thumb" alt="${esc(site.name)}" />` : 
                    `<div class="sewn-ring-thumb sewn-ring-thumb-placeholder">${(String(site.name || '?'))[0].toUpperCase()}</div>`}
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

    // Fullscreen toggle button
    const fullscreenBtn = document.querySelector('.sewn-fullscreen-btn');
    if (fullscreenBtn) {
      fullscreenBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        state.mode = 'overlay';
        render();
      });
    }

    // Overlay close button
    const overlayClose = document.querySelector('.sewn-overlay-close');
    if (overlayClose) {
      overlayClose.addEventListener('click', () => {
        state.open = false;
        state.mode = 'panel';
        render();
      });
    }

    // Minimize button (overlay → panel)
    const minimizeBtn = document.querySelector('.sewn-minimize-btn');
    if (minimizeBtn) {
      minimizeBtn.addEventListener('click', () => {
        state.mode = 'panel';
        render();
      });
    }

    // Overlay backdrop click to close
    const backdrop = document.querySelector('.sewn-overlay-backdrop');
    if (backdrop) {
      backdrop.addEventListener('click', () => {
        state.open = false;
        state.mode = 'panel';
        render();
      });
    }

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

    // Feed retry button
    const retryBtn = document.getElementById('sewn-feed-retry');
    if (retryBtn) {
      retryBtn.addEventListener('click', retryFeed);
    }

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
    
    // Default to panel mode when opening (user can click fullscreen button)
    if (state.open && state.mode === 'overlay') {
      state.mode = 'panel'; // Reset to panel unless they explicitly chose overlay
    }
    
    render();
    if (state.open && state.user && !state.scoreData) {
      // Load scoreboard data asynchronously, then re-render with results
      loadScoreboard().then(() => render());
    }
  }

  function scrollChatToBottom() {
    setTimeout(() => {
      const el = document.getElementById('sewn-chat-scroll');
      if (el) el.scrollTop = el.scrollHeight;
    }, 50);
  }

  // ── Helpers ───────────────────────────────────────────────

  function decodeHtml(html) {
    const txt = document.createElement('textarea');
    txt.innerHTML = html;
    return txt.value;
  }

  function shuffleArray(arr) {
    const shuffled = [...arr];
    for (let i = shuffled.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
    }
    return shuffled;
  }

  function esc(str) {
    if (str == null) return ''; // Loose equality catches both null and undefined
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
  }

  function getCookie(name) {
    // Escape special regex characters in cookie name for safety
    const escapedName = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const match = document.cookie.match(new RegExp('(^| )' + escapedName + '=([^;]+)'));
    return match ? decodeURIComponent(match[2]) : null;
  }

  function setCookie(name, value, hours) {
    const d = new Date();
    d.setTime(d.getTime() + hours * 3600000);
    const secure = window.location.protocol === 'https:' ? ';Secure' : '';
    document.cookie = `${name}=${encodeURIComponent(value)};expires=${d.toUTCString()};path=/;SameSite=Lax${secure}`;
  }

  function removeCookie(name) {
    const secure = window.location.protocol === 'https:' ? ';Secure' : '';
    document.cookie = `${name}=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/;SameSite=Lax${secure}`;
  }

  // ── Styles ────────────────────────────────────────────────

  function injectStyles() {
    const style = document.createElement('style');
    style.textContent = `
      #sewn-overlay-root { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
      
      /* Glyph icon system — Lucide-style stroked SVGs */
      .sewn-icon { width: 16px; height: 16px; display: inline-block; vertical-align: -2px; flex-shrink: 0; }
      .sewn-icon-lg { width: 40px; height: 40px; }
      .sewn-fab .sewn-icon { width: 24px; height: 24px; vertical-align: middle; }
      .sewn-panel-title .sewn-icon { width: 14px; height: 14px; vertical-align: -1px; }
      .sewn-overlay-title .sewn-icon { width: 18px; height: 18px; vertical-align: -3px; }
      .sewn-tab .sewn-icon { width: 14px; height: 14px; vertical-align: -2px; }
      .sewn-fullscreen-btn .sewn-icon,
      .sewn-minimize-btn .sewn-icon,
      .sewn-overlay-close .sewn-icon { width: 18px; height: 18px; }
      .sewn-login-icon .sewn-icon { width: 40px; height: 40px; }
      .sewn-empty-icon .sewn-icon { width: 40px; height: 40px; }
      .sewn-error-icon .sewn-icon { width: 40px; height: 40px; }
      .sewn-ring-logo .sewn-icon { width: 28px; height: 28px; }
      .sewn-link .sewn-icon { width: 14px; height: 14px; margin-right: 6px; vertical-align: -2px; }
      .sewn-msg-label .sewn-icon { width: 12px; height: 12px; vertical-align: -1px; }
      #sewn-chat-send .sewn-icon { width: 16px; height: 16px; stroke: white; }
      
      .sewn-fab {
        position: fixed; bottom: 20px; right: 20px; z-index: 999999;
        width: 56px; height: 56px; border-radius: 50%;
        background: linear-gradient(135deg, #2563eb, #7c3aed);
        color: white; border: none; cursor: pointer;
        box-shadow: 0 4px 16px rgba(37,99,235,0.4);
        transition: all 0.3s ease;
        display: flex; align-items: center; justify-content: center;
        padding: 0;
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
      .sewn-panel-title { font-weight: 600; color: #f9fafb; font-size: 14px; display: flex; align-items: center; }
      .sewn-panel-logo { height: 24px; width: auto; display: block; object-fit: contain; }
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
      .sewn-login-icon { margin-bottom: 16px; text-align: center; color: #60a5fa; }
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
      
      /* Feed */
      .sewn-feed { padding: 16px; }
      .sewn-feed-header { margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #1f2937; }
      .sewn-feed-header h2 { color: #f9fafb; font-size: 16px; margin: 0 0 4px; font-weight: 600; }
      .sewn-feed-header p { color: #9ca3af; font-size: 12px; margin: 0; }
      .sewn-feed-list { display: flex; flex-direction: column; gap: 16px; }
      .sewn-feed-item {
        padding: 12px; background: #1f2937; border-radius: 8px;
        border: 1px solid #374151; transition: border-color 0.2s;
      }
      .sewn-feed-item:hover { border-color: #4b5563; }
      .sewn-feed-title { margin: 0 0 8px; font-size: 14px; font-weight: 600; }
      .sewn-feed-title a { color: #60a5fa; text-decoration: none; }
      .sewn-feed-title a:hover { color: #93c5fd; text-decoration: underline; }
      .sewn-feed-meta {
        display: flex; gap: 8px; font-size: 11px; color: #6b7280;
        margin-bottom: 8px;
      }
      .sewn-feed-date, .sewn-feed-author { padding: 2px 6px; background: #374151; border-radius: 4px; }
      .sewn-feed-excerpt { font-size: 12px; color: #d1d5db; line-height: 1.5; margin-bottom: 8px; }
      .sewn-feed-excerpt p { margin: 0 0 8px; }
      .sewn-feed-excerpt p:last-child { margin-bottom: 0; }
      .sewn-feed-tags { display: flex; flex-wrap: wrap; gap: 4px; }
      .sewn-feed-tag { font-size: 10px; padding: 2px 8px; background: #374151; border-radius: 12px; color: #9ca3af; }
      .sewn-empty {
        text-align: center; padding: 40px 16px; color: #6b7280;
      }
      .sewn-empty-icon { margin-bottom: 12px; opacity: 0.5; color: #6b7280; }
      .sewn-error {
        text-align: center; padding: 40px 16px; color: #f87171;
      }
      .sewn-error-icon { margin-bottom: 12px; color: #f87171; }
      .sewn-error p { color: #9ca3af; font-size: 14px; margin: 8px 0 0; }
      .sewn-login-cta {
        text-align: center; padding: 32px 24px;
      }
      .sewn-login-cta .sewn-login-icon { margin-bottom: 16px; color: #60a5fa; }
      .sewn-login-cta h3 { color: #f9fafb; font-size: 18px; margin: 0 0 8px; }
      .sewn-login-cta p { color: #9ca3af; font-size: 13px; margin: 0 0 20px; line-height: 1.5; }
      
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
      .sewn-ring-logo { color: #60a5fa; }
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
      
      /* Member Directory */
      .sewn-member-directory { margin: 16px 0; }
      .sewn-directory-title { font-size: 14px; font-weight: 600; margin-bottom: 12px; color: #e5e7eb; }
      .sewn-member-grid {
        display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 12px; max-height: 400px; overflow-y: auto;
      }
      .sewn-member-card {
        background: #1f2937; border: 1px solid #374151; border-radius: 8px;
        overflow: hidden; transition: transform 0.2s, box-shadow 0.2s;
      }
      .sewn-member-card:hover {
        transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.3);
      }
      .sewn-member-screenshot {
        width: 100%; height: 120px; object-fit: cover; background: #111827;
      }
      .sewn-member-info { padding: 12px; }
      .sewn-member-avatar {
        width: 40px; height: 40px; border-radius: 50%; margin-bottom: 8px;
      }
      .sewn-member-name {
        font-size: 13px; font-weight: 600; margin: 0 0 4px 0; color: #e5e7eb;
      }
      .sewn-member-tier {
        display: inline-block; font-size: 10px; padding: 2px 6px; border-radius: 4px;
        background: #374151; color: #9ca3af; font-weight: 500; text-transform: uppercase;
      }
      .sewn-tier-wire { background: #1e3a8a; color: #93c5fd; }
      .sewn-tier-extrawire { background: #7c2d12; color: #fed7aa; }
      .sewn-tier-freewire { background: #581c87; color: #e9d5ff; }
      .sewn-member-bio {
        font-size: 11px; color: #9ca3af; margin: 8px 0; line-height: 1.4;
      }
      .sewn-member-link {
        display: inline-block; font-size: 11px; color: #60a5fa;
        text-decoration: none; font-weight: 500; margin-top: 8px;
      }
      .sewn-member-link:hover { text-decoration: underline; }
      .sewn-auth-required {
        text-align: center; padding: 32px 16px; background: #1f2937;
        border-radius: 8px; margin: 16px 0;
      }
      .sewn-auth-required p { color: #9ca3af; margin-bottom: 12px; }
      .sewn-btn-primary {
        display: inline-block; background: #2563eb; color: white;
        padding: 10px 20px; border-radius: 4px; text-decoration: none; font-weight: 500;
      }
      .sewn-btn-primary:hover { background: #1d4ed8; }

      /* Fullscreen button in panel */
      .sewn-fullscreen-btn {
        background: none; border: none; color: #9ca3af; cursor: pointer;
        padding: 4px 8px; border-radius: 4px;
        transition: all 0.2s; margin-left: 8px;
        display: inline-flex; align-items: center;
      }
      .sewn-fullscreen-btn:hover { background: #374151; color: #e5e7eb; }

      /* Overlay Mode (Full-screen) */
      .sewn-overlay-backdrop {
        position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 999997;
        background: rgba(0, 0, 0, 0.7); backdrop-filter: blur(4px);
      }
      .sewn-overlay-modal {
        position: fixed; top: 20px; left: 20px; right: 20px; bottom: 20px; z-index: 999998;
        background: #111827; border-radius: 16px; box-shadow: 0 20px 50px rgba(0,0,0,0.5);
        display: flex; flex-direction: column; overflow: hidden;
      }
      .sewn-overlay-header {
        display: flex; align-items: center; padding: 16px 20px;
        background: #1f2937; border-bottom: 1px solid #374151;
      }
      .sewn-overlay-title {
        font-size: 18px; font-weight: 600; color: #f3f4f6; flex: 1;
        display: flex; align-items: center;
      }
      .sewn-overlay-logo { height: 32px; width: auto; display: block; object-fit: contain; }
      .sewn-overlay-user {
        font-size: 13px; color: #9ca3af; margin-right: 16px;
      }
      .sewn-overlay-close {
        background: none; border: none; color: #9ca3af; cursor: pointer;
        padding: 4px 8px; border-radius: 4px;
        transition: all 0.2s; display: inline-flex; align-items: center;
      }
      .sewn-overlay-close:hover { background: #374151; color: #f3f4f6; }
      .sewn-minimize-btn {
        background: none; border: none; color: #9ca3af; cursor: pointer;
        padding: 4px 8px; border-radius: 4px;
        transition: all 0.2s; display: inline-flex; align-items: center; margin-right: 8px;
      }
      .sewn-minimize-btn:hover { background: #374151; color: #f3f4f6; }
      .sewn-overlay-tabs {
        display: flex; gap: 8px; padding: 12px 20px; background: #1f2937;
        border-bottom: 1px solid #374151; overflow-x: auto;
      }
      .sewn-overlay-tabs .sewn-tab {
        padding: 10px 16px; font-size: 14px;
      }
      .sewn-overlay-body {
        flex: 1; overflow-y: auto; padding: 20px;
      }

      @media (max-width: 440px) {
        .sewn-panel { width: calc(100vw - 24px); right: 12px; bottom: 80px; }
        .sewn-fab { bottom: 16px; right: 16px; }
        .sewn-ring-sites { grid-template-columns: 1fr; }
        .sewn-member-grid { grid-template-columns: 1fr; }
        .sewn-overlay-modal { top: 10px; left: 10px; right: 10px; bottom: 10px; }
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
