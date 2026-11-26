<?php
require_once __DIR__ . '/../../config.php';

/**
 * ============================================================
 * Mezmur Department Dashboard (መዝሙር ክፍል) — SEPARATED
 * ============================================================
 *
 * This is an HTML shell. It contains:
 *   - Structure and layout (HTML only)
 *   - No database queries (those are in admin/api_mezmur.php)
 *   - No inline CSS (theme.css + themes/components.css)
 *   - No inline JavaScript logic (that's in frontend/js/mezmur.js)
 *   - School identity comes from window.APP (set by base.php)
 *
 * To redesign for a new school: change theme.css only.
 * To change logic: edit mezmur.js or api_mezmur.php.
 * This file almost never changes.
 * ============================================================
 */

$pageTitle  = 'Mezmur Department';
$pageScript = 'mezmur';
$pageScripts = ['mezmur_player'];
$bodyClass  = 'page-mezmur';

// Only mezmur staff and admins may open the mezmur dashboard.
// (base.php enforces this list; the mezmur API is guarded separately.)
$requiredRoles = ['super_admin', 'school_admin', 'mezmur_dept'];
$requiredFeature = 'mezmur';

ob_start();
?>
<!-- ═══ MAIN LAYOUT ═══ -->
<div class="school-layout">

    <!-- ═══ SIDEBAR ═══ -->
    <aside class="school-sidebar">
        <div class="school-brand">
            <div class="school-brand-logo"><i class="fa-solid fa-music"></i></div>
            <div>
                <div class="school-brand-name"><span data-school-short></span> Mezmur</div>
                <div class="school-brand-sub amharic">መዝሙር ክፍል</div>
            </div>
        </div>

        <nav class="school-nav-section" aria-label="Mezmur sections">
            <div class="school-nav-title">Mezmur</div>
            <ul class="school-nav-list">
                <li><button class="school-nav-link active" data-section="overview"><i class="fa-solid fa-gauge-high"></i> Overview</button></li>
                <li><button class="school-nav-link" data-section="library"><i class="fa-solid fa-book-open"></i> Hymn Library</button></li>
                <li><button class="school-nav-link" data-section="catalog"><i class="fa-solid fa-tags"></i> Catalog</button></li>
                <li><button class="school-nav-link" data-section="attendance"><i class="fa-solid fa-inbox"></i> Submissions</button></li>
                <li><button class="school-nav-link" data-section="analytics"><i class="fa-solid fa-chart-column"></i> Analytics</button></li>
                <li><button class="school-nav-link" data-section="takers"><i class="fa-solid fa-user-shield"></i> Attendance Takers</button></li>
                <li><button class="school-nav-link" data-comm-open="inbox"><i class="fa-solid fa-comments"></i> Communication</button></li>
                <li><a class="school-nav-link" href="<?= e(ssms_app_url('admin/account.php')) ?>"><i class="fa-solid fa-user-shield"></i> My Profile</a></li>
            </ul>
        </nav>

        <div class="school-sidebar-footer">
            <!-- Theme toggle -->
            <button class="school-theme-toggle" id="themeToggle" onclick="toggleTheme()">
                <span class="school-theme-toggle-label" id="themeLabel">Dark Mode</span>
                <div class="school-theme-toggle-track">
                    <div class="school-theme-toggle-thumb"></div>
                </div>
            </button>

            <div class="school-bell-slot"><?php include __DIR__ . '/../../admin/components/notification_center.php'; ?><?= renderNotificationCenter() ?></div>
            <a href="/admin/account.php" class="school-user-card" title="View &amp; Edit Profile">
                <div class="school-user-avatar" data-user-initials></div>
                <div>
                    <div class="school-user-name" data-user-name></div>
                    <div class="school-user-role">Mezmur • <span data-today></span></div>
                </div>
            </a>
            <a href="/backend/auth/logout.php" class="school-logout-btn">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
        </div>
    </aside>

    <!-- ═══ MAIN CONTENT ═══ -->
    <div class="school-main">

        <!-- Mobile header -->
        <div class="school-topbar">
            <div>
                <h1>Mezmur Department</h1>
                <div class="school-topbar-sub" data-today></div>
            </div>
            <div class="school-bell-inline"><?php include __DIR__ . '/../../admin/components/notification_center.php'; ?><?= renderNotificationCenter() ?></div>
            <div class="school-status-badge">
                <span class="school-status-dot"></span> Online
            </div>
        </div>

        <div class="school-content">

            <!-- ═══ OVERVIEW SECTION ═══ -->
            <section id="section-overview" class="school-section active" data-section="overview">

                <div class="page-head">
                    <div>
                        <h1 id="mzGreeting">Welcome 🎵</h1>
                        <div class="page-head-sub"><span data-today></span></div>
                    </div>
                    <div class="page-head-actions">
                        <button class="btn-secondary btn-sm" onclick="Mezmur.migrateSchema()"
                                title="Align the database with the current code (safe, idempotent)">
                            <i class="fa-solid fa-database"></i> Sync DB schema
                        </button>
                    </div>
                </div>

                <!-- Quick actions (taker tile hidden for non-managers by JS) -->
                <div class="quick-actions">
                    <button class="quick-tile qa-primary" onclick="Mezmur.quickReview()">
                        <i class="fa-solid fa-inbox"></i> Review Queue
                    </button>
                    <button class="quick-tile" onclick="Mezmur.quickLibrary()">
                        <i class="fa-solid fa-book-open"></i> Hymn Library
                    </button>
                    <button class="quick-tile qa-accent2" onclick="Mezmur.quickAnalytics()">
                        <i class="fa-solid fa-chart-column"></i> Run Analytics
                    </button>
                    <button class="quick-tile qa-warning" id="mzQaTakers" onclick="Mezmur.quickTakers()">
                        <i class="fa-solid fa-user-shield"></i> Attendance Takers
                    </button>
                </div>

                <!-- KPI strip -->
                <div class="stat-grid">
                    <div class="school-stat-card">
                        <div class="school-stat-icon si-accent2"><i class="fa-solid fa-calendar-day"></i></div>
                        <div class="school-stat-value" id="mzOvDays">—</div>
                        <div class="school-stat-label">Attendance days this month</div>
                        <div class="stat-delta flat" id="mzOvDaysDelta"></div>
                    </div>
                    <div class="school-stat-card">
                        <div class="school-stat-icon si-ok"><i class="fa-solid fa-percent"></i></div>
                        <div class="school-stat-value" id="mzOvRate">—</div>
                        <div class="school-stat-label">Average attendance rate (month)</div>
                        <div class="stat-delta flat" id="mzOvRateDelta"></div>
                    </div>
                    <div class="school-stat-card">
                        <div class="school-stat-icon si-accent"><i class="fa-solid fa-music"></i></div>
                        <div class="school-stat-value" id="mzOvHymns">—</div>
                        <div class="school-stat-label">Hymns in library</div>
                    </div>
                    <div class="school-stat-card">
                        <div class="school-stat-icon si-info"><i class="fa-solid fa-users"></i></div>
                        <div class="school-stat-value" id="mzOvMembers">—</div>
                        <div class="school-stat-label">Active members</div>
                    </div>
                    <div class="school-stat-card">
                        <div class="school-stat-icon si-warn"><i class="fa-solid fa-user-shield"></i></div>
                        <div class="school-stat-value" id="mzOvTakers">—</div>
                        <div class="school-stat-label">Attendance takers</div>
                    </div>
                    <!-- Attendance alerting Phase 2: Open Attendance Warnings (Mezmur only, server-scoped) -->
                    <a class="school-stat-card" id="aw-dashboard-card" href="/admin/attendance_warnings.php?department=mezmur" title="Open attendance warnings">
                        <div class="school-stat-icon si-warn"><i class="fa-solid fa-triangle-exclamation"></i></div>
                        <div class="school-stat-value" id="mzOvWarnings" data-aw-total>—</div>
                        <div class="school-stat-label">Open Attendance Warnings</div>
                        <div class="stat-delta flat" id="mzOvWarningsSub"></div>
                    </a>
                    <!-- Attendance alerting Phase 4: Monthly Attendance (current Ethiopian month, Mezmur only, server-scoped) -->
                    <a class="school-stat-card" id="am-dashboard-card" href="/admin/attendance_monthly.php?department=mezmur" title="Monthly attendance report (Ethiopian months)">
                        <div class="school-stat-icon si-primary"><i class="fa-solid fa-calendar-days"></i></div>
                        <div class="school-stat-value" id="mzOvMonthly" data-am-below>—</div>
                        <div class="school-stat-label">Monthly Attendance · below threshold</div>
                        <div class="stat-delta flat" id="mzOvMonthlySub"></div>
                    </a>
                </div>

                <!-- Recent activity -->
                <div class="grid-2">
                    <div class="school-card">
                        <div class="school-card-title"><i class="fa-solid fa-calendar-check"></i> Recent Attendance Days</div>
                        <div class="table-shell">
                            <table>
                                <thead>
                                    <tr><th>Date</th><th>Attended</th><th>Rate</th></tr>
                                </thead>
                                <tbody id="mzOvRecentDays">
                                    <tr><td colspan="3"><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="school-card">
                        <div class="school-card-title"><i class="fa-solid fa-music"></i> Recently Updated Hymns</div>
                        <div class="table-shell">
                            <table>
                                <thead>
                                    <tr><th>Title</th><th>Category</th><th>Updated</th></tr>
                                </thead>
                                <tbody id="mzOvRecentHymns">
                                    <tr><td colspan="3"><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Review queue (department inbox preview) -->
                <div class="school-card">
                    <div class="school-card-title"><i class="fa-solid fa-inbox"></i> Attendance Review Queue
                        <span class="text-dim">— packets saved or submitted by takers</span>
                    </div>
                    <div class="table-shell">
                        <table>
                            <thead>
                                <tr><th>Date</th><th>Section</th><th>Marked</th><th>Status</th><th>Updated</th></tr>
                            </thead>
                            <tbody id="mzOvQueue">
                                <tr><td colspan="5"><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- ═══ LIBRARY SECTION ═══ -->
            <section id="section-library" class="school-section" data-section="library">

                <div class="page-head">
                    <div>
                        <h2><i class="fa-solid fa-book-open"></i> Hymn Library</h2>
                        <div class="page-head-sub amharic">የመዝሙር መጻሕፍት ቤት</div>
                    </div>
                    <div class="page-head-actions">
                        <button id="mzAddBtn" class="btn-primary" onclick="Mezmur.openAdd()"><i class="fa-solid fa-plus"></i> Add Hymn</button>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stat-grid">
                    <div class="school-stat-card">
                        <div class="school-stat-icon si-accent2"><i class="fa-solid fa-music"></i></div>
                        <div class="school-stat-value" id="mzStatTotal">—</div>
                        <div class="school-stat-label">Total Hymns</div>
                    </div>
                    <div class="school-stat-card">
                        <div class="school-stat-icon si-ok"><i class="fa-solid fa-circle-check"></i></div>
                        <div class="school-stat-value" id="mzStatActive">—</div>
                        <div class="school-stat-label">Active</div>
                    </div>
                    <div class="school-stat-card">
                        <div class="school-stat-icon si-warn"><i class="fa-solid fa-tags"></i></div>
                        <div class="school-stat-value" id="mzStatCategories">—</div>
                        <div class="school-stat-label">Categories</div>
                    </div>
                </div>

                <div class="school-card">
                    <!-- Toolbar: one compact professional row (P30) -->
                    <div class="toolbar toolbar-compact">
                        <div class="search-wrap">
                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                            <input id="mzSearch" class="school-input" type="search" placeholder="Search by title or lyrics…" autocomplete="off" aria-label="Search hymns"
                                   role="searchbox" aria-controls="mzTbody" aria-describedby="mzSearchStatus">
                        </div>
                        <!-- P42: results changed silently for screen-reader
                             users — the table repainted with no announcement,
                             so there was no way to know whether a query had
                             matched anything. Other sections of this page
                             already use aria-live; the hymn list did not. -->
                        <p id="mzSearchStatus" class="sr-only" role="status" aria-live="polite"></p>
                        <select id="mzCategoryFilter" class="school-input" aria-label="Filter by category">
                            <option value="">All categories</option>
                        </select>
                        <select id="mzZemarianFilter" class="school-input" aria-label="Filter by singer">
                            <option value="">All singers</option>
                        </select>
                        <select id="mzStatusFilter" class="school-input" aria-label="Filter by status">
                            <option value="active">Active</option>
                            <option value="archived">Archived</option>
                            <option value="">All</option>
                        </select>
                        <select id="mzLengthFilter" class="school-input" aria-label="Filter by length">
                            <option value="">All lengths</option>
                            <option value="long">Long</option>
                            <option value="short">Short</option>
                        </select>
                        <select id="mzLanguageFilter" class="school-input" aria-label="Filter by language">
                            <option value="">All languages</option>
                            <option value="amharic">Amharic</option>
                            <option value="geez">Geez (ግዕዝ)</option>
                        </select>
                    </div>

                    <!-- List -->
                    <div class="table-shell">
                        <table>
                            <thead>
                                <tr>
                                    <th class="th-cover" aria-label="Cover art">Art</th>
                                    <th>Title</th>
                                    <th>Category</th>
                                    <th>Updated</th>
                                    <th class="nowrap">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="mzTbody">
                                <tr><td colspan="7"><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div id="mzPagination" class="pager" role="navigation" aria-label="Hymn list pagination"></div>
                </div>
            </section>

            <!-- ═══ ATTENDANCE SECTION ═══ -->
            <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" integrity="sha384-vtjasyidUo0kW94K5MXDXntzOJpQgBKXmE7e2Ga4LG0skTTLeBi97eFAXsqewJjw" crossorigin="anonymous"></script>

