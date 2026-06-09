file_path = "public_html/public_html/index.html"

with open(file_path, "r", encoding="utf-8", errors="ignore") as f:
    content = f.read()

# ─── SECTION 1: INSERT BENTO SPONSORS BEFORE ROADMAP ───
# Let's find:
# `  <!-- ═══════════════════════════════════════════════════\n     DEVELOPMENT ROADMAP`
roadmap_comment = """  <!-- ═══════════════════════════════════════════════════
     DEVELOPMENT ROADMAP"""

# Insert Bento box fueling sponsors section immediately preceding the DEVELOPMENT ROADMAP comment track
bento_section = """  <!-- ═══════════════════════════════════════════════════
     FUELED BY VISIONARY SPONSORS [BENTO BOX STYLE]
 ════════════════════════════════════════════════════ -->
  <section id="fueling-sponsors" class="py-24 px-4 bg-white border-t border-slate-100 relative overflow-hidden">
    <div class="max-w-6xl mx-auto">
      <div class="text-center mb-16 reveal">
        <span class="text-xs font-bold tracking-widest uppercase text-blue-600 mb-4 block">Our Pillars</span>
        <h2 class="text-3xl sm:text-4xl font-extrabold text-slate-900 leading-tight">Sponsors Fueling OwnPay</h2>
        <p class="text-slate-500 text-sm mt-3 max-w-md mx-auto">Click any sponsor logo to explore how their premium backing advances our open payment ecosystem.</p>
      </div>

      <!-- Bento Grid Container -->
      <div class="relative min-h-[350px]">
        <div id="sponsor-bento-grid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 transition-all duration-300">
          
          <!-- Sponsor Card 1: FlexoHost -->
          <div class="sponsor-bento-card bg-slate-50 border border-slate-200/60 rounded-3xl p-8 flex flex-col justify-between items-center text-center cursor-pointer transition-all duration-300 h-64 hover:-translate-y-1 hover:bg-slate-100/50 hover:shadow-md" data-sponsor="flexohost">
            <div class="flex-1 flex items-center justify-center">
              <img src="https://www.flexohost.com/favicon.ico" alt="FlexoHost Logo" class="h-16 w-auto object-contain filter drop-shadow-sm" onerror="this.style.display='none';this.parentElement.innerHTML='<span class=\\'text-slate-800 font-black text-3xl\\'>FlexoHost</span>'" />
            </div>
            <div class="mt-4">
              <h4 class="text-lg font-bold text-slate-900">FlexoHost</h4>
              <span class="text-xs text-blue-600 font-semibold bg-blue-50 px-2 py-0.5 rounded-full border border-blue-100/80">Infrastructure</span>
            </div>
          </div>

          <!-- Sponsor Card 2: HostSite24 -->
          <div class="sponsor-bento-card bg-slate-50 border border-slate-200/60 rounded-3xl p-8 flex flex-col justify-between items-center text-center cursor-pointer transition-all duration-300 h-64 hover:-translate-y-1 hover:bg-slate-100/50 hover:shadow-md" data-sponsor="hostsite24">
            <div class="flex-1 flex items-center justify-center">
              <img src="assets/img/sponsors/hostsite24.png" alt="HostSite24 Logo" class="h-12 w-auto object-contain filter drop-shadow-sm" onerror="this.style.display='none';this.parentElement.innerHTML='<span class=\\'text-slate-800 font-black text-3xl\\'>HostSite24</span>'" />
            </div>
            <div class="mt-4">
              <h4 class="text-lg font-bold text-slate-900">HostSite24</h4>
              <span class="text-xs text-cyan-600 font-semibold bg-cyan-50 px-2 py-0.5 rounded-full border border-cyan-100/80">Sandbox Nodes</span>
            </div>
          </div>

          <!-- Sponsor Card 3: Bangla Hoster -->
          <div class="sponsor-bento-card bg-slate-50 border border-slate-200/60 rounded-3xl p-8 flex flex-col justify-between items-center text-center cursor-pointer transition-all duration-300 h-64 hover:-translate-y-1 hover:bg-slate-100/50 hover:shadow-md" data-sponsor="banglahoster">
            <div class="flex-1 flex items-center justify-center">
              <img src="assets/img/sponsors/banglahoster.svg" alt="Bangla Hoster Logo" class="h-10 w-auto object-contain filter drop-shadow-sm" onerror="this.style.display='none';this.parentElement.innerHTML='<span class=\\'text-slate-800 font-black text-3xl\\'>Bangla Hoster</span>'" />
            </div>
            <div class="mt-4">
              <h4 class="text-lg font-bold text-slate-900">Bangla Hoster</h4>
              <span class="text-xs text-violet-600 font-semibold bg-violet-50 px-2 py-0.5 rounded-full border border-violet-100/80">Mirrors</span>
            </div>
          </div>

          <!-- Sponsor Card 4: Namepart -->
          <div class="sponsor-bento-card bg-slate-50 border border-slate-200/60 rounded-3xl p-8 flex flex-col justify-between items-center text-center cursor-pointer transition-all duration-300 h-64 hover:-translate-y-1 hover:bg-slate-100/50 hover:shadow-md" data-sponsor="namepart">
            <div class="flex-1 flex items-center justify-center">
              <img src="assets/img/sponsors/namepart_logo.png" alt="Namepart Logo" class="h-10 w-auto object-contain filter drop-shadow-sm" onerror="this.style.display='none';this.parentElement.innerHTML='<span class=\\'text-slate-800 font-black text-3xl\\'>Namepart</span>'" />
            </div>
            <div class="mt-4">
              <h4 class="text-lg font-bold text-slate-900">Namepart</h4>
              <span class="text-xs text-amber-600 font-semibold bg-amber-50 px-2 py-0.5 rounded-full border border-amber-100/80">Branding</span>
            </div>
          </div>

        </div>

        <!-- Sleek Floating Overlay Card Details -->
        <div id="sponsor-detail-overlay" class="absolute inset-0 bg-white/90 backdrop-blur-md rounded-3xl border border-slate-200 shadow-2xl flex flex-col items-center justify-center p-8 transition-all duration-300 transform scale-95 opacity-0 pointer-events-none z-20">
          <button id="close-sponsor-overlay" class="absolute top-4 right-4 text-slate-400 hover:text-slate-800 text-3xl transition-colors font-bold">&times;</button>
          <div class="max-w-md text-center flex flex-col items-center">
            <div id="overlay-logo-box" class="w-20 h-20 rounded-2xl bg-white border border-slate-200 shadow-sm flex items-center justify-center p-3 mb-4">
              <!-- Logo goes here dynamically -->
            </div>
            <h3 id="overlay-title" class="text-xl font-black text-slate-900 tracking-tight mb-1">Sponsor Name</h3>
            <span id="overlay-badge" class="text-[10px] font-bold px-2.5 py-0.5 rounded-full mb-4 inline-block">Tier</span>
            <p id="overlay-desc" class="text-slate-600 text-xs leading-relaxed mb-6">Description details go here...</p>
            <a id="overlay-link" href="#" target="_blank" class="inline-flex items-center gap-1.5 px-5 py-2.5 rounded-xl bg-blue-600 text-white text-xs font-bold shadow-md hover:bg-blue-700 transition-colors">
              Visit Website
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
              </svg>
            </a>
          </div>
        </div>

      </div>
    </div>
  </section>

"""

