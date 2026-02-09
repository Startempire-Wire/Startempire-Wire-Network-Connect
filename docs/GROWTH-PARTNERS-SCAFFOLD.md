# SEWN Growth Partners — Feature Scaffold

> Epic: `bricks-child-i3j`
> Status: Greenfield scaffolding complete
> Created: 2026-02-08

---

## 1. CONCEPT

Growth Partners (GP) are a **deeper relationship layer** on top of BuddyBoss friendships.

- **Max 8 per user** — curated, not a follower count
- **Mutual confirmation required** — both parties must consent (OAuth-style warning)
- **Bidirectional accountability** — shared daily check-in status, task velocity, milestones
- **Connected to Wirebot** — Wirebot includes GP context in daily standups
- **Visible on Wins dashboard** — circular avatar row (per Figma wireframe)

### Relationship Hierarchy
```
Stranger → BB Friend → Growth Partner (max 8, mutual, accountability-linked)
```

### What Growth Partners Share (Consent Dialog Lists These)
1. Daily check-in completion status (green/yellow/red)
2. Task completion rate (% of daily tasks done, NOT task content)
3. Revenue milestones (anonymized — "hit $5K MRR", not exact numbers)
4. Wirebot nudge visibility (partner sees that you were nudged, not why)
5. Active business stage (Idea / Launch / Growth)

### What Growth Partners Do NOT Share
- Private task content or descriptions
- Chat history with Wirebot
- Financial details (actual revenue, debt, expenses)
- Pairing profile answers
- Personal notes or journal entries

---

## 2. EXISTING INFRASTRUCTURE (Already Built)

### Ring Leader REST API (startempirewire.network)
**File:** `startempire-wire-network-ring-leader/includes/api/class-rest-controller.php`

Already has 3 endpoints:
```
GET    /ring-leader/v1/member/growth-partners              → List GP
POST   /ring-leader/v1/member/growth-partners              → Add GP
DELETE  /ring-leader/v1/member/growth-partners/{partner_id} → Remove GP
```

**Current storage:** `sewn_growth_partners` user_meta (JSON array of `{user_id, since, note}`)

**Current constraints:** Requires BP friendship first (`friends_check_friendship()`). No max limit enforced. No mutual confirmation — instant add.

### Wins Dashboard (wins.wirebot.chat)
**File:** `wirebot-core/cmd/scoreboard/ui/src/lib/Dashboard.svelte`

Already has "NETWORK GROWTH PARTNERS" section:
- Fetches from `/v1/network/members?limit=8` (generic, not GP-specific)
- Avatar circles with name, link
- Empty state: ghost avatars + "Find Partners →" CTA
- "CONNECT ➜" header link to startempirewire.com/members/

### Connect Plugin (startempirewire.com)
**File:** `startempire-wire-network-connect/public/class-startempire-wire-network-connect-public.php`

Has `add_connect_button()` on BP member profiles. Currently shows:
- Connected ✓ (if BP friends)
- Request Sent (if pending)
- Accept Request (if awaiting)
- Connect (if not friends)

**Missing:** No GP layer — only BP friendship.

### Chrome Extension (startempire-wire-network-ext)
**File:** `startempire-wire-network-ext/src/pages/sidepanel/Sidepanel.svelte`

No GP section yet. Has auth flow, network stats, Wirebot tab.

### Figma Reference
**Frame:** "Home Overview" in Wire Bot Figma file
**Layout:** "NETWORK GROWTH PARTNERS" section shows:
- Row of 5 circular avatars (gray placeholders when empty)
- "CONNECT ➜" link in header
- Below: stage pills (Idea, Launch, Growth)

---

## 3. IMPLEMENTATION PLAN (16 Tasks)

### Layer 1: Data & Storage (GP-1 thru GP-3)

#### GP-1: DB Schema — `wp_sewn_growth_partnerships` table
**Where:** Ring Leader plugin (startempirewire.network)
**File:** `ring-leader/includes/class-db.php` (new)

```sql
CREATE TABLE {prefix}sewn_growth_partnerships (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         BIGINT UNSIGNED NOT NULL,
    partner_id      BIGINT UNSIGNED NOT NULL,
    status          ENUM('pending','active','declined','removed') NOT NULL DEFAULT 'pending',
    requested_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at    DATETIME NULL,
    removed_at      DATETIME NULL,
    note            VARCHAR(255) DEFAULT '',
    
    UNIQUE KEY uq_pair (user_id, partner_id),
    INDEX idx_partner (partner_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Migration:** Convert existing `sewn_growth_partners` user_meta entries to table rows (status=active, since→requested_at+confirmed_at).

**Why proper table vs user_meta:**
- Bilateral queries (both "who are MY partners" and "who has ME as partner")
- Status tracking (pending/active/declined/removed history)
- Unique constraint prevents duplicates at DB level
- Indexable for Ring Leader API performance

#### GP-2: Ring Leader REST Hardening
**Where:** `ring-leader/includes/api/class-rest-controller.php`
**Changes:**

1. **Max 8 enforcement:**
   ```php
   $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table} 
       WHERE user_id = %d AND status = 'active'", $user_id);
   if ($count >= 8) {
       return new WP_REST_Response(['error' => 'Maximum 8 growth partners reached'], 422);
   }
   ```

2. **Mutual confirmation flow:**
   - `POST /member/growth-partners` → Creates row with `status=pending`
   - New: `PUT /member/growth-partners/{partner_id}/accept` → Sets `status=active`, `confirmed_at=NOW()`
   - New: `PUT /member/growth-partners/{partner_id}/decline` → Sets `status=declined`
   - Both sides can see pending state
   - `GET` endpoint returns separate arrays: `{active: [], pending_sent: [], pending_received: []}`

3. **Bilateral insert:** When user A requests user B:
   - Row 1: `user_id=A, partner_id=B, status=pending`
   - On accept: Row 2: `user_id=B, partner_id=A, status=active` AND update Row 1 to active
   - This means each user's `user_id` column lists THEIR view

4. **Webhook on state change:**
   - `do_action('sewn_growth_partner_requested', $user_id, $partner_id)`
   - `do_action('sewn_growth_partner_accepted', $user_id, $partner_id)`
   - `do_action('sewn_growth_partner_removed', $user_id, $partner_id)`

#### GP-3: Connect Plugin REST Relay
**Where:** `startempire-wire-network-connect/inc/class-rest-api.php`
**New endpoints** (namespace `sewn-connect/v1`):

```
GET    /growth-partners                        → Proxy to RL, return partner list
POST   /growth-partners                        → Proxy request to RL
PUT    /growth-partners/{partner_id}/accept     → Proxy accept
PUT    /growth-partners/{partner_id}/decline    → Proxy decline
DELETE /growth-partners/{partner_id}            → Proxy remove
```

Authentication: WP nonce + `is_user_logged_in()`. Maps WP user to RL user via `sewn_connect_network_user_id` user_meta or auth exchange.

---

### Layer 2: WordPress / BuddyBoss UI (GP-4 thru GP-7)

#### GP-4: BP Member Profile Button — State Machine
**Where:** `startempire-wire-network-connect/public/class-startempire-wire-network-connect-public.php`
**Method:** `add_connect_button()`

Full state machine:

```
┌─────────────────────────────────────────────────────────────┐
│                    BUTTON STATE MACHINE                      │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  NOT LOGGED IN ─────────────────→ (no button)                │
│                                                              │
│  OWN PROFILE ───────────────────→ "Growth Partners: 3/8"     │
│                                     [Manage Partners]         │
│                                                              │
│  NOT BB FRIEND ─────────────────→ BP "Add Friend" button     │
│                                     (standard BP behavior)    │
│                                                              │
│  BB FRIEND + NOT GP ────────────→ "Connected ✓"              │
│                                     [★ Designate GP]          │
│                                                              │
│  BB FRIEND + GP PENDING SENT ───→ "GP Request Sent"          │
│                                     [Cancel]                  │
│                                                              │
│  BB FRIEND + GP PENDING RECV ───→ "GP Request from {name}"   │
│                                     [Accept] [Decline]        │
│                                                              │
│  ACTIVE GP ─────────────────────→ "Growth Partner ✓"         │
│                                     [Remove GP]               │
│                                                              │
│  GP SLOTS FULL (8/8) ──────────→ "Connected ✓"              │
│                                     (no designate option)     │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