<!-- ═══ SECTION: CATALOG (standalone singers/category/subcategory manager) ═══ -->
            <section id="section-catalog" class="school-section" data-section="catalog">
                <div class="page-head">
                    <h2><i class="fa-solid fa-tags"></i> Catalog</h2>
                    <div class="page-head-sub">Categories, sub-categories &amp; singers — everything managed here, inline.</div>
                </div>
                <div class="school-card">
                    <div class="toolbar">
                        <button class="btn-secondary btn-sm mz-cmgr-tab active" id="mzMgrCatTabBtn" onclick="Mezmur.mgrTab('categories')" aria-pressed="true"><i class="fa-solid fa-sitemap"></i> Categories</button>
                        <button class="btn-secondary btn-sm mz-cmgr-tab" id="mzMgrZemTabBtn" onclick="Mezmur.mgrTab('zemarians')" aria-pressed="false"><i class="fa-solid fa-user-group"></i> Singers</button>
                    </div>

                    <!-- categories manager -->
                    <div id="mzMgrCats">
                        <div class="toolbar">
                            <div class="toolbar-grow"><input id="mzMgrMainName" class="school-input" maxlength="50" placeholder="New main category name…" autocomplete="off"></div>
                            <button id="mzMgrMainAdd" class="btn-primary btn-sm" onclick="Mezmur.mgrAddMain()"><i class="fa-solid fa-plus"></i> Add main category</button>
                        </div>
                        <div class="table-shell"><table class="school-table">
                            <thead><tr><th class="th-cover">Cover</th><th>Name</th><th class="th-hymns">Hymns</th><th class="th-order">Order</th><th class="th-actions">Actions</th></tr></thead>
                            <tbody id="mzMgrCatRows"></tbody>
                        </table></div>
                        <input type="file" id="mzMgrFile" accept="image/jpeg,image/png,image/webp" class="is-hidden" aria-hidden="true">
                    </div>

                    <!-- singers manager -->
                    <div id="mzMgrZems" class="is-hidden">
                        <div class="toolbar">
                            <div class="toolbar-grow"><input id="mzMgrZemName" class="school-input amharic" maxlength="100" placeholder="የዘማሪያን ስም (በአማርኛ)…" autocomplete="off" aria-label="New singer name in Amharic"></div>
                            <button id="mzMgrZemAdd" class="btn-primary btn-sm" onclick="Mezmur.mgrAddZem()"><i class="fa-solid fa-plus"></i> Add singer</button>
                        </div>
                        <div class="table-shell"><table class="school-table">
                            <thead><tr><th class="th-cover">Cover</th><th class="amharic">ስም</th><th class="th-hymns">Hymns</th><th class="th-actions">Actions</th></tr></thead>
                            <tbody id="mzMgrZemRows"></tbody>
                        </table></div>
                    </div>
                </div>
            </section>

            <section id="section-attendance" class="school-section" data-section="attendance">

                <!-- Day list view -->
                <div id="mzSessionListView">
                    <!-- ═══ SUBMISSIONS — mirrors the Education department layout exactly ═══ -->
                    <div class="page-head">
                        <div>
                            <h2><i class="fa-solid fa-inbox"></i> Mezmur Submissions</h2>
                            <div class="page-head-sub">Drafts are still being worked on. Submitted means the taker finished.</div>
                        </div>
                        <div class="page-head-actions">
                            <label class="school-label visually-hidden" for="mzSubSection">Section</label>
                            <select id="mzSubSection" class="school-input" aria-label="Filter by section" onchange="Mezmur.loadSubmissions()">
                                <option value="">All sections</option>
                            </select>
                            <label class="school-label visually-hidden" for="mzSubFrom">From date</label>
                            <input id="mzSubFrom" class="school-input" type="date" aria-label="From date" onchange="Mezmur.loadSubmissions()">
                            <label class="school-label visually-hidden" for="mzSubTo">To date</label>
                            <input id="mzSubTo" class="school-input" type="date" aria-label="To date" onchange="Mezmur.loadSubmissions()">
                            <button class="btn-secondary" type="button" onclick="Mezmur.exportSubmissions()"><i class="fa-solid fa-download"></i> Excel</button>
                            <button class="btn-secondary" type="button" onclick="Mezmur.loadSubmissions()"><i class="fa-solid fa-sync"></i> Refresh</button>
                            <button class="btn-secondary" type="button" onclick="Mezmur.printQrRoster()" title="Printable QR tiles for this section — scanned by takers in the mobile app"><i class="fa-solid fa-qrcode"></i> QR Roster</button>
                        </div>
                    </div>

                    <!-- Drafts | Submitted | Insights tabs -->
                    <div class="sub-tabs" role="tablist" aria-label="Submission tabs">
                        <button class="sub-tab active" id="mzSubTabDraft" type="button" role="tab" aria-selected="true" onclick="Mezmur.switchSubTab('draft')"><i class="fa-solid fa-pen-to-square"></i> Drafts</button>
                        <button class="sub-tab" id="mzSubTabSubmitted" type="button" role="tab" aria-selected="false" onclick="Mezmur.switchSubTab('submitted')"><i class="fa-solid fa-paper-plane"></i> Submitted</button>
                        <button class="sub-tab" id="mzSubTabApproved" type="button" role="tab" aria-selected="false" onclick="Mezmur.switchSubTab('approved')" title="Approved packets — can be reopened or rejected with a reason"><i class="fa-solid fa-circle-check"></i> Approved</button>
                        <button class="sub-tab" id="mzSubTabInsights" type="button" role="tab" aria-selected="false" onclick="Mezmur.switchSubTab('insights')"><i class="fa-solid fa-chart-line"></i> Insights</button>
                    </div>
                    <input autocomplete="off" type="hidden" id="mzSubTabStatus" value="draft">

                    <!-- Insight strip -->
                    <div id="mzSubStatsRow" class="sub-stats-row" aria-live="polite"></div>

                    <!-- Packet table -->
                    <div id="mzSubmissionsList" class="table-shell">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Section</th>
                                    <th>Taker</th>
                                    <th>Members</th>
                                    <th>Result</th>
                                    <th>Status</th>
                                    <th>Updated</th>
                                    <th class="nowrap">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="mzSubTbody">
                                <tr><td colspan="8"><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div id="mzSubPagination" class="pager" role="navigation" aria-label="Submissions pagination"></div>

                    <!-- Insights pane -->
                    <div id="mzSubInsights" class="is-hidden" aria-live="polite"></div>
                </div>

                <!-- Sheet view -->
                <div id="mzSheetView" class="school-card">
                    <div class="print-only">
                        <h2 id="mzPrintTitle">Mezmur Attendance</h2>
                        <div id="mzPrintMeta" class="text-dim"></div>
                    </div>
                    <div class="page-head no-print">
                        <div>
                            <h2 id="mzSheetTitle">Attendance</h2>
                            <div class="page-head-sub" id="mzSheetMeta"></div>
                        </div>
                        <div class="page-head-actions">
                            <select id="mzViewSection" class="school-input mz-view-select" aria-label="Section">
                                <option value="">Select section…</option>
                            </select>
                            <button class="btn-secondary" onclick="Mezmur.viewSheet()"><i class="fa-solid fa-eye"></i> View</button>
                            <button class="btn-secondary" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
                            <button class="btn-secondary" onclick="Mezmur.closeSheet()"><i class="fa-solid fa-arrow-left"></i> Back</button>
                        </div>
                    </div>

                    <!-- Packet status banner (draft / submitted / review note) -->
                    <div id="mzSheetStatus" class="is-hidden" role="status" aria-live="polite"></div>

                    <div id="mzSheetBody" aria-live="polite">
                        <div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div>
                        <div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div>
                        <div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div>
                    </div>

                    <div class="sheet-summarybar">
                        <div id="mzSheetSummary" aria-live="polite"></div>
                        <div class="text-dim"><i class="fa-solid fa-lock"></i> Read-only — sheets are recorded and submitted from the mobile app.</div>
                    </div>
                </div>
            </section>

            <!-- ═══ ANALYTICS SECTION ═══ -->
            <section id="section-analytics" class="school-section" data-section="analytics">

                <div class="page-head">
                    <div>
                        <h2><i class="fa-solid fa-chart-column"></i> Attendance Analytics</h2>
                        <div class="page-head-sub">Every member with number <b>and</b> percentage — program eligibility at a glance.</div>
                    </div>
                    <div class="page-head-actions">
                        <button class="btn-secondary" onclick="Mezmur.exportCsv()"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
                    </div>
                </div>

                <!-- Filters -->
                <div class="school-card">
                    <div class="toolbar">
                        <select id="mzAnSection" class="school-input" aria-label="Filter by section">
                            <option value="">All sections</option>
                        </select>
                        <input id="mzAnFrom" class="school-input" type="date" aria-label="From date">
                        <input id="mzAnTo" class="school-input" type="date" aria-label="To date">
                        <input id="mzAnSearch" class="school-input" type="search" placeholder="Search member…" aria-label="Search member">
                        <input id="mzAnMinRate" class="school-input" type="number" min="0" max="100" placeholder="Min rate %" aria-label="Minimum attendance rate percent">
                        <input id="mzAnMinAtt" class="school-input" type="number" min="0" placeholder="Min attended" aria-label="Minimum days attended">
                        <button class="btn-primary" onclick="Mezmur.runAnalytics()"><i class="fa-solid fa-magnifying-glass-chart"></i> Analyze</button>
                    </div>
                </div>

                <!-- Headline stats -->
                <div class="stat-grid">
                    <div class="school-stat-card">
                        <div class="school-stat-icon si-accent2"><i class="fa-solid fa-calendar-day"></i></div>
                        <div class="school-stat-value" id="mzAnHeld">—</div>
                        <div class="school-stat-label">Days Held</div>
                    </div>
                    <div class="school-stat-card">
                        <div class="school-stat-icon si-info"><i class="fa-solid fa-users"></i></div>
                        <div class="school-stat-value" id="mzAnMembers">—</div>
                        <div class="school-stat-label">Members Ranked</div>
                    </div>
                    <div class="school-stat-card">
                        <div class="school-stat-icon si-warn"><i class="fa-solid fa-percent"></i></div>
                        <div class="school-stat-value" id="mzAnAvgRate">—</div>
                        <div class="school-stat-label">Average Rate</div>
                    </div>
                </div>

                <!-- Section rollups -->
                <div class="school-card">
                    <h3 class="school-card-title"><i class="fa-solid fa-layer-group"></i> By Section</h3>
                    <div id="mzSectionCards" class="stat-grid"></div>
                </div>

                <!-- Monthly trend -->
                <div class="school-card">
                    <h3 class="school-card-title"><i class="fa-solid fa-chart-line"></i> Monthly Trend</h3>
                    <div id="mzTrendBody" class="mt-1"></div>
                </div>

                <!-- Member ranking table -->
                <div class="school-card">
                    <h3 class="school-card-title"><i class="fa-solid fa-ranking-star"></i> Member Ranking
                        <span class="text-dim">— every number with its percentage</span>
                    </h3>
                    <div class="table-shell mt-1">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th class="th-sortable" onclick="Mezmur.sortBy('name')" aria-sort="none">Member</th>
                                    <th class="th-sortable" onclick="Mezmur.sortBy('section')" aria-sort="none">Section</th>
                                    <th class="th-sortable" onclick="Mezmur.sortBy('attended')" aria-sort="none">Attended</th>
                                    <th class="th-sortable" onclick="Mezmur.sortBy('rate')" aria-sort="none">Rate %</th>
                                    <th class="th-sortable" onclick="Mezmur.sortBy('absent')" aria-sort="none">Absent</th>
                                    <th class="th-sortable" onclick="Mezmur.sortBy('last_attended')" aria-sort="none">Last Attended</th>
                                </tr>
                            </thead>
                            <tbody id="mzAnTbody">
                                <tr><td colspan="7"><div class="empty-state"><i class="fa-solid fa-sliders"></i><div class="state-title">No analysis yet</div><p class="state-text">Set your filters above and press <b>Analyze</b> to rank every member by attendance.</p></div></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div id="mzAnPagination" class="pager" role="navigation" aria-label="Analytics pagination"></div>
                </div>
            </section>

            <!-- ═══ ATTENDANCE TAKERS SECTION ═══ -->
            <section id="section-takers" class="school-section" data-section="takers">
                <div class="page-head">
                    <div>
                        <h2><i class="fa-solid fa-user-shield"></i> Attendance Takers</h2>
                        <div class="page-head-sub">Accounts allowed to record Mezmur attendance from web or mobile.</div>
                    </div>
                    <div class="page-head-actions">
                        <button class="btn-primary" onclick="Mezmur.openTakerModal()"><i class="fa-solid fa-user-plus"></i> Add Taker</button>
                    </div>
                </div>
                <div class="school-card">
                    <div class="table-shell">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Username</th>
                                    <th>Type</th>
                                    <th>Created</th>
                                    <th>Status</th>
                                    <th class="nowrap">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="mzTakerTbody">
                                <tr><td colspan="6"><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

        </div>
    </div>