content = content.replace(roadmap_comment, bento_section + roadmap_comment)

# ─── SECTION 2: OVERHAUL THE DEVELOPMENT ROADMAP ───
# Let's locate the entire roadmap section starting from:
# `<section id="roadmap"` all the way to `</section>` preceding the FAQ section.
# First, let's find `FAQ SECTION` comment
faq_comment = """  <!-- ═══════════════════════════════════════════════════
     FAQ SECTION"""

roadmap_start = content.find('<section id="roadmap"')
faq_start = content.find(faq_comment)

# Overwrite roadmap section with horizontal premium timeline scroller
roadmap_replacement = """<section id="roadmap" class="py-24 px-4 bg-slate-50 border-t border-slate-100 relative">
    <div class="max-w-6xl mx-auto">
      <div class="text-center mb-16 reveal">
        <span class="text-xs font-bold tracking-widest uppercase text-blue-600 mb-4 block">Continuous Evolution</span>
        <h2 class="text-3xl sm:text-4xl font-extrabold text-slate-900 leading-tight">OwnPay Development Map</h2>
        <p class="text-slate-500 text-xs mt-3 max-w-md mx-auto">Drag or scroll horizontally to explore our phased architecture checkpoints and milestone details.</p>
      </div>

      <!-- Horizontal Scroll Track -->
      <div class="overflow-x-auto pb-8 scrollbar-thin scrollbar-thumb-slate-200 select-none cursor-grab active:cursor-grabbing" id="roadmap-scroll-track" style="mask-image: linear-gradient(to right, transparent, white 4%, white 96%, transparent);">
        <div class="flex gap-8 px-4" style="width: max-content;">
          
          <!-- Phase 1 -->
          <div class="w-80 flex-shrink-0 bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm flex flex-col justify-between h-[390px] relative group hover:border-blue-500 hover:shadow-md transition-all duration-300">
            <div class="flex items-center justify-between mb-4">
              <span class="text-2xl font-black text-slate-700/30 group-hover:text-blue-500/20 font-mono">P1</span>
              <span class="text-[10px] font-extrabold text-blue-700 bg-blue-50 border border-blue-100 px-2 py-0.5 rounded-full uppercase tracking-wider">Completed</span>
            </div>
            <div class="flex-1 flex flex-col justify-start">
              <h4 class="text-base font-extrabold text-slate-900 mb-3">Architecture &amp; SOA Core</h4>
              <p class="text-slate-500 text-xs leading-relaxed mb-4">
                Core Custom Service-Oriented Architecture setup, autowired PSR-11 dependency container abstractions, and modular plug-and-play drivers.
              </p>
              <ul class="space-y-1.5 border-t border-slate-100 pt-3">
                <li class="flex items-center gap-2 text-[11px] text-slate-500 font-medium">
                  <span class="text-emerald-500">✓</span> autowired PSR-11 Container
                </li>
                <li class="flex items-center gap-2 text-[11px] text-slate-500 font-medium">
                  <span class="text-emerald-500">✓</span> Service-Oriented Core
                </li>
              </ul>
            </div>
          </div>

          <!-- Phase 2 -->
          <div class="w-80 flex-shrink-0 bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm flex flex-col justify-between h-[390px] relative group hover:border-blue-500 hover:shadow-md transition-all duration-300">
            <div class="flex items-center justify-between mb-4">
              <span class="text-2xl font-black text-slate-700/30 group-hover:text-blue-500/20 font-mono">P2</span>
              <span class="text-[10px] font-extrabold text-blue-700 bg-blue-50 border border-blue-100 px-2 py-0.5 rounded-full uppercase tracking-wider">Completed</span>
            </div>
            <div class="flex-1 flex flex-col justify-start">
              <h4 class="text-base font-extrabold text-slate-900 mb-3">Universal Plugin Engine</h4>
              <p class="text-slate-500 text-xs leading-relaxed mb-4">
                Event-driven loader matching base filters, webhook managers, sandboxing capability grids, and safe isolated ZIP package uploads.
              </p>
              <ul class="space-y-1.5 border-t border-slate-100 pt-3">
                <li class="flex items-center gap-2 text-[11px] text-slate-500 font-medium">
                  <span class="text-emerald-500">✓</span> Sandboxed execution
                </li>
                <li class="flex items-center gap-2 text-[11px] text-slate-500 font-medium">
                  <span class="text-emerald-500">✓</span> ZIP-based plugins loader
                </li>
              </ul>
            </div>
          </div>

          <!-- Phase 3 -->
          <div class="w-80 flex-shrink-0 bg-emerald-50/50 border-2 border-emerald-400/80 rounded-3xl p-6 shadow-md flex flex-col justify-between h-[390px] relative group hover:shadow-lg transition-all duration-300">
            <div class="flex items-center justify-between mb-4">
              <span class="text-2xl font-black text-emerald-800/35 font-mono">P3</span>
              <span class="text-[10px] font-extrabold text-emerald-700 bg-emerald-100/60 border border-emerald-300/80 px-2 py-0.5 rounded-full uppercase tracking-wider">Done &amp; Audited</span>
            </div>
            <div class="flex-1 flex flex-col justify-start">
              <h4 class="text-base font-extrabold text-slate-900 mb-2">Alpha Audit &amp; Hardening</h4>
              <p class="text-slate-600 text-xs leading-relaxed mb-3">
                Mitigating architectural vulnerabilities, hardening static SAST layers, and testing execution parameters under closed beta access.
              </p>
              
              <!-- Subtask 3.3 Nested Compact Panel -->
              <div class="bg-white border border-emerald-200 rounded-xl p-3 shadow-sm">
                <div class="flex items-center gap-1.5 text-[11px] font-extrabold text-emerald-700 uppercase tracking-wider mb-1">
                  <span>⚙</span> Subtask 3.3: Closed Beta Testing
                </div>
                <p class="text-[10px] text-slate-500 leading-normal">
                  Running exhaustive step-by-step logic checking, validating error exceptions, and running real configurations under limited test cohorts.
                </p>
              </div>
            </div>
          </div>

          <!-- Phase 4 -->
          <div class="w-80 flex-shrink-0 bg-amber-50 border border-amber-200 rounded-3xl p-6 shadow-sm flex flex-col justify-between h-[390px] relative group hover:border-amber-400 hover:shadow-md transition-all duration-300">
            <div class="flex items-center justify-between mb-4">
              <span class="text-2xl font-black text-slate-700/30 group-hover:text-amber-500/20 font-mono">P4</span>
              <span class="text-[10px] font-extrabold text-amber-700 bg-amber-100 border border-amber-200 px-2 py-0.5 rounded-full uppercase tracking-wider">Active</span>
            </div>
            <div class="flex-1 flex flex-col justify-start">
              <h4 class="text-base font-extrabold text-slate-900 mb-3">Public Beta Code Drop</h4>
              <p class="text-slate-500 text-xs leading-relaxed mb-4">
                Initial public code release under AGPL-3.0 licensing. Star-CTA triggers, waitlist cohort onboarding, and live setup modules activation.
              </p>
              <ul class="space-y-1.5 border-t border-amber-100 pt-3">
                <li class="flex items-center gap-2 text-[11px] text-slate-500 font-medium">
                  <span class="relative flex h-2 w-2"><span class="ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span><span class="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span></span> Codebase Open-Source drop
                </li>
              </ul>
            </div>
          </div>

          <!-- Phase 5 -->
          <div class="w-80 flex-shrink-0 bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm flex flex-col justify-between h-[390px] relative group hover:border-blue-500 hover:shadow-md transition-all duration-300">
            <div class="flex items-center justify-between mb-4">
              <span class="text-2xl font-black text-slate-700/30 group-hover:text-blue-500/20 font-mono">P5</span>
              <span class="text-[10px] font-extrabold text-slate-400 bg-slate-50 border border-slate-200 px-2 py-0.5 rounded-full uppercase tracking-wider">Roadmap</span>
            </div>
            <div class="flex-1 flex flex-col justify-start">
              <h4 class="text-base font-extrabold text-slate-900 mb-2">Admin UI UX &amp; Maintenance</h4>
              <p class="text-slate-500 text-xs leading-relaxed mb-3">
                Crafting customizable administrative templates, and releasing follow-up patches to guarantee core workflow consistency.
              </p>
              
              <!-- Subtasks list -->
              <div class="space-y-1.5 border-t border-slate-100 pt-3 text-[10px] text-slate-500">
                <div class="flex items-start gap-1"><span class="text-blue-500">•</span> <span><strong>5.1:</strong> Critical Bug Mitigation Updates</span></div>
                <div class="flex items-start gap-1"><span class="text-blue-500">•</span> <span><strong>5.2:</strong> User Experience Follow-ups</span></div>
                <div class="flex items-start gap-1"><span class="text-blue-500">•</span> <span><strong>5.3:</strong> Feedback-driven core tweaks</span></div>
              </div>
            </div>
          </div>

          <!-- Phase 6 -->
          <div class="w-80 flex-shrink-0 bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm flex flex-col justify-between h-[390px] relative group hover:border-blue-500 hover:shadow-md transition-all duration-300">
            <div class="flex items-center justify-between mb-4">
              <span class="text-2xl font-black text-slate-700/30 group-hover:text-blue-500/20 font-mono">P6</span>
              <span class="text-[10px] font-extrabold text-slate-400 bg-slate-50 border border-slate-200 px-2 py-0.5 rounded-full uppercase tracking-wider">Roadmap</span>
            </div>
            <div class="flex-1 flex flex-col justify-start">
              <h4 class="text-base font-extrabold text-slate-900 mb-2">Mobile Onboarding &amp; SDKs</h4>
              <p class="text-slate-500 text-xs leading-relaxed mb-3">
                Aggregating administrative review feedback to prepare the initial closed beta mobile tracking apps.
              </p>
              
              <!-- Subtasks -->
              <div class="space-y-1.5 border-t border-slate-100 pt-3 text-[10px] text-slate-500">
                <div class="flex items-start gap-1"><span class="text-blue-500">•</span> <span><strong>6.0:</strong> Direct mobile feedback logs</span></div>
                <div class="flex items-start gap-1"><span class="text-blue-500">•</span> <span><strong>6.1:</strong> Release dev kits, packages &amp; SDKs</span></div>
              </div>
            </div>
          </div>

          <!-- Phase 7 -->
          <div class="w-80 flex-shrink-0 bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm flex flex-col justify-between h-[390px] relative group hover:border-blue-500 hover:shadow-md transition-all duration-300">
            <div class="flex items-center justify-between mb-4">
              <span class="text-2xl font-black text-slate-700/30 group-hover:text-blue-500/20 font-mono">P7</span>
              <span class="text-[10px] font-extrabold text-slate-400 bg-slate-50 border border-slate-200 px-2 py-0.5 rounded-full uppercase tracking-wider">Roadmap</span>
            </div>
            <div class="flex-1 flex flex-col justify-start">
              <h4 class="text-base font-extrabold text-slate-900 mb-3">Next-Gen Panel Release</h4>
              <p class="text-slate-500 text-xs leading-relaxed mb-4">
                Consolidating core platform optimizations and introducing the redesigned next-gen administration panel.
              </p>
              <ul class="space-y-1.5 border-t border-slate-100 pt-3">
                <li class="flex items-center gap-2 text-[11px] text-slate-500 font-medium">
                  <span class="text-blue-500">•</span> Redesigned admin panel UI/UX
                </li>
              </ul>
            </div>
          </div>

        </div>
      </div>
    </div>
  </section>

"""