**Implementation:**
```php
public function add_connect_button() {
    // 1. Auth + context checks
    // 2. Determine BP friendship status (existing code works)
    // 3. NEW: Query GP status via Connect REST or local cache
    // 4. Render appropriate button based on composite state
    // 5. Inline JS for AJAX state transitions (no page reload)
}
```

**AJAX endpoints** (wp_ajax_):
- `sewn_gp_request` → POST to Connect /growth-partners
- `sewn_gp_accept` → PUT to Connect /growth-partners/{id}/accept
- `sewn_gp_decline` → PUT to Connect /growth-partners/{id}/decline
- `sewn_gp_remove` → DELETE to Connect /growth-partners/{id}
- `sewn_gp_cancel` → DELETE to Connect /growth-partners/{id} (while pending)

#### GP-5: OAuth-Style Confirmation Dialog
**Where:** New file `startempire-wire-network-connect/assets/js/sewn-gp-dialog.js` + CSS

**Triggered by:** Click "Designate Growth Partner" button

**Dialog structure:**
```html
<div class="sewn-gp-overlay">
  <div class="sewn-gp-dialog">
    <div class="sewn-gp-dialog-header">
      <img src="{avatar}" class="sewn-gp-dialog-avatar" />
      <h3>Designate {Name} as Growth Partner?</h3>
    </div>
    
    <div class="sewn-gp-dialog-warning">
      <svg>⚠️ shield icon</svg>
      <p>Growth Partners share accountability data to help each other build.</p>
    </div>
    
    <div class="sewn-gp-dialog-permissions">
      <h4>What you'll share with each other:</h4>
      <ul>
        <li>✓ Daily check-in completion status</li>
        <li>✓ Task completion rate (not task content)</li>
        <li>✓ Revenue milestones (anonymized)</li>
        <li>✓ Business stage (Idea/Launch/Growth)</li>
        <li>✓ Wirebot accountability nudge visibility</li>
      </ul>
    </div>
    
    <div class="sewn-gp-dialog-note">
      <label>Add a note (optional)</label>
      <textarea maxlength="255" placeholder="Why you want to grow together..."></textarea>
    </div>
    
    <div class="sewn-gp-dialog-actions">
      <button class="sewn-gp-cancel">Cancel</button>
      <button class="sewn-gp-confirm">
        <svg>handshake icon</svg>
        I Understand — Send Request
      </button>
    </div>
    
    <p class="sewn-gp-dialog-footer">
      You can remove a Growth Partner at any time.
      <br>They must also accept this request.
    </p>
  </div>
</div>
```

**Remove dialog (also OAuth-style):**
```html
<div class="sewn-gp-dialog sewn-gp-remove">
  <h3>Remove {Name} as Growth Partner?</h3>
  <p>They will be notified. Shared accountability data will be disconnected.
     You'll remain BuddyBoss friends.</p>
  <button class="sewn-gp-cancel">Keep Partner</button>
  <button class="sewn-gp-confirm-remove">Remove Growth Partner</button>
</div>
```

#### GP-6: BP Notification Hooks
**Where:** `startempire-wire-network-connect/inc/class-gp-notifications.php` (new)

Events:
1. **GP Request Sent** → BP notification to recipient: "{Name} wants you as a Growth Partner"
2. **GP Request Accepted** → BP notification to requester: "{Name} accepted your GP request!"
3. **GP Request Declined** → BP notification to requester: "{Name} declined your GP request"
4. **GP Removed** → BP notification: "{Name} removed you as a Growth Partner"

Each also sends email via `wp_mail()` with SEW-branded template:
```php
function sewn_gp_send_notification($type, $from_id, $to_id) {
    // BP notification
    bp_notifications_add_notification([
        'user_id'           => $to_id,
        'item_id'           => $from_id,
        'secondary_item_id' => 0,
        'component_name'    => 'sewn_growth_partners',
        'component_action'  => "gp_{$type}",
    ]);
    
    // Email
    $from_name = bp_core_get_user_displayname($from_id);
    $to_email  = get_userdata($to_id)->user_email;
    $subject   = $type === 'request' 
        ? "{$from_name} wants you as a Growth Partner on Startempire Wire"
        : "Growth Partner update from {$from_name}";
    // ... branded HTML template
    wp_mail($to_email, $subject, $body, ['Content-Type: text/html']);
}
```