</div>

<!-- ═══ MODAL: ADD / EDIT HYMN ═══ -->
<div class="school-modal" id="mzHymnModal" role="dialog" aria-modal="true" aria-labelledby="mzModalTitle">
    <div class="school-modal-content">
        <div class="page-head">
            <h3 id="mzModalTitle"><i class="fa-solid fa-music"></i> Add Hymn</h3>
            <button class="btn-secondary btn-sm" onclick="Mezmur.closeModal()" aria-label="Close dialog"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <input type="hidden" id="mzHymnId" value="0">
        <div class="school-form-group">
            <label class="school-label" for="mzTitle">Title (ርዕስ) *</label>
            <input id="mzTitle" class="school-input" maxlength="255" autocomplete="off">
        </div>
        <div class="school-form-group">
            <div class="mz-cascade">
                <div>
                    <label class="school-label" for="mzHymnMainCat">Category *</label>
                    <select id="mzHymnMainCat" class="school-input" aria-label="Category"></select>
                </div>
                <div>
                    <label class="school-label" for="mzHymnSubCat">Sub-category *</label>
                    <select id="mzHymnSubCat" class="school-input" aria-label="Sub-category" disabled><option value="">Select a category first…</option></select>
                </div>
            </div>
            <button type="button" class="btn-secondary btn-sm catalog-manage" onclick="Mezmur.openCatalog('categories')"><i class="fa-solid fa-tags"></i> Manage categories</button>
        </div>
        <div class="school-form-group mz-catpick">
            <label class="school-label" id="mzZemLbl">Singers / Zemarians (one or more)</label>
            <button type="button" id="mzZemPickBtn" class="school-input mz-pick-btn" aria-haspopup="listbox" aria-labelledby="mzZemLbl">Select singers…</button>
            <div id="mzZemariansBox" class="mz-pick-panel is-hidden" role="listbox"></div>
            <button type="button" class="btn-secondary btn-sm catalog-manage" onclick="Mezmur.openCatalog('zemarians')"><i class="fa-solid fa-user-plus"></i> Manage singers</button>
        </div>
        <div class="school-form-group">
            <label class="school-label" for="mzLength">Length</label>
            <select id="mzLength" class="school-input">
                <option value="long" selected>Long</option>
                <option value="short">Short</option>
            </select>
        </div>
        <div class="school-form-group">
            <label class="school-label" for="mzLanguage">Language</label>
            <select id="mzLanguage" class="school-input">
                <option value="amharic" selected>Amharic (አማርኛ)</option>
                <option value="geez">Geez (ግዕዝ)</option>
            </select>
        </div>
        <div class="school-form-group">
            <label class="school-label" for="mzEditor">Lyrics</label>
            <div class="mz-ed-toolbar" role="toolbar" aria-label="Lyrics styling">
                <button type="button" class="mz-ed-btn" data-cmd="bold" title="Bold (Ctrl+B)" aria-label="Bold"><i class="fa-solid fa-bold"></i></button>
                <button type="button" class="mz-ed-btn" data-cmd="italic" title="Italic (Ctrl+I)" aria-label="Italic"><i class="fa-solid fa-italic"></i></button>
                <button type="button" class="mz-ed-btn" data-cmd="underline" title="Underline (Ctrl+U)" aria-label="Underline"><i class="fa-solid fa-underline"></i></button>
                <span class="mz-ed-divider"></span>
                <button type="button" class="mz-ed-btn" data-cmd="section" title="Insert section header (e.g. Verse 1)" aria-label="Insert section header"><i class="fa-solid fa-heading"></i> Section</button>
                <span class="mz-sec-pop is-hidden" id="mzSecPop">
                    <input id="mzSecPopInput" maxlength="60" placeholder="Section name (e.g. Verse 1)">
                    <button type="button" class="btn-primary btn-sm" id="mzSecPopOk">Insert</button>
                    <button type="button" class="btn-secondary btn-sm" id="mzSecPopCancel">Cancel</button>
                </span>
            </div>
            <div id="mzEditor" class="mz-editor amharic" contenteditable="true" spellcheck="false" data-placeholder="የመዝሙሩ ግጥም…"></div>
            <textarea id="mzLyrics" class="is-hidden" aria-hidden="true"></textarea>
            <p class="text-dim mz-hint">Style as you write with the toolbar — what you see is how it prints.</p>
        </div>
        <div class="school-error-msg is-hidden" id="mzModalError" role="alert"></div>
        <button class="btn-primary btn-block" id="mzSaveBtn" onclick="Mezmur.save()"><i class="fa-solid fa-save"></i> Save Hymn</button>
    </div>