if roadmap_start != -1 and faq_start != -1:
    content = content[:roadmap_start] + roadmap_replacement + content[faq_start:]

# ─── SECTION 3: OVERHAUL THE GITHUB CTA SECTION ───
# Let's locate the mid-page Github CTA section
# Starts at `<!-- ═══════════════════════════════════════════════════\n     MID-PAGE GITHUB CTA`
# Ends right before the `FAQ SECTION` comment
github_comment = "<!-- ═══════════════════════════════════════════════════\n     MID-PAGE GITHUB CTA"
faq_comment_actual = "<!-- ═══════════════════════════════════════════════════\n     FAQ SECTION"

github_start = content.find(github_comment)
faq_start = content.find(faq_comment_actual)

premium_github = """<!-- ═══════════════════════════════════════════════════
     MID-PAGE GITHUB CTA
════════════════════════════════════════════════════ -->
  <section class="py-24 px-4 bg-slate-900 relative overflow-hidden">
    <!-- Mesh background -->
    <div class="absolute inset-0 pointer-events-none">
      <div class="absolute -top-32 -right-32 w-[550px] h-[550px] bg-blue-600 rounded-full blur-[140px] opacity-15"></div>
      <div class="absolute -bottom-32 -left-32 w-[550px] h-[550px] bg-cyan-500 rounded-full blur-[140px] opacity-10"></div>
      <!-- Grid -->
      <div style="position:absolute;inset:0;background-image:linear-gradient(rgba(99,179,237,0.04) 1px,transparent 1px),linear-gradient(90deg,rgba(99,179,237,0.04) 1px,transparent 1px);background-size:40px 40px;"></div>
    </div>

    <div class="relative z-10 max-w-3xl mx-auto text-center reveal">
      <span class="text-xs font-bold tracking-widest uppercase text-blue-400 mb-5 block">Open Source Ecosystem</span>
      <h2 class="text-3xl sm:text-4xl font-extrabold text-white mb-5 leading-tight">
        Built by the Community,<br />for the Community.
      </h2>
      <p class="text-slate-300 text-sm leading-relaxed mb-10 max-w-lg mx-auto">
        Join the global financial sovereignty movement. Help us maintain direct payment independence by dropping a star on our GitHub repository.
      </p>
      <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
        <a href="https://github.com/own-pay/ownpay" target="_blank" rel="noopener noreferrer"
          class="inline-flex items-center gap-3 px-8 py-4 rounded-xl bg-white text-slate-900 text-sm font-extrabold shadow-2xl hover:bg-slate-50 hover:-translate-y-0.5 transition-all duration-200">
          <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
            <path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.942.359.31.678.921.678 1.856 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z"/>
          </svg>
          ⭐ Star Own Pay on GitHub
        </a>
        <a href="donate.php" target="_blank" rel="noopener noreferrer"
          class="inline-flex items-center gap-2 px-7 py-4 rounded-xl border-2 border-rose-400/40 bg-rose-500/10 text-rose-300 text-sm font-bold hover:bg-rose-500/20 hover:-translate-y-0.5 transition-all duration-200">
          <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
            <path d="M12 21.593c-5.63-5.539-11-10.297-11-14.402C1 3.147 4.198 1 7.5 1c1.524 0 3.049.574 4.5 1.685C13.451 1.574 14.976 1 16.5 1 19.802 1 23 3.147 23 7.191c0 4.105-5.37 8.863-11 14.402z"/>
          </svg>
          Sponsor / Donate
        </a>
      </div>
    </div>
  </section>

"""