#### GP-7: Member Portal Growth Partners Tab
**Where:** `bricks-child/inc/community/member-portal.php` — add new tab

Tab content:
```
╔═══════════════════════════════════════════════════════════════╗
║  GROWTH PARTNERS (3/8)                                       ║
╠═══════════════════════════════════════════════════════════════╣
║                                                               ║
║  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌ ─ ─ ─ ─┐         ║
║  │  Avatar  │ │  Avatar  │ │  Avatar  │ │   + Add │         ║
║  │  Alex K  │ │  Sarah M │ │  Jay R   │ │ Partner │         ║
║  │ since Jan│ │ since Dec│ │ since Feb│ └ ─ ─ ─ ─┘         ║
║  │ ● Active │ │ ● Active │ │ ⏳ Pend  │                     ║
║  │ [Remove] │ │ [Remove] │ │ [Cancel] │                     ║
║  └──────────┘ └──────────┘ └──────────┘                     ║
║                                                               ║
║  ─ PENDING REQUESTS ─────────────────────────────────────    ║
║                                                               ║
║  👤 Mike T. wants to be your Growth Partner                   ║
║  "Let's hold each other accountable for Q1 goals"            ║
║  [Accept]  [Decline]                                          ║
║                                                               ║
║  ─ FIND MORE PARTNERS ───────────────────────────────────    ║
║  Browse members to find your accountability circle →          ║
║                                                               ║
╚═══════════════════════════════════════════════════════════════╝
```

---

### Layer 3: Wins Dashboard (GP-8 thru GP-10)

#### GP-8: Scoreboard GP Endpoint Pivot
**Where:** `wirebot-core/cmd/scoreboard/main.go`

Currently: `authFetch('/v1/network/members?limit=8')`
Change to: `authFetch('/v1/growth-partners')` which proxies to Ring Leader

**New Go endpoint:**
```go
mux.HandleFunc("/v1/growth-partners", s.authMember(s.handleGrowthPartners))

func (s *Server) handleGrowthPartners(w http.ResponseWriter, r *http.Request) {
    // Fetch from Ring Leader /ring-leader/v1/member/growth-partners
    // using operator's SEWN JWT
    // Hydrate with accountability status from scoreboard data
    // Return: { active: [], pending: [], slots: {used: N, max: 8} }
}
```

#### GP-9: Dashboard.svelte Partners Display
**Where:** `wirebot-core/cmd/scoreboard/ui/src/lib/Dashboard.svelte`

Changes:
1. Fetch from `/v1/growth-partners` instead of `/v1/network/members?limit=8`
2. Show accountability dot on each avatar:
   - 🟢 Green = completed daily check-in today
   - 🟡 Yellow = hasn't checked in yet (but day not over)
   - 🔴 Red = missed yesterday's check-in
3. Show pending requests inline
4. Slots indicator: "3/8 Growth Partners"

#### GP-10: Designate from Wins Dashboard
**Where:** `wirebot-core/cmd/scoreboard/ui/src/lib/PairingFlow.svelte` (extend)

Add "Designate Growth Partner" action:
1. User clicks "+" avatar in partners row
2. Modal shows BP friends list (fetched from RL `/v1/user/{id}/friends`)
3. Select friend → same consent dialog as GP-5
4. POST to scoreboard `/v1/growth-partners` → proxied to RL

---

### Layer 4: Chrome Extension (GP-11)

#### GP-11: Extension Sidepanel GP Section
**Where:** `startempire-wire-network-ext/src/pages/sidepanel/Sidepanel.svelte`

Add collapsible "Growth Partners" section:
- Fetches from Connect REST `/sewn-connect/v1/growth-partners`
- Avatar row with accountability dots
- "Designate" action on friends list
- Links to BP profile on click

---

### Layer 5: Wirebot Integration (GP-12, GP-13)

#### GP-12: Accountability Nudge Routing
**Where:** `wirebot-core/cmd/scoreboard/main.go` (daily cron)

When user has active GPs:
```
Morning standup includes:
"Your growth partner Alex completed 4/5 tasks yesterday and hit a revenue milestone. 
 You completed 2/5. Let's pick up the pace today."

EOD review includes:
"Alex shipped 3 tasks today. You shipped 1. Tomorrow's priority: [next task]."
```

Nudge intensity matches user's `accountability_preference` from pairing profile (diplomatic/direct/drill-sergeant).

#### GP-13: Partner Activity Feed
**Where:** `wirebot-core/cmd/scoreboard/main.go` + Letta context injection

Wirebot chat context includes GP summaries:
- Fetched from Ring Leader on each session start
- Only **public milestones**: "launched website", "hit $5K MRR", "shipped product"
- NOT private: task content, revenue numbers, chat history
- Injected into Letta/Clawdbot system prompt as context block

---

### Layer 6: Visual & Polish (GP-14 thru GP-16)

#### GP-14: CSS Components
**Where:** `bricks-child/assets/css/v2/growth-partners.css` (new)

```css
/* Badge on BP profile */
.sewn-gp-badge { 
    background: var(--sew-accent, #32a2c1); color: #fff;
    padding: 6px 16px; border-radius: 24px; font-size: 13px;
}
.sewn-gp-badge.active { background: var(--sew-success, #10b981); }

/* OAuth dialog */
.sewn-gp-overlay { position: fixed; inset: 0; z-index: 99999;
    background: rgba(10,36,52,0.7); backdrop-filter: blur(4px);
    display: flex; align-items: center; justify-content: center; }
.sewn-gp-dialog { background: #fff; border-radius: 16px; max-width: 480px;
    width: 90vw; padding: 32px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }

/* Slots indicator */
.sewn-gp-slots { display: flex; gap: 6px; }
.sewn-gp-slot { width: 12px; height: 12px; border-radius: 50%;
    background: var(--sew-border, #e2e8f0); }
.sewn-gp-slot.filled { background: var(--sew-accent, #32a2c1); }

/* Member portal cards */
.sewn-gp-card { border-radius: 12px; border: 1px solid var(--sew-border);
    padding: 20px; text-align: center; }
.sewn-gp-card .avatar { width: 64px; height: 64px; border-radius: 50%; }

/* Accountability dot */
.sewn-gp-dot { width: 10px; height: 10px; border-radius: 50%;
    border: 2px solid #fff; position: absolute; bottom: 2px; right: 2px; }
.sewn-gp-dot.green { background: #10b981; }
.sewn-gp-dot.yellow { background: #f59e0b; }
.sewn-gp-dot.red { background: #ef4444; }
```