</div>

<!-- ═══ DIALOG: COVER IMAGE PREVIEW (upload with a real preview) ═══ -->
<div class="school-modal" id="mzImageDialog" role="dialog" aria-modal="true" aria-labelledby="mzImageTitle">
    <div class="school-modal-content mw-380">
        <div class="page-head">
            <h3 id="mzImageTitle"><i class="fa-solid fa-image"></i> Cover image</h3>
            <button class="btn-secondary btn-sm" onclick="Mezmur.closeImageDialog()" aria-label="Close dialog"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="mz-img-preview"><img id="mzImgPreviewImg" src="" alt="Selected cover preview"></div>
        <div class="text-dim mz-hint2" id="mzImgMeta"></div>
        <p class="text-dim mz-note">
            The cover is dimmed slightly on tiles and headers, so titles always stay readable over any photo.
        </p>
        <div class="toolbar toolbar-end">
            <button class="btn-secondary btn-sm" id="mzImgCancel">Cancel</button>
            <button class="btn-primary btn-sm" id="mzImgUpload"><i class="fa-solid fa-upload"></i> Upload</button>
        </div>
    </div>
</div>

<!-- ═══ DIALOG: COVER COLOR (gradient picker) ═══ -->
<div class="school-modal" id="mzColorDialog" role="dialog" aria-modal="true" aria-labelledby="mzColorTitle">
    <div class="school-modal-content mw-380">
        <div class="page-head">
            <h3 id="mzColorTitle"><i class="fa-solid fa-palette"></i> Cover color</h3>
            <button class="btn-secondary btn-sm" onclick="Mezmur.closeColorDialog()" aria-label="Close dialog"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="mz-color-preview" id="mzColorPreview"><span id="mzColorPreviewName"></span></div>
        <div class="text-dim mz-hint3" id="mzColorNote"></div>
        <div class="school-label">Presets</div>
        <div class="mz-swatches" id="mzSwatches" role="radiogroup" aria-label="Gradient presets"></div>
        <div class="mz-color-custom">
            <label>Start <input type="color" id="mzGradStart" value="#4f46e5" aria-label="Gradient start color"></label>
            <label>End <input type="color" id="mzGradEnd" value="#7c3aed" aria-label="Gradient end color"></label>
            <button type="button" class="btn-secondary btn-sm" id="mzGradAuto"><i class="fa-solid fa-wand-magic-sparkles"></i> Auto (by name)</button>
        </div>
        <div class="mz-opacity-row">
            <label>Start opacity <input type="range" id="mzGradStartOp" min="20" max="100" step="1" value="100" aria-label="Start color opacity"><span id="mzGradStartOpV">100%</span></label>
            <label>End opacity <input type="range" id="mzGradEndOp" min="20" max="100" step="1" value="100" aria-label="End color opacity"><span id="mzGradEndOpV">100%</span></label>
        </div>
        <div class="toolbar toolbar-between">
            <button type="button" class="btn-secondary btn-sm" id="mzRemoveImg"><i class="fa-solid fa-trash-can"></i> Remove image</button>
            <span class="toolbar gap-sm">
                <button type="button" class="btn-secondary btn-sm" onclick="Mezmur.closeColorDialog()">Cancel</button>
                <button type="button" class="btn-primary btn-sm" id="mzGradSave"><i class="fa-solid fa-check"></i> Save</button>
            </span>
        </div>
    </div>