if github_start != -1 and faq_start != -1:
    content = content[:github_start] + premium_github + content[faq_start:]

# ─── SECTION 4: REDESIGN THE FAQ SECTION ───
# Let's locate the FAQ section starting at:
# `<section id="faq"` all the way to `</section>` preceding the Sponsors/Contributors section.
# First, let's find `SPONSORS & CONTRIBUTORS` comment
sponsors_comment = """  <!-- ═══════════════════════════════════════════════════
     SPONSORS & CONTRIBUTORS"""

faq_start = content.find('<section id="faq"')
sponsors_start = content.find(sponsors_comment)

faq_replacement = """<section id="faq" class="py-24 px-4 bg-white border-t border-slate-100">
    <div class="max-w-6xl mx-auto">

      <div class="text-center mb-16 reveal">
        <span class="text-xs font-bold tracking-widest uppercase text-blue-600 mb-4 block">Got Questions?</span>
        <h2 class="text-3xl sm:text-4xl font-extrabold text-slate-900 leading-tight">Frequently Asked Questions</h2>
        <p class="text-slate-500 text-xs mt-3">Simple answers to common questions about OwnPay's core architecture and distribution timeline.</p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-6" id="faq-list">

        <!-- Q1 -->
        <div class="faq-item bg-slate-50 border border-slate-200/60 rounded-2xl overflow-hidden transition-all duration-300 reveal">
          <button class="faq-trigger w-full flex items-center justify-between px-6 py-4 text-left gap-4 group" aria-expanded="false">
            <span class="text-sm font-bold text-slate-900 group-hover:text-blue-700 transition-colors">Is Own Pay built on Laravel?</span>
            <svg class="faq-chevron w-4 h-4 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7" />
            </svg>
          </button>
          <div class="faq-body">
            <p class="px-6 pb-5 text-xs text-slate-500 leading-relaxed border-t border-slate-100 pt-3 bg-white/40">
              No. To maximize performance and security without bloat, we built a proprietary, lightweight PHP 8.2+ framework following strict PSR standards and a modern Service-Oriented Architecture (SOA).
            </p>
          </div>
        </div>

        <!-- Q2 -->
        <div class="faq-item bg-slate-50 border border-slate-200/60 rounded-2xl overflow-hidden transition-all duration-300 reveal" style="transition-delay:.05s">
          <button class="faq-trigger w-full flex items-center justify-between px-6 py-4 text-left gap-4 group" aria-expanded="false">
            <span class="text-sm font-bold text-slate-900 group-hover:text-blue-700 transition-colors">Where is the GitHub codebase?</span>
            <svg class="faq-chevron w-4 h-4 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7" />
            </svg>
          </button>
          <div class="faq-body">
            <p class="px-6 pb-5 text-xs text-slate-500 leading-relaxed border-t border-slate-100 pt-3 bg-white/40">
              We are currently fixing vulnerabilities discovered during our Alpha audit. We refuse to publish insecure code. The repository will be updated during the Public Beta release.
            </p>
          </div>
        </div>

        <!-- Q3 -->
        <div class="faq-item bg-slate-50 border border-slate-200/60 rounded-2xl overflow-hidden transition-all duration-300 reveal" style="transition-delay:.1s">
          <button class="faq-trigger w-full flex items-center justify-between px-6 py-4 text-left gap-4 group" aria-expanded="false">
            <span class="text-sm font-bold text-slate-900 group-hover:text-blue-700 transition-colors">What happened to PipraPay?</span>
            <svg class="faq-chevron w-4 h-4 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7" />
            </svg>
          </button>
          <div class="faq-body">
            <p class="px-6 pb-5 text-xs text-slate-500 leading-relaxed border-t border-slate-100 pt-3 bg-white/40">
              Own Pay is the heavily refactored, modernized, and rebranded evolution fork of the PipraPay projects.
            </p>
          </div>
        </div>

        <!-- Q4 -->
        <div class="faq-item bg-slate-50 border border-slate-200/60 rounded-2xl overflow-hidden transition-all duration-300 reveal" style="transition-delay:.15s">
          <button class="faq-trigger w-full flex items-center justify-between px-6 py-4 text-left gap-4 group" aria-expanded="false">
            <span class="text-sm font-bold text-slate-900 group-hover:text-blue-700 transition-colors">Is it free for commercial use?</span>
            <svg class="faq-chevron w-4 h-4 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7" />
            </svg>
          </button>
          <div class="faq-body">
            <p class="px-6 pb-5 text-xs text-slate-500 leading-relaxed border-t border-slate-100 pt-3 bg-white/40">
              Yes, completely! Since it is licensed under AGPL-3.0, you are fully authorized to self-host, customize, and deploy it for any enterprise white-label portal without vendor tax.
            </p>
          </div>
        </div>

      </div>
    </div>
  </section>

"""