#### GP-15: Mobile Responsive
- Dialog → full-screen overlay at ≤480px
- Member portal GP tab → single-column card stack
- Avatar row → horizontal scroll with scroll-snap
- Button states → full-width on narrow screens

#### GP-16: Visual Verification
Rod screenshots at 1440px + 390px for all GP surfaces.

---

## 4. DEPENDENCY CHAIN

```
GP-1 (DB) ──────────────→ GP-2 (RL hardening) ──→ GP-3 (Connect relay)
                                                       │
                          ┌────────────────────────────┘
                          │
GP-14 (CSS) ──→ GP-4 (BP button) ──→ GP-5 (consent dialog)
                          │                   │
                          │                   └──→ GP-6 (notifications)
                          │
                          └──→ GP-7 (portal tab)
                          
GP-3 ──→ GP-8 (scoreboard endpoint) ──→ GP-9 (Dashboard.svelte)
                                            │
                                            └──→ GP-10 (designate from Wins)

GP-3 ──→ GP-11 (Chrome extension)

GP-9 ──→ GP-12 (Wirebot nudges) ──→ GP-13 (activity feed)

GP-4 + GP-7 + GP-9 ──→ GP-15 (mobile) ──→ GP-16 (screenshots)
```

**Critical path:** GP-1 → GP-2 → GP-3 → GP-4 → GP-5 → GP-16

---

## 5. SURFACES WHERE GP APPEARS

| Surface | Location | Action Available |
|---------|----------|-----------------|
| BP Member Profile | `/members/{user}/` | Designate/Accept/Remove GP |
| Member Portal | `/member-portal/#growth-partners` | Manage all GP, accept/decline |
| Wins Dashboard | `wins.wirebot.chat` | View GP, designate from friends |
| Chrome Extension | Sidepanel → Network section | View GP, designate from friends |
| Wirebot Chat | Daily standup context | Read-only GP activity |
| BP Notifications | Bell icon + email | Request/Accept/Decline/Remove |
| SEWN Overlay | Overlay panel (future) | View GP count |

---

## 6. DATA FLOW

```
User clicks "Designate GP" on BP profile
    │
    ▼
OAuth-style dialog shown (GP-5)
    │
    ▼ [Confirm]
AJAX POST to wp-admin/admin-ajax.php?action=sewn_gp_request
    │
    ▼
Connect plugin relays to Ring Leader (GP-3)
    │
    ▼
Ring Leader creates pending row in DB (GP-1, GP-2)
    │
    ├──→ BP notification to partner (GP-6)
    ├──→ Email to partner (GP-6)
    │
    ▼ [Partner accepts]
Ring Leader sets status=active, creates bilateral row
    │
    ├──→ BP notification to requester (GP-6)
    ├──→ Wins dashboard auto-refreshes partner list (GP-9)
    ├──→ Wirebot picks up new GP context on next session (GP-12)
    └──→ Chrome extension reflects new partner (GP-11)
```

---

## 7. FILES TO CREATE / MODIFY

### New Files
| File | Purpose |
|------|---------|
| `ring-leader/includes/class-db.php` | DB table creation + migration |
| `sewn-connect/inc/class-gp-rest.php` | Growth Partners REST relay endpoints |
| `sewn-connect/inc/class-gp-notifications.php` | BP notifications + email |
| `sewn-connect/assets/js/sewn-gp-dialog.js` | Consent dialog JS |
| `sewn-connect/assets/css/sewn-gp.css` | GP-specific plugin styles |
| `bricks-child/assets/css/v2/growth-partners.css` | Theme GP styles |

### Modified Files
| File | Changes |
|------|---------|
| `ring-leader/includes/api/class-rest-controller.php` | Rewrite GP endpoints to use table, add accept/decline, limit 8 |
| `sewn-connect/public/class-startempire-wire-network-connect-public.php` | Full state machine in `add_connect_button()` |
| `sewn-connect/inc/class-rest-api.php` | Register GP relay routes |
| `bricks-child/inc/community/member-portal.php` | Add Growth Partners tab |
| `bricks-child/functions.php` | Enqueue GP CSS |
| `wirebot-core/cmd/scoreboard/main.go` | Add /v1/growth-partners handler |
| `wirebot-core/cmd/scoreboard/ui/src/lib/Dashboard.svelte` | Fetch from GP API, show dots |
| `startempire-wire-network-ext/src/pages/sidepanel/Sidepanel.svelte` | Add GP section |

---

## 8. WINS DASHBOARD DATA INVENTORY — GP Visibility Classification

Every data surface on the Wins dashboard must be classified for Growth Partner visibility.
The Wins dashboard (`wins.wirebot.chat`) has **5 tabs** and **~60 distinct data fields** across them.

### Tab 1: Dashboard (Home)