</div>

<!-- ═══ SYSTEM DIALOG: in-app confirm (never browser popups) ═══ -->
<div class="school-modal" id="mzSysDialog" role="dialog" aria-modal="true" aria-labelledby="mzSysDialogTitle">
    <div class="school-modal-content mw-420">
        <div class="page-head"><h3 id="mzSysDialogTitle"><i class="fa-solid fa-circle-question"></i> Confirm</h3></div>
        <p class="mz-body" id="mzSysDialogBody"></p>
        <div class="toolbar toolbar-end">
            <button class="btn-secondary btn-sm" id="mzSysDialogNo">Cancel</button>
            <button class="btn-primary btn-sm" id="mzSysDialogYes">Confirm</button>
        </div>
    </div>
</div>

<!-- ═══ MODAL: VIEW HYMN ═══ -->
<div class="school-modal" id="mzViewModal" role="dialog" aria-modal="true" aria-labelledby="mzViewTitle">
    <div class="school-modal-content">
        <div class="page-head">
            <h3 id="mzViewTitle"><i class="fa-solid fa-book-open"></i> Hymn</h3>
            <button class="btn-secondary btn-sm" onclick="Mezmur.closeView()" aria-label="Close dialog"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <!-- P66: hymn art hero — mezmur.js fills the background (art
             rendition or fallback gradient), title overlay and the
             Set/Replace/Remove art buttons. -->
        <div id="mzViewArt" class="mz-view-art" aria-hidden="true"></div>
        <div id="mzViewMeta" class="toolbar"></div>
        <pre class="amharic lyrics-view" id="mzViewLyrics"></pre>
    </div>