if faq_start != -1 and sponsors_start != -1:
    content = content[:faq_start] + faq_replacement + content[sponsors_start:]

# ─── SECTION 5: REDESIGN CONTRIBUTORS & FOOTER ───
# Let's locate the entire remaining section starting at `<!-- ═══════════════════════════════════════════════════\n     SPONSORS & CONTRIBUTORS`
# all the way to `</html>`
sponsors_comment_index = content.find(sponsors_comment)

contributors_and_footer = """  <!-- ═══════════════════════════════════════════════════
     SPONSORS & CONTRIBUTORS (RE-DESIGNED CIRCLES)
 ════════════════════════════════════════════════════ -->
  <section class="py-24 px-4 bg-slate-50 border-t border-slate-200">
    <div class="max-w-6xl mx-auto">

      <div class="text-center mb-16 reveal">
        <span class="text-xs font-bold tracking-widest uppercase text-blue-600 mb-4 block">Pillars of OwnPay</span>
        <h2 class="text-3xl sm:text-4xl font-extrabold text-slate-900 mb-4">Sponsors &amp; Supporter Circles</h2>
        <p class="text-slate-500 text-xs max-w-md mx-auto leading-relaxed">
          OwnPay operates purely on open-source solidarity. Hover over each contributor profile below to explore their visual thank-you notes.
        </p>
      </div>

      <div class="space-y-12">
        <!-- Tier 1: Elite Sponsors -->
        <div class="text-center">
          <span class="text-[10px] font-black text-amber-600 bg-amber-50 border border-amber-200/80 px-3 py-1 rounded-full uppercase tracking-widest block w-max mx-auto mb-6">Elite Corporate Sponsors</span>
          <div class="flex flex-wrap items-center justify-center gap-6">
            <!-- Namepart Circle -->
            <div class="supporter-avatar-container">
              <div class="w-20 h-20 rounded-full border-4 border-amber-400 p-1 bg-white shadow-md flex items-center justify-center hover:scale-105 transition-all duration-300">
                <img src="assets/img/sponsors/namepart_logo.png" alt="Namepart" class="w-16 h-16 rounded-full object-contain" />
              </div>
              <div class="supporter-tooltip">
                <h5 class="font-bold text-white text-xs">Namepart</h5>
                <span class="text-[9px] text-amber-400 font-semibold uppercase tracking-wider block mt-0.5">Elite Sponsor</span>
                <p class="text-[10px] text-slate-300 leading-snug mt-1.5">"Providing visual branding kits and foundational developer environments."</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Tier 2: Regular Sponsors (<$50) -->
        <div class="text-center">
          <span class="text-[10px] font-black text-blue-600 bg-blue-50 border border-blue-200/80 px-3 py-1 rounded-full uppercase tracking-widest block w-max mx-auto mb-6">Regular Infrastructure Backers</span>
          <div class="flex flex-wrap items-center justify-center gap-6">
            <!-- FlexoHost Circle -->
            <div class="supporter-avatar-container">
              <div class="w-16 h-16 rounded-full border-4 border-blue-400 p-1 bg-white shadow-sm flex items-center justify-center hover:scale-105 transition-all duration-300">
                <img src="https://www.flexohost.com/favicon.ico" alt="FlexoHost" class="w-12 h-12 rounded-full object-contain" onerror="this.style.display='none';this.parentElement.innerHTML='<span class=\\'text-slate-800 font-bold text-sm\\'>FH</span>'" />
              </div>
              <div class="supporter-tooltip">
                <h5 class="font-bold text-white text-xs">FlexoHost</h5>
                <span class="text-[9px] text-blue-400 font-semibold uppercase tracking-wider block mt-0.5">Domain Sponsor</span>
                <p class="text-[10px] text-slate-300 leading-snug mt-1.5">"Providing our official ownpay.org domain and deployment servers."</p>
              </div>
            </div>

            <!-- HostSite24 Circle -->
            <div class="supporter-avatar-container">
              <div class="w-16 h-16 rounded-full border-4 border-blue-400 p-1 bg-white shadow-sm flex items-center justify-center hover:scale-105 transition-all duration-300">
                <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center font-bold text-slate-800 text-sm">HS</div>
              </div>
              <div class="supporter-tooltip">
                <h5 class="font-bold text-white text-xs">HostSite24</h5>
                <span class="text-[9px] text-blue-400 font-semibold uppercase tracking-wider block mt-0.5">Sandbox Backer</span>
                <p class="text-[10px] text-slate-300 leading-snug mt-1.5">"Sponsored premium cloud instances for sandbox routing."</p>
              </div>
            </div>

            <!-- Bangla Hoster Circle -->
            <div class="supporter-avatar-container">
              <div class="w-16 h-16 rounded-full border-4 border-blue-400 p-1 bg-white shadow-sm flex items-center justify-center hover:scale-105 transition-all duration-300">
                <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center font-bold text-slate-800 text-sm">BH</div>
              </div>
              <div class="supporter-tooltip">
                <h5 class="font-bold text-white text-xs">Bangla Hoster</h5>
                <span class="text-[9px] text-blue-400 font-semibold uppercase tracking-wider block mt-0.5">Storage Backer</span>
                <p class="text-[10px] text-slate-300 leading-snug mt-1.5">"Sponsored persistent backups and repository storage mirrors."</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Tier 3: Impact Contributors -->
        <div class="text-center">
          <span class="text-[10px] font-black text-emerald-600 bg-emerald-50 border border-emerald-200/80 px-3 py-1 rounded-full uppercase tracking-widest block w-max mx-auto mb-6">Valuable Impact Contributors</span>
          <div class="flex flex-wrap items-center justify-center gap-6">
            <!-- Abdullah Bin Ziad -->
            <div class="supporter-avatar-container">
              <div class="w-14 h-14 rounded-full border-4 border-emerald-400 p-0.5 bg-white shadow-sm flex items-center justify-center hover:scale-105 transition-all duration-300">
                <div class="w-11 h-11 rounded-full bg-slate-800 flex items-center justify-center font-extrabold text-white text-xs">AZ</div>
              </div>
              <div class="supporter-tooltip">
                <h5 class="font-bold text-white text-xs">Abdullah Bin Ziad</h5>
                <span class="text-[9px] text-emerald-400 font-semibold uppercase tracking-wider block mt-0.5">Rebranding Advice</span>
                <p class="text-[10px] text-slate-300 leading-snug mt-1.5">"Credited with recommending the name OwnPay and cloud support."</p>
              </div>
            </div>

            <!-- Naime Fattain -->
            <div class="supporter-avatar-container">
              <div class="w-14 h-14 rounded-full border-4 border-emerald-400 p-0.5 bg-white shadow-sm flex items-center justify-center hover:scale-105 transition-all duration-300">
                <div class="w-11 h-11 rounded-full bg-slate-800 flex items-center justify-center font-extrabold text-white text-xs">NF</div>
              </div>
              <div class="supporter-tooltip">
                <h5 class="font-bold text-white text-xs">Naime Fattain</h5>
                <span class="text-[9px] text-emerald-400 font-semibold uppercase tracking-wider block mt-0.5">Core Developer</span>
                <p class="text-[10px] text-slate-300 leading-snug mt-1.5">"Developed foundational PSR Container routing structures."</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ═══════════════════════════════════════════════════
     PREMIUM MODERN NAVIGATION FOOTER
 ════════════════════════════════════════════════════ -->
  <footer class="bg-slate-900 border-t border-slate-800 py-16 px-4">
    <div class="max-w-6xl mx-auto">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-12 mb-12">
        <!-- Brand -->
        <div class="md:col-span-2">
          <img src="ownpay_logo.png" alt="OwnPay" class="h-8 w-auto mb-4 object-contain brightness-0 invert" />
          <p class="text-slate-400 text-xs leading-relaxed max-w-sm">
            Self-hosted payment gateway automation platform engineered for absolute visual white-label sovereignty and zero transaction tax. Built by the community, for the community.
          </p>
        </div>

        <!-- Column 1: Resources -->
        <div>
          <h4 class="text-slate-200 text-xs font-black uppercase tracking-wider mb-4">Platform</h4>
          <ul class="space-y-2.5 text-xs">
            <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Home</a></li>
            <li><a href="https://docs.ownpay.org" target="_blank" class="text-slate-400 hover:text-white transition-colors">Docs</a></li>
            <li><a href="https://learn.ownpay.org" target="_blank" class="text-slate-400 hover:text-white transition-colors">Learn</a></li>
            <li><a href="https://blog.ownpay.org" target="_blank" class="text-slate-400 hover:text-white transition-colors">Blog</a></li>
            <li><a href="donate.php" target="_blank" class="text-rose-400 hover:text-rose-300 font-bold transition-colors">Donate / Sponsor</a></li>
          </ul>
        </div>

        <!-- Column 2: Coordinate social -->
        <div>
          <h4 class="text-slate-200 text-xs font-black uppercase tracking-wider mb-4">Community</h4>
          <ul class="space-y-2.5 text-xs">
            <li><a href="https://www.facebook.com/share/g/1E61TeFgLz/" target="_blank" class="text-slate-400 hover:text-white transition-colors flex items-center gap-1">Facebook Group</a></li>
            <li><a href="https://facebook.com/ownpay.org" target="_blank" class="text-slate-400 hover:text-white transition-colors">Facebook Page</a></li>
            <li><a href="https://github.com/own-pay/ownpay" target="_blank" class="text-slate-400 hover:text-white transition-colors">GitHub Repository</a></li>
            <li><a href="https://facebook.com/fattain.naime" target="_blank" class="text-slate-400 hover:text-white transition-colors">Contact Support</a></li>
          </ul>
        </div>
      </div>

      <hr class="border-slate-800 mb-8" />

      <!-- Symmetrical copyright -->
      <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
        <div class="text-slate-500 text-xs font-medium">
          &copy; 2026 OwnPay Project. Licensed under <a href="https://www.gnu.org/licenses/agpl-3.0.html" target="_blank" class="text-blue-400 hover:text-blue-300 underline underline-offset-2">AGPL-3.0</a>.
        </div>
        <div class="text-slate-500 text-xs text-center sm:text-right font-medium max-w-md leading-relaxed">
          Secured with absolute cryptographic integrity and driven by the <a href="https://ownpay.org" target="_blank" class="text-blue-400 hover:text-blue-300 font-bold">OwnPay Sovereign Engine</a> — engineering the autonomous, direct-settlement future of global enterprise payments.
        </div>
      </div>
    </div>
  </footer>

  <!-- ═══════════════════════════════════════════════════
     JAVASCRIPT EXTENSION TRIGGER
 ════════════════════════════════════════════════════ -->
  <script>
    // Bento Sponsors dynamic trigger popover
    const sponsorDetails = {
      flexohost: {
        name: "FlexoHost",
        logo: "https://www.flexohost.com/favicon.ico",
        badge: "Infrastructure",
        badgeClass: "text-blue-600 bg-blue-50 border border-blue-100",
        desc: "FlexoHost sponsored our platform domain and hosting infrastructure for testing and deployment.",
        url: "https://www.flexohost.com"
      },
      hostsite24: {
        name: "HostSite24",
        logo: "assets/img/sponsors/hostsite24.png",
        badge: "Sandbox Nodes",
        badgeClass: "text-cyan-600 bg-cyan-50 border border-cyan-100",
        desc: "HostSite24 sponsored high-speed cloud sandbox nodes for API latency verification.",
        url: "https://hostsite24.com"
      },
      banglahoster: {
        name: "Bangla Hoster",
        logo: "assets/img/sponsors/banglahoster.svg",
        badge: "Mirrors",
        badgeClass: "text-violet-600 bg-violet-50 border border-violet-100",
        desc: "Bangla Hoster sponsored cloud storage and secure backup mirrors for package deployment.",
        url: "https://banglahoster.net"
      },
      namepart: {
        name: "Namepart",
        logo: "assets/img/sponsors/namepart_logo.png",
        badge: "Branding",
        badgeClass: "text-amber-600 bg-amber-50 border border-amber-100",
        desc: "Namepart sponsored branding kits, digital design visual elements, and core developer tools.",
        url: "https://namepart.com"
      }
    };

    document.querySelectorAll(".sponsor-bento-card").forEach(card => {
      card.addEventListener("click", function() {
        const id = this.getAttribute("data-sponsor");
        const details = sponsorDetails[id];
        if (!details) return;

        // Dim others
        document.querySelectorAll(".sponsor-bento-card").forEach(c => {
          if (c !== card) c.classList.add("is-dimmed");
        });

        // Populate overlay popup
        document.getElementById("overlay-title").innerText = details.name;
        document.getElementById("overlay-desc").innerText = details.desc;
        document.getElementById("overlay-link").setAttribute("href", details.url);
        
        const badgeEl = document.getElementById("overlay-badge");
        badgeEl.className = `text-[10px] font-bold px-2.5 py-0.5 rounded-full mb-4 inline-block ${details.badgeClass}`;
        badgeEl.innerText = details.badge;

        const logoBox = document.getElementById("overlay-logo-box");
        logoBox.innerHTML = `<img src="${details.logo}" alt="${details.name}" class="h-12 w-auto object-contain filter drop-shadow-sm" onerror="this.style.display='none';this.parentElement.innerHTML='<span class=\\'text-slate-800 font-bold text-xl\\'>${details.name.substring(0,2)}</span>'" />`;

        // Fade in
        const overlay = document.getElementById("sponsor-detail-overlay");
        overlay.classList.remove("pointer-events-none", "opacity-0", "scale-95");
        overlay.classList.add("opacity-100", "scale-100");
      });
    });

    document.getElementById("close-sponsor-overlay").addEventListener("click", function() {
      // Fade out
      const overlay = document.getElementById("sponsor-detail-overlay");
      overlay.classList.add("pointer-events-none", "opacity-0", "scale-95");
      overlay.classList.remove("opacity-100", "scale-100");

      // Undim others
      document.querySelectorAll(".sponsor-bento-card").forEach(c => {
        c.classList.remove("is-dimmed");
      });
    });

    // Sleek drag to scroll grab scroller trigger for horizontal map
    const track = document.getElementById("roadmap-scroll-track");
    let isDown = false;
    let startX;
    let scrollLeft;

    if (track) {
      track.addEventListener("mousedown", (e) => {
        isDown = true;
        track.classList.add("active");
        startX = e.pageX - track.offsetLeft;
        scrollLeft = track.scrollLeft;
      });
      track.addEventListener("mouseleave", () => {
        isDown = false;
        track.classList.remove("active");
      });
      track.addEventListener("mouseup", () => {
        isDown = false;
        track.classList.remove("active");
      });
      track.addEventListener("mousemove", (e) => {
        if (!isDown) return;
        e.preventDefault();
        const x = e.pageX - track.offsetLeft;
        const walk = (x - startX) * 1.5; // Scroll speed factor
        track.scrollLeft = scrollLeft - walk;
      });
    }
  </script>
</body>
</html>
"""

if sponsors_comment_index != -1:
    content = content[:sponsors_comment_index] + contributors_and_footer

with open(file_path, "w", encoding="utf-8") as f:
    f.write(content)
print("Successfully completed index.html premium overhaul!")