| Data Surface | Field/Value | Source | GP Visibility | Rationale |
|---|---|---|---|---|
| Welcome header | `display_name` | WP user | ✅ PUBLIC | Already visible on BB profile |
| Score pill | `execution_score` (0-100) | DailyScore | ❓ **DECISION NEEDED** | This is the core accountability signal — sharing it drives mutual pressure, but it's also a deeply personal performance metric |
| Streak | `streak.current` (consecutive win days) | Scoreboard | ❓ **DECISION NEEDED** | Same tension — powerful accountability signal but reveals work habits |
| Neural drift | `drift.score` + `drift.signal` | Pairing engine | 🔴 PRIVATE | Measures how well Wirebot understands the operator — personal AI calibration, no accountability value |
| Business filter | Entity names (SEW, SEWN, WB, PVD) | Hardcoded | 🔴 PRIVATE | Reveals full business portfolio including unrevealed ventures |
| Setup progress | `checklist.percent`, `completed/total` | Checklist engine | ❓ **DECISION NEEDED** | Shows how far along the founder is in business setup — useful accountability signal but could feel exposing |
| Next task | `nextTask.title` | Checklist | 🔴 PRIVATE | Specific task content is operational detail, not accountability |
| Onboarding cards | Pairing/Revenue/Feed CTAs | Static | 🔴 PRIVATE | Internal onboarding state |
| **Growth Partners** | Avatar row, names, links | Ring Leader | ✅ PUBLIC | The GP section itself — obviously visible |
| Network feed | Posts/events from RL | Ring Leader content API | ✅ PUBLIC | Already public network content |
| Similar founders | Name, tier, match_reason | Ring Leader | 🔴 PRIVATE | Reveals who the system thinks is similar — competitive intelligence |
| Network score | Score + level + breakdown | Ring Leader | ❓ **DECISION NEEDED** | Community engagement metric — less personal than execution score |
| AI recommendations | Personalized content/member suggestions | Ring Leader | 🔴 PRIVATE | Reveals the AI's model of what the founder needs |
| Stage selector | `stage` (idea/launch/growth) | Checklist | ❓ **DECISION NEEDED** | High-level business maturity — GPs probably already know this from conversation, but making it programmatic changes the dynamic |
| Business setup tasks | Category list + task titles + completion | Checklist | 🔴 PRIVATE | Granular operational detail (e.g., "Choose Payment Processing", "Register LLC") |
| Daily standup tasks | Task titles + checkbox state | Checklist daily | 🔴 PRIVATE | Most intimate operational data — what the founder committed to doing today |
| Wirebot proposals | Auto-inferred task completions + evidence snippets | Proposals engine | 🔴 PRIVATE | AI inference about what the founder has/hasn't done, sourced from private documents |
| Wirebot suggestions | AI-generated advice cards | Score + checklist | 🔴 PRIVATE | Personalized advice based on weaknesses |
| Ask Wirebot bar | Chat input/response | Chat API | 🔴 PRIVATE | Conversational content |
| Alerts | Critical/warning/info notifications | Scoreboard | 🔴 PRIVATE | System alerts (stalling, missed days, etc.) |

### Tab 2: Score

| Data Surface | Field/Value | Source | GP Visibility | Rationale |
|---|---|---|---|---|
| Execution score ring | `score` (0-100) + signal color | DailyScore | ❓ (same as above) | |
| Stall alert | `stall_hours` ("NO SHIP IN 48H") | Scoreboard | 🔴 PRIVATE | Shaming-level detail about inactivity |
| Intent | Free text: "What I'm shipping today" | DailyScore.Intent | ❓ **DECISION NEEDED** | User-written daily commitment — sharing this creates powerful accountability ("I said I'd ship X, did I?"), but it's voluntarily composed text |
| Season/day | `season.name`, `season_day` | Season | ✅ PUBLIC | Calendar context, no private info |
| Streak + Best | `streak.current`, `streak.best` | Scoreboard | ❓ (same as streak above) | |
| W-L record | `record` ("12-5") | Season | ❓ **DECISION NEEDED** | Season win/loss — aggregated enough to not be shaming but revealing |
| Ships today | `ship_today` count | DailyScore | ❓ **DECISION NEEDED** | Number of things shipped — accountability metric, no content |
| Possession | "OPERATOR" or "WIREBOT" | Scoreboard | 🔴 PRIVATE | Internal game mechanic |
| Lane breakdown | Shipping/Distribution/Revenue/Systems scores + max | DailyScore lanes | 🔴 PRIVATE | Granular performance decomposition — reveals which business areas are weak |
| Streak bonus / Penalties | `streak_bonus`, `penalties` | DailyScore | 🔴 PRIVATE | Scoring internals |
| Day/Week/Season clocks | Progress bars (% through time periods) | Clock | ✅ PUBLIC | Same calendar for everyone |
| Last ship | `last_ship` text | Events | 🔴 PRIVATE | Specific thing that was shipped — operational detail |

### Tab 3: Feed

| Data Surface | Field/Value | Source | GP Visibility | Rationale |
|---|---|---|---|---|
| Event stream | Individual ship/revenue/distribution events | Events DB | 🔴 PRIVATE | Raw operational events (git commits, deploys, payments) |
| Projects list | Project names + pending/approved counts | Projects API | 🔴 PRIVATE | Reveals codebase and infrastructure |
| Event metadata | File paths, commit messages, amounts | Events DB | 🔴 PRIVATE | Deepest level of private operational data |
| Memory review items | AI-extracted facts pending approval | Memory queue | 🔴 PRIVATE | What Wirebot learned from conversations |

### Tab 4: Season

| Data Surface | Field/Value | Source | GP Visibility | Rationale |
|---|---|---|---|---|
| Season progress | Start/end dates, days elapsed | Season | ✅ PUBLIC | Calendar |
| Calendar heatmap | Day-by-day win/loss colors | History | ❓ **DECISION NEEDED** | Visual pattern of consistency — powerful accountability but also reveals every bad day |
| Season Wrapped | Record, total ships, best streak, avg score, revenue events, days won/played | Season summary | ❓ **DECISION NEEDED** | End-of-season retrospective — could be a shareable "badge" |

### Tab 5: Settings / Profile

| Data Surface | Field/Value | Source | GP Visibility | Rationale |
|---|---|---|---|---|
| Founder Profile | 7 construct dimensions (Action Style, Communication DNA, Energy Topology, Risk Disposition, Business Reality, Temporal Patterns, Cognitive Style) | Pairing engine | 🔴 PRIVATE | Deep psychological/behavioral profile — the most intimate data in the system |
| Profile accuracy | `overall_accuracy`, `days_active` | Pairing engine | 🔴 PRIVATE | AI calibration internals |
| Wirebot complement | What Wirebot compensates for | Pairing engine | 🔴 PRIVATE | Reveals founder weaknesses |
| Evidence trail | Behavioral observations backing profile scores | Pairing engine | 🔴 PRIVATE | Raw behavioral analysis |
| Integration configs | Stripe keys, GitHub tokens, Plaid connections | OAuth/integrations | 🔴 PRIVATE | Credentials and financial connections |
| Financial snapshot | Revenue 30d/90d, MRR, charges | Stripe webhook data | 🔴 PRIVATE | Actual dollar amounts |
| OPERATOR_REALITY.md | Debt profile, revenue streams, monthly burn | Workspace file | 🔴 PRIVATE | The most sensitive document in the entire system |

### Letta/Mem0 Memory Layers (Not Directly in UI But Accessible to Wirebot)