</div>

<!-- ═══ MODAL: LYRIC TIMING EDITOR (P44) ═══════════════════
     Authoring UI for synced ("karaoke") lyrics.

     The backend, both players and the LRC validator were already
     complete — but nothing could WRITE a timestamp, so the feature was
     dormant. This is the tap-to-sync editor that fills that gap.

     Method (the standard used by every LRC tool): play the audio, press
     Space at the moment each line begins, and the current playback time
     is stamped onto that line. Typing timestamps by hand is not viable —
     a 70-line hymn would take an hour and still be wrong. -->
<div class="school-modal" id="mzSyncModal" role="dialog" aria-modal="true" aria-labelledby="mzSyncTitle">
    <div class="school-modal-content mw-760">
        <div class="page-head">
            <div>
                <h3 id="mzSyncTitle"><i class="fa-solid fa-wand-magic-sparkles"></i> Sync lyrics to audio</h3>
                <div class="page-head-sub" id="mzSyncHymnName"></div>
            </div>
            <button class="btn-secondary btn-sm" onclick="Mezmur.syncClose()" aria-label="Close dialog"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <input type="hidden" id="mzSyncHymnId" value="0">
        <audio id="mzSyncAudio" preload="auto" playsinline class="is-hidden"></audio>

        <p class="text-dim mz-sync-help">
            Press <kbd>Space</kbd> to stamp the highlighted line and move to the next.
            <kbd>←</kbd> / <kbd>→</kbd> nudge the audio by 2s, <kbd>Backspace</kbd> steps back a line.
        </p>

        <div class="mz-sync-transport">
            <button type="button" class="btn-primary btn-sm" id="mzSyncPlay" onclick="Mezmur.syncPlayPause()">
                <i class="fa-solid fa-play"></i> <span id="mzSyncPlayLabel">Play</span>
            </button>
            <span class="mz-sync-clock" id="mzSyncClock">0:00.0</span>
            <input type="range" id="mzSyncSeek" min="0" max="1000" value="0" aria-label="Seek audio">
            <button type="button" class="btn-secondary btn-sm" onclick="Mezmur.syncStamp()">
                <i class="fa-solid fa-stopwatch"></i> Stamp line
            </button>
        </div>

        <div class="school-error-msg is-hidden" id="mzSyncError" role="alert"></div>
        <!-- P46: always say where the user's work currently lives. -->
        <p class="mz-sync-state is-ok" id="mzSyncSaveState" role="status" aria-live="polite">All timings saved to the server.</p>
        <div id="mzSyncStatus" class="sr-only" role="status" aria-live="polite"></div>

        <!-- One row per lyric line; the pending line is highlighted. -->
        <div class="mz-sync-lines" id="mzSyncLines" tabindex="0" aria-label="Lyric lines to time"></div>

        <div class="toolbar toolbar-between mz-sync-actions">
            <button type="button" class="btn-secondary btn-sm" onclick="Mezmur.syncReset()">
                <i class="fa-solid fa-rotate-left"></i> Clear all timings
            </button>
            <div>
                <button type="button" class="btn-secondary btn-sm" onclick="Mezmur.syncClose()">Cancel</button>
                <button type="button" class="btn-primary btn-sm" id="mzSyncSave" onclick="Mezmur.syncSave()">
                    <i class="fa-solid fa-floppy-disk"></i> Save timings
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══ MODAL: AUDIO (attach / play / remove hymn audio) ═══
     P0 audio upgrade: the file is uploaded DIRECTLY to Cloudflare R2
     (browser PUT to a short-lived presigned URL), never through PHP.
     The hymn row only stores a key; the public URL is rebuilt from the
     MEZMUR_MEDIA_PUBLIC_BASE config at read time. -->