| Data Layer | Content | GP Visibility | Rationale |
|---|---|---|---|
| Mem0 facts | LLM-extracted facts from conversations (80+ facts) | 🔴 PRIVATE | Conversational intelligence |
| Letta blocks | `human`, `business_stage`, `goals`, `kpis` structured state | 🔴 PRIVATE | AI's internal model of the founder |
| Letta archival | PAIRING, SCOREBOARD_PRODUCT, OPERATOR_REALITY, SOUL docs | 🔴 PRIVATE | Core identity documents |
| IDENTITY.md | Preferred name, timezone, communication style | 🔴 PRIVATE | Personal preferences |
| SOUL.md | Wirebot's personality calibration for this operator | 🔴 PRIVATE | AI relationship configuration |
| Chat sessions | Full conversation history with Wirebot | 🔴 PRIVATE | Private conversations |

---

## 9. DESIGN DECISIONS (Locked In)

All five design questions answered. These are final architectural constraints.

---

### D1: THREE-TIER GRANULAR VISIBILITY — User Controls What Each Partner Sees

**Decision:** Three visibility tiers. Each GP relationship has a tier set by EACH party independently. The *effective* visibility is the MINIMUM of both parties' settings (mutual floor). Users configure per-partner via the Member Portal GP tab and the Wins dashboard.

#### Tier 1 — "Peer" (default when GP is first accepted)
| Field | Shared | Hint Text |
|---|---|---|
| `execution_score` (0-100) | ✅ | "Your overall daily performance score. Higher = more shipped." |
| `signal` (green/yellow/red) | ✅ | "Green = winning. Yellow = under pressure. Red = stalling." |
| `streak.current` | ✅ | "Consecutive days of winning. Breaks to 0 on a loss." |
| `ships_today` count | ✅ | "Number of things shipped today. Not what — just how many." |
| `W-L record` | ✅ | "Season win-loss record. A 'win' is scoring above threshold." |
| `stage` (idea/launch/growth) | ✅ | "Which phase of business building you're in." |
| `network_score` | ✅ | "Your community engagement level on Startempire Wire." |
| `season.name` + `season_day` | ✅ | "Which season and what day of the season." |

**What Tier 1 feels like:** You can see if your partner is executing. You know their score, their streak, their stage. Enough to say "nice streak!" or "rough week?" in a DM. No operational detail.

#### Tier 2 — "Accountability Partner" (opt-in upgrade, requires mutual consent)
Everything in Tier 1, PLUS:

| Field | Shared | Hint Text |
|---|---|---|
| `intent` (daily commitment text) | ✅ | "What you wrote you'd ship today. Your partner sees this — choose words wisely." |
| `intent_fulfilled` (boolean) | ✅ | "Did you do what you said? Partners will know." |
| `setup_percent` | ✅ | "How much of the business setup checklist is complete." |
| `calendar_heatmap` (day-by-day win/loss) | ✅ | "A visual grid of your daily wins and losses. Shows patterns." |
| `season_wrapped` (end-of-season summary) | ✅ | "Your season retrospective card. Auto-shared at season end." |
| `stall_hours` (how long since last ship) | ✅ | "Hours since you last shipped anything. Gets uncomfortable above 24." |

**What Tier 2 feels like:** The gym buddy. Your partner sees your daily commitment (intent) and whether you followed through. They see your consistency heatmap. When you say "I'll launch the site today" and don't, they'll know. This is where real accountability lives.

**Wirebot involvement at Tier 2:** Each user's Wirebot instance includes GP Tier 2 data in its daily standup context. Wirebot A says to User A: "Your partner Alex committed to shipping a landing page today. You committed to finishing onboarding — you're at 65%." Both Wirebots operate independently but share the same data from Ring Leader.

#### Tier 3 — "Inner Circle" (explicit opt-in, OAuth-style re-consent)
Everything in Tier 2, PLUS:

| Field | Shared | Hint Text |
|---|---|---|
| `daily_task_titles` (not descriptions) | ✅ | "The titles of today's tasks — not the details, but what you're working on." |
| `lane_breakdown` (Shipping/Distribution/Revenue/Systems) | ✅ | "Which business lanes you're strong/weak in. Reveals where you're neglecting." |
| `checklist_categories` (category names + completion %) | ✅ | "Which setup categories are done and which aren't (e.g., 'Legal 80%', 'Marketing 20%')." |
| `last_ship` text | ✅ | "What you most recently shipped — the actual thing, not just the count." |

**What Tier 3 feels like:** Co-founder visibility. Your partner knows what you're working on today, which lanes you're neglecting, and what you last shipped. This is for partners you trust enough to say "you haven't touched marketing in 3 weeks" and hear it.

**Tier 3 re-consent:** Upgrading to Tier 3 triggers a SECOND OAuth-style dialog with a distinct warning: "Inner Circle partners see your task titles, lane weaknesses, and what you shipped. This is co-founder level visibility. Both partners must opt in." Both Wirebots are notified of the tier change.

#### What is NEVER shared at any tier
| Field | Why |
|---|---|
| Financial data (revenue, MRR, debt, Stripe data) | Actual dollar amounts are radioactive. Even between co-founders this is opt-in IRL. |
| Pairing profile (7 constructs, psychological dimensions) | This is the AI's behavioral model of you. Deeply personal. |
| Chat history with Wirebot | Private conversations. |
| OPERATOR_REALITY.md contents | Debt profile, burn rate — the most sensitive doc in the system. |
| Mem0 facts / Letta blocks | AI's extracted intelligence about you. |
| Task descriptions (only titles at Tier 3) | Operational detail that could contain client names, deal terms, etc. |
| Wirebot proposals + evidence snippets | AI inferences from private documents. |
| Integration credentials | Obviously. |
| Neural drift score | AI calibration metric, no accountability value. |
| Business entity names (portfolio) | Reveals unrevealed ventures. |

#### Tier Configuration UX

**In Member Portal → Growth Partners tab:**
Each partner card shows a tier selector:
```
┌──────────────────────────────────────┐
│  👤 Alex K.          since Jan 2026  │
│  ● Active Growth Partner             │
│                                      │
│  YOUR SHARING LEVEL:                 │
│  [● Peer] [ Accountability] [ Inner] │
│                                      │
│  ALEX'S SHARING LEVEL:               │
│  [● Peer] [  ] [  ]                  │
│                                      │
│  ℹ️ Effective: Peer (mutual minimum)  │
│  [Remove Partner]                    │
└──────────────────────────────────────┘
```

**In Wins Dashboard → GP section:**
Tapping a partner avatar opens a flyout with their shared data (respecting effective tier) + tier controls.

**Detailed hints:** Every field in the tier tables above has a `hint` string. These are shown as tooltips/info-icons in both the tier selection UI ("here's what you're sharing") and when viewing a partner's data ("here's what this number means").

---

### D2: INTENT IS SHARED AT TIER 2 — Wirebot Is the Courier

**Decision:** `DailyScore.Intent` is shared with Tier 2+ partners. This is the core accountability mechanism.

**How it works:**
1. User A writes intent in Wins dashboard: "Ship the pricing page and fix mobile nav"
2. User A's Wirebot instance pushes intent to Ring Leader as part of daily GP summary
3. Ring Leader stores it, keyed to User A, timestamped
4. User B's Wirebot instance fetches GP summaries from Ring Leader during daily standup
5. User B's Wirebot says: "Your partner Verious committed to shipping the pricing page and fixing mobile nav today. You committed to launching the email sequence."
6. At EOD, User B's Wirebot says: "Verious fulfilled his intent (score: 72). You scored 45 — the email sequence didn't ship."

**Each user's Wirebot is independently involved:**
- Wirebot A and Wirebot B are separate instances with separate pairing profiles
- They share GP data ONLY through Ring Leader (never direct)
- Each Wirebot adapts GP nudge language to its operator's `accountability_preference`:
  - Diplomatic: "Alex had a tough day — only hit 30. Might be a good time to check in."
  - Direct: "Alex scored 30 and missed their intent. You scored 85. Offer to help?"
  - Drill Sergeant: "Alex is stalling at 30. You're crushing it at 85. Don't let your partner drag. Send a message."

**Intent privacy guardrail:** The consent dialog for Tier 2 explicitly warns: "Your daily intent text will be shared with this partner. Write intents you're comfortable being held to."

---

### D3: SEASON WRAPPED IS SHAREABLE BY DEFAULT — Configurable Detail Layers

**Decision:** Season Wrapped is auto-shared with all GPs at season end. Users can configure additional detail layers and optionally attach Wirebot's season analysis.

#### Base Wrapped Card (always shared with GPs)
```
┌─────────────────────────────────────────┐
│  🎬 SEASON 3 WRAPPED                    │
│  "The Shipping Season"                  │
│                                         │
│  RECORD:      15W - 8L                  │
│  TOTAL SHIPS: 47                        │
│  BEST STREAK: 7 days                    │
│  AVG SCORE:   62                        │
│  DAYS PLAYED: 23                        │
│                                         │
│  🏆 Achievement: "Consistent Shipper"   │
└─────────────────────────────────────────┘
```

#### Configurable Add-Ons (toggle per season, before sharing)
| Add-On | Content | Default |
|---|---|---|
| **Lane Breakdown** | Shipping/Distribution/Revenue/Systems season averages | OFF |
| **Top Ships** | List of 3-5 most impactful things shipped (user-curated or auto-picked) | OFF |
| **Streak Graph** | Visual chart of score over the season | ON |
| **Wirebot Analysis** | AI-generated 2-3 sentence season reflection | OFF |

#### Wirebot Analysis Example
```
"Verious had a strong shipping season (47 ships, 15W-8L) but distribution
lagged — most work stayed in the build phase. Revenue lane activated in
week 3 after Stripe integration. Recommendation for next season: shift
30% of shipping energy to distribution."
```

**Configuration UX:** Before a season closes, a "Prepare Your Wrapped" screen lets the user toggle add-ons and preview the card. The card is then pushed to Ring Leader and visible to all GPs.

**Public sharing:** Users can also generate a shareable link (like Spotify Wrapped) for social/non-GP audiences. This version strips any Wirebot analysis and only shows the base card.

---

### D4: RING LEADER MEDIATED + ANONYMIZABLE

**Decision:** All GP data flows through Ring Leader. Each user's scoreboard pushes a daily summary to Ring Leader. Partners fetch FROM Ring Leader. Data is anonymizable per-relationship.

#### Daily GP Summary Push (Scoreboard → Ring Leader)

Each operator's scoreboard runs a cron (or post-score hook) that pushes:
```json
POST /ring-leader/v1/member/gp-summary
{
  "date": "2026-02-08",
  "tier_1": {
    "execution_score": 72,
    "signal": "green",
    "streak": 5,
    "ships_today": 3,
    "record": "15-8",
    "stage": "launch",
    "network_score": 420,
    "season_day": "Day 23"
  },
  "tier_2": {
    "intent": "Ship the pricing page and fix mobile nav",
    "intent_fulfilled": true,
    "setup_percent": 65,
    "stall_hours": 2,
    "calendar_heatmap": ["W","W","L","W","W","W","W","L","W"]
  },
  "tier_3": {
    "daily_task_titles": ["Pricing page layout", "Mobile nav hamburger", "Stripe webhook test"],
    "lane_breakdown": { "shipping": 28, "distribution": 8, "revenue": 15, "systems": 12 },
    "checklist_categories": [
      { "name": "Legal", "percent": 80 },
      { "name": "Marketing", "percent": 20 }
    ],
    "last_ship": "Pricing page v2 deployed"
  }
}
```

Ring Leader stores this per-user, per-date. When Partner B requests Partner A's data, Ring Leader:
1. Checks the effective tier (min of A→B setting and B→A setting)
2. Returns ONLY the fields for that tier
3. Server-side filtering — the higher-tier data never leaves Ring Leader for unauthorized requests

#### Anonymization

Users can toggle **"Anonymize my GP data"** per-relationship. When enabled:
- `display_name` → replaced with a consistent pseudonym (e.g., "Founder #7A3")
- `intent` → run through a lightweight LLM pass that strips proper nouns: "Ship the pricing page for Acme Corp" → "Ship the pricing page for [client]"
- `daily_task_titles` → same noun-stripping
- `last_ship` → same
- Avatar → replaced with a generic silhouette
- All numeric data stays intact (scores, streaks, lanes — these are already abstract)

**Why per-relationship:** A user might be Tier 3 with one GP (full names, full detail) and Tier 1 + anonymized with another (just scores, pseudonym). Anonymization is independent of tier.

#### Ring Leader Endpoints (New/Modified)