<div class="school-modal" id="mzAudioModal" role="dialog" aria-modal="true" aria-labelledby="mzAudioTitle">
    <div class="school-modal-content mw-520">
        <div class="page-head">
            <h3 id="mzAudioTitle"><i class="fa-solid fa-headphones"></i> Hymn Audio</h3>
            <button class="btn-secondary btn-sm" onclick="Mezmur.closeAudio()" aria-label="Close dialog"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <input type="hidden" id="mzAudioHymnId" value="0">

        <div class="mz-audio-hymn amharic" id="mzAudioHymnName"></div>
        <div class="text-dim mz-audio-sub" id="mzAudioMeta"></div>

        <!-- Hidden fallback element (contract tests + duration probe).
             Listening happens in the Mezmur player dock, not here. -->
        <div class="mz-audio-player-wrap is-hidden" id="mzAudioPlayerWrap">
            <audio id="mzAudioPlayer" preload="none" playsinline></audio>
        </div>
        <button type="button" class="btn-primary btn-sm is-hidden" id="mzAudioListenBtn">
            <i class="fa-solid fa-play"></i> Play in player
        </button>

        <!-- Status / guidance line -->
        <p class="text-dim mz-audio-note" id="mzAudioState"></p>

        <!-- Upload controls -->
        <input type="file" id="mzAudioFile" accept="audio/*,.mp3,.m4a,.ogg,.wav,.aac,.opus" class="is-hidden" aria-hidden="true">
        <div class="toolbar toolbar-between mz-audio-actions">
            <button class="btn-primary btn-sm" id="mzAudioPickBtn" onclick="Mezmur.audioPick()">
                <i class="fa-solid fa-upload"></i> <span id="mzAudioPickLabel">Choose audio file…</span>
            </button>
            <button class="btn-secondary btn-sm is-hidden" id="mzAudioSyncBtn" onclick="Mezmur.syncOpen()">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Sync lyrics
            </button>
            <button class="btn-secondary btn-sm is-hidden" id="mzAudioRemoveBtn" onclick="Mezmur.audioRemove()">
                <i class="fa-solid fa-trash-can"></i> Remove
            </button>
        </div>

        <!-- Upload progress (direct PUT to R2) -->
        <div class="is-hidden" id="mzAudioProgressWrap">
            <div class="mz-progress-track"><div class="mz-progress-bar" id="mzAudioProgressBar"></div></div>
            <div class="text-dim" id="mzAudioProgressLabel">Uploading… 0%</div>
        </div>

        <div class="school-error-msg is-hidden" id="mzAudioErr" role="alert"></div>
        <p class="text-dim mz-audio-fineprint">
            <i class="fa-solid fa-circle-info"></i>
            Audio streams from the media CDN — it is never stored on this server, so shared-hosting limits do not apply.
            Allowed: mp3, m4a, ogg, wav, aac, opus (max 15 MB).
        </p>
    </div>
</div>

<!-- ═══ MODAL: ADD TAKER ═══ -->
<div class="school-modal" id="mzTakerModal" role="dialog" aria-modal="true" aria-labelledby="mzTakerModalTitle">
    <div class="school-modal-content">
        <div class="page-head">
            <h3 id="mzTakerModalTitle"><i class="fa-solid fa-user-plus"></i> Add Attendance Taker</h3>
            <button class="btn-secondary btn-sm" onclick="modal('mzTakerModal',false)" aria-label="Close dialog"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="school-form-group">
            <label class="school-label" for="mzTkName">Full Name *</label>
            <input id="mzTkName" class="school-input" maxlength="150" autocomplete="off">
        </div>
        <div class="school-form-group">
            <label class="school-label" for="mzTkUser">Username *</label>
            <input id="mzTkUser" class="school-input" maxlength="50" autocomplete="off">
        </div>
        <div class="school-form-group">
            <label class="school-label" for="mzTkPass">Password * <span class="text-dim">(6+ characters)</span></label>
            <input id="mzTkPass" class="school-input" type="password" minlength="6" maxlength="72" autocomplete="new-password">
        </div>
        <div class="school-error-msg is-hidden" id="mzTkError" role="alert"></div>
        <button class="btn-primary btn-block" id="mzTkSaveBtn" onclick="Mezmur.createTaker()"><i class="fa-solid fa-save"></i> Create Account</button>
    </div>
</div>

<!-- ═══ MODAL: REVIEW SUBMISSION (approve / return-with-note) ═══ -->
<div class="school-modal" id="mzReviewModal" role="dialog" aria-modal="true" aria-labelledby="mzReviewTitle">
    <div class="school-modal-content">
        <div class="page-head">
            <h3 id="mzReviewTitle"><i class="fa-solid fa-clipboard-check"></i> Review Attendance</h3>
            <button class="btn-secondary btn-sm" onclick="modal('mzReviewModal',false)" aria-label="Close dialog"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <input type="hidden" id="mzRvId" value="0">
        <div id="mzRvMeta" class="toolbar"></div>
        <div class="school-form-group">
            <label class="school-label" for="mzRvDecision">Decision *</label>
            <select id="mzRvDecision" class="school-input">
                <option value="approved">Approve — packet is final</option>
                <option value="revision_needed">Return for correction — taker can edit again</option>
                <option value="rejected">Reject — dismiss this packet</option>
            </select>
        </div>
        <div class="school-form-group">
            <label class="school-label" for="mzRvNotes">Reason <span class="text-dim">(required for returns/rejections — the taker sees it)</span></label>
            <textarea id="mzRvNotes" class="school-input" rows="4" maxlength="500" placeholder="What should the taker fix or confirm?"></textarea>
        </div>
        <div class="school-error-msg is-hidden" id="mzRvError" role="alert"></div>
        <button class="btn-primary btn-block" id="mzRvSaveBtn" onclick="Mezmur.submitReview()"><i class="fa-solid fa-gavel"></i> Record Decision</button>
    </div>
</div>

<!-- ═══ MODAL: PACKET DETAIL (rows) ═══ -->
<div class="school-modal" id="mzPacketModal" role="dialog" aria-modal="true" aria-labelledby="mzPacketTitle">
    <div class="school-modal-content">
        <div class="page-head">
            <h3 id="mzPacketTitle"><i class="fa-solid fa-list-check"></i> Attendance Packet</h3>
            <button class="btn-secondary btn-sm" onclick="modal('mzPacketModal',false)" aria-label="Close dialog"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div id="mzPacketMeta" class="toolbar"></div>
        <div id="mzPacketBody"></div>
    </div>