```
POST   /ring-leader/v1/member/gp-summary              → Push daily summary (from scoreboard cron)
GET    /ring-leader/v1/member/gp-summary/{partner_id}  → Fetch partner's summary (tier-filtered)
PUT    /ring-leader/v1/member/gp-tier/{partner_id}     → Set visibility tier for a partner
GET    /ring-leader/v1/member/gp-tier/{partner_id}     → Get effective tier (min of both)
PUT    /ring-leader/v1/member/gp-anonymize/{partner_id} → Toggle anonymization for a partner
POST   /ring-leader/v1/member/gp-wrapped               → Push season wrapped card
GET    /ring-leader/v1/member/gp-wrapped/{partner_id}  → Fetch partner's wrapped card
```

---

### D5: MUTUAL CONSENT ENFORCEMENT MODE — GPs Choose, Wirebot Enforces

**Decision:** Both GPs mutually choose an enforcement mode during the GP accept flow. Wirebot enforces it. Either party can change the mode, but upgrades require re-consent from the other party.

#### Enforcement Modes (chosen at GP acceptance, changeable later)

| Mode | Wirebot Behavior | Best For |
|---|---|---|
| **🕊 Gentle Awareness** | Wirebot mentions GP status only when contextually relevant. "By the way, Alex scored 85 yesterday." No proactive nudging about the partner. | New GP relationships, casual accountability |
| **📢 Active Check-In** | Wirebot proactively notifies each partner about the other's daily status. "Alex committed to shipping the landing page — they scored 72, intent fulfilled." When a partner stalls (>24h no ship), Wirebot alerts the other: "Alex hasn't shipped in 36 hours." | Serious accountability partners |
| **🔥 Drill Mode** | Everything in Active Check-In, plus Wirebot directly nudges the stalling partner ON BEHALF of the other: "Your growth partner is wondering where you are. You committed to shipping X. It's been 48 hours. Check in." Also surfaces in daily standup/EOD. | Deep trust, co-founder energy |

#### How Mutual Consent Works

1. **At GP accept:** Both parties independently select an enforcement mode as part of the acceptance flow (after the OAuth-style consent dialog)
2. **Effective mode = the LOWER of both selections** (same as tier). If A picks Drill Mode and B picks Gentle Awareness, effective mode is Gentle Awareness
3. **Mode change request:** Either party can request a mode change. The other party gets a notification: "Alex wants to upgrade accountability to Active Check-In. [Accept] [Keep Current]"
4. **Downgrade is instant:** Either party can downgrade (e.g., Active → Gentle) without consent — you can always reduce what's done TO you. Wirebot notifies the other: "Your partner Alex changed accountability mode to Gentle Awareness."
5. **Upgrade requires consent:** Moving up (Gentle → Active, Active → Drill) requires the other party's accept

#### Wirebot Enforcement Implementation

Each user's Wirebot instance independently:
- Fetches GP enforcement mode from Ring Leader alongside daily GP summary
- Adapts its standup/EOD/nudge behavior based on the effective mode
- Uses the operator's personal `accountability_preference` (diplomatic/direct/drill-sergeant from pairing) to STYLE the message, while the enforcement MODE determines WHAT triggers the message

**Example matrix:**

| Enforcement Mode | Operator Pref | Wirebot Says When Partner Stalls |
|---|---|---|
| Gentle | Diplomatic | *(nothing proactive — only if asked)* |
| Gentle | Drill Sergeant | *(nothing proactive — mode overrides pref)* |
| Active | Diplomatic | "Alex hasn't checked in today. Might be worth a quick message." |
| Active | Drill Sergeant | "Alex is MIA — 36 hours, no ship. You going to let that slide?" |
| Drill | Diplomatic | Nudges partner: "Your accountability partner is checking in. How's today going?" |
| Drill | Drill Sergeant | Nudges partner: "Your partner wants an update. You committed to X. Ship it or explain." |

#### Wirebot Cross-Instance Communication Flow

```
User A stalls (>24h, Active/Drill mode)
    │
    ▼
User A's scoreboard pushes stall status to Ring Leader
    │
    ▼
Ring Leader stores stall flag in A's GP summary
    │
    ├──→ User B's Wirebot fetches A's summary from RL
    │    → Wirebot B alerts User B (Active/Drill)
    │
    └──→ [Drill Mode only] Ring Leader queues a nudge for User A
         → User A's Wirebot fetches nudge queue from RL
         → Wirebot A delivers the nudge to User A
         (styled per A's accountability_preference)
```

Key: Wirebot B never directly contacts Wirebot A. All communication is mediated through Ring Leader. Each Wirebot only talks to its own operator.

---

## 10. UPDATED TASK LIST (Post-Decisions)

New tasks needed based on locked-in decisions:

| ID | Description | Depends On | Priority |
|---|---|---|---|
| GP-17 | Tier system — DB columns for per-partner `tier_setting` (1/2/3) + `anonymize` (bool) in Ring Leader | GP-1 | P1 |
| GP-18 | Tier selection UI — Member Portal per-partner tier selector + hint tooltips | GP-7, GP-26 | P2 |
| GP-19 | Daily GP summary push — scoreboard cron/hook → Ring Leader `POST /gp-summary` | GP-8 | P1 |
| GP-20 | Ring Leader GP summary storage + tier-filtered `GET /gp-summary/{partner_id}` | GP-2, GP-17 | P1 |
| GP-21 | Enforcement mode — selection in accept flow + change-request consent notification | GP-5 | P1 |
| GP-22 | Wirebot enforcement integration — standup/EOD/nudge routing per mode × pref matrix | GP-12, GP-21 | P2 |
| GP-23 | Anonymization engine — per-relationship toggle + LLM noun-stripping for intent/titles | GP-20 | P2 |
| GP-24 | Season Wrapped push — configurable add-ons UI + `POST /gp-wrapped` to Ring Leader | GP-9 | P2 |
| GP-25 | Wrapped sharing — public link generation + social card image (OG meta) | GP-24 | P3 |
| GP-26 | Hint text system — tooltip content for all 18 shared fields across 3 tiers | GP-14 | P2 |

**Updated critical path:**
```
GP-1 → GP-17 (tier schema) → GP-20 (RL summary storage) → GP-19 (push cron)
                                                          → GP-23 (anonymization)
GP-2 → GP-20
GP-4 → GP-5 → GP-21 (enforcement mode in accept flow)
GP-12 → GP-22 (Wirebot enforcement)
GP-9 → GP-24 (Wrapped) → GP-25 (sharing)
GP-14 → GP-26 (hints) → GP-18 (tier UI)
```

**Total tasks: 26** (original 16 + 10 new from decisions)