</div>

<!-- ═══ NOW PLAYING DOCK (custom chrome over a hidden HTML5 engine) ═══ -->
<div id="mzPlayer" class="mz-player is-hidden" hidden>
    <audio id="mzEngine" preload="metadata" playsinline></audio>
    <div class="mz-player-left">
        <button type="button" id="mzPArtBtn" class="mz-player-art" aria-label="Open now playing">
            <span id="mzPArtLetter" class="mz-player-art-letter">♪</span>
        </button>
        <div class="mz-player-meta">
            <div id="mzPTitle" class="mz-player-title amharic">—</div>
            <div id="mzPSub" class="mz-player-sub">—</div>
        </div>
    </div>
    <div class="mz-player-center">
        <div class="mz-player-controls">
            <button type="button" id="mzPShuffle" title="Shuffle" aria-pressed="false" aria-label="Shuffle"><i class="fa-solid fa-shuffle"></i></button>
            <button type="button" id="mzPPrev" title="Previous" aria-label="Previous hymn"><i class="fa-solid fa-backward-step"></i></button>
            <button type="button" id="mzPBack" title="Back 15 seconds" aria-label="Back 15 seconds"><i class="fa-solid fa-rotate-left"></i></button>
            <button type="button" id="mzPPlay" class="mz-player-play" title="Play" aria-label="Play"><i class="fa-solid fa-play"></i></button>
            <button type="button" id="mzPFwd" title="Forward 15 seconds" aria-label="Forward 15 seconds"><i class="fa-solid fa-rotate-right"></i></button>
            <button type="button" id="mzPNext" title="Next" aria-label="Next hymn"><i class="fa-solid fa-forward-step"></i></button>
            <button type="button" id="mzPRepeat" title="Repeat off" aria-pressed="false" aria-label="Repeat"><i class="fa-solid fa-repeat"></i></button>
        </div>
        <div class="mz-player-seekrow">
            <span id="mzPCur">0:00</span>
            <input type="range" id="mzPSeek" min="0" max="1000" value="0" aria-label="Seek">
            <span id="mzPDur">0:00</span>
        </div>
    </div>
    <div class="mz-player-right">
        <button type="button" id="mzPLyricsBtn" title="Lyrics" aria-pressed="false" aria-label="Lyrics"><i class="fa-solid fa-align-left"></i></button>
        <button type="button" id="mzPQueueBtn" title="Queue" aria-pressed="false" aria-label="Queue"><i class="fa-solid fa-list-ol"></i></button>
        <button type="button" id="mzPRate" class="mz-p-rate" title="Playback speed" aria-label="Playback speed">1×</button>
        <button type="button" id="mzPMute" title="Mute" aria-pressed="false" aria-label="Mute"><i class="fa-solid fa-volume-high"></i></button>
        <input type="range" id="mzPVol" min="0" max="100" value="100" aria-label="Volume">
        <!-- P42: the dock had NO way to dismiss it. Once opened it
             covered the bottom of every page for the rest of the
             session, with no affordance to close. Mirrors the mobile
             app, where X stops audio and hides the bar. -->
        <button type="button" id="mzPClose" title="Close player" aria-label="Stop and close player"><i class="fa-solid fa-xmark"></i></button>
    </div>
</div>

<aside id="mzNowPlaying" class="mz-np is-hidden" hidden aria-hidden="true" aria-labelledby="mzNpHeading">
    <!-- P67: full content-region stage. Wrappers (.mz-np-stage/-body/
         -now/-meta/-view) are CSS layout hooks only — every id and
         JS-generated class inside is unchanged, so mezmur_player.js
         binds exactly as before. -->
    <div class="mz-np-stage">
        <div class="mz-np-head">
            <h2 id="mzNpHeading">Now playing</h2>
            <button type="button" id="mzNpClose" class="mz-np-close" aria-label="Close now playing"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="mz-np-body">
            <div class="mz-np-now">
                <div class="mz-np-artwrap"><div id="mzNpArt" class="mz-np-art"><span id="mzNpArtLetter" class="mz-np-art-letter">♪</span></div></div>
                <div class="mz-np-meta">
                    <div id="mzNpTitle" class="mz-np-title amharic"></div>
                    <div id="mzNpSub" class="mz-np-sub"></div>
                </div>
                <div class="mz-np-tabs" role="tablist" aria-label="Now playing views">
                    <button type="button" id="mzNpTabLyrics" class="mz-np-tab active" role="tab" aria-selected="true">Lyrics</button>
                    <button type="button" id="mzNpTabQueue" class="mz-np-tab" role="tab" aria-selected="false">Queue</button>
                </div>
            </div>
            <div class="mz-np-view">
                <div id="mzNpLyrics" class="mz-np-lyrics amharic" role="tabpanel" aria-labelledby="mzNpTabLyrics"></div>
                <div id="mzNpQueue" class="mz-np-queue is-hidden" role="tabpanel" aria-labelledby="mzNpTabQueue"></div>
            </div>
        </div>
    </div>
</aside>

<!-- ═══ MOBILE BOTTOM NAV ═══ -->
<nav class="school-bottom-nav" aria-label="Mezmur sections">
    <div class="school-bottom-nav-inner">
        <button class="school-bottom-nav-btn active" data-section="overview" aria-label="Overview"><i class="fa-solid fa-gauge-high"></i><span>Home</span></button>
        <button class="school-bottom-nav-btn" data-section="library" aria-label="Hymn Library"><i class="fa-solid fa-book-open"></i><span>Library</span></button>
        <button class="school-bottom-nav-btn" data-section="catalog" aria-label="Catalog"><i class="fa-solid fa-tags"></i><span>Catalog</span></button>
        <button class="school-bottom-nav-btn" data-section="attendance" aria-label="Attendance"><i class="fa-solid fa-calendar-check"></i><span>Attend</span></button>
        <button class="school-bottom-nav-btn" data-section="analytics" aria-label="Analytics"><i class="fa-solid fa-chart-column"></i><span>Analyze</span></button>
        <button class="school-bottom-nav-btn" data-section="takers" aria-label="Attendance takers"><i class="fa-solid fa-user-shield"></i><span>Takers</span></button>
        <a class="school-bottom-account-link" href="<?= e(ssms_app_url('admin/account.php')) ?>" aria-label="My profile"><i class="fa-solid fa-user"></i><span>Profile</span></a>
    </div>
</nav>

<?php
$bodyContent = ob_get_clean();
ob_start();
require __DIR__ . '/../../admin/components/comm/comm_section.php';
$bodyContent .= ob_get_clean();
require __DIR__ . '/../layouts/base.php';
?>
