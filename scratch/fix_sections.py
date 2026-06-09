import re

file_path = "public_html/public_html/index.html"

with open(file_path, "r", encoding="utf-8", errors="ignore") as f:
    content = f.read()

# Let's find "100% Self-Hosted" which is before the broken scroll indicator
trust_marker = '100% Self-Hosted'
scroll_marker = '<!-- Scroll indicator -->'
why_ownpay_marker = '<!-- ═══════════════════════════════════════════════════\n     WHY OWN PAY'

# Let's find the start of the trust chip that contains the broken indicator
# The broken indicator is inside the chip that has `d="M16.707 5.293a1 1 0 010 1.41`
# Let's find the index of the first `AGPL-3.0 Licensed` trust chip
start_pattern = r'<span class="flex items-center gap-1.5">\s*<svg[^>]*>\s*<path[^>]*d="M16\.707 5\.293a1 1 0 010 1\.41\s*<!-- Scroll indicator -->'

# Or even simpler, let's find:
# `<span class="flex items-center gap-1.5">\n            <svg class="w-3.5 h-3.5 text-emerald-500" fill="currentColor" viewBox="0 0 20 20">\n              <path fill-rule="evenodd"\n                d="M16.707 5.293a1 1 0 010 1.41      <!-- Scroll indicator -->`
# Let's search for the exact substring:
target_substring = 'd="M16.707 5.293a1 1 0 010 1.41      <!-- Scroll indicator -->'
start_idx = content.find(target_substring)

# We want to start the replacement from the opening `<span class="flex items-center gap-1.5">` immediately preceding this target_substring.
# We can find this by scanning backwards from start_idx for `<span class="flex items-center gap-1.5">`
span_start = content.rfind('<span class="flex items-center gap-1.5">', 0, start_idx)

# And we want to replace everything up to the section element right before "WHY OWN PAY"
# The section right before WHY OWN PAY is:
# `<section class="py-20 px-4 bg-slate-900 relative overflow-hidden">`
# Let's find the start of `<!-- ═══════════════════════════════════════════════════\n     WHY OWN PAY`
why_idx = content.find('WHY OWN PAY')
# Now, find the last `</section>` preceding `why_idx`
end_section_idx = content.rfind('</section>', 0, why_idx)
if end_section_idx != -1:
    end_section_idx += len('</section>')

if span_start != -1 and end_section_idx != -1:
    print(f"Found block: {span_start} to {end_section_idx}")
    
    # Replacement block:
    replacement = """<span class="flex items-center gap-1.5">
            <svg class="w-3.5 h-3.5 text-emerald-500" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd"
                d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                clip-rule="evenodd" />
            </svg>
            PHP 8.2+ Framework
          </span>
        </div>

        <!-- Scroll indicator -->
        <div class="mt-6 flex justify-center reveal" style="transition-delay:.4s">
          <a href="#how-it-works"
            class="scroll-indicator flex flex-col items-center gap-1 text-slate-300 hover:text-blue-400 transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
          </a>
        </div>
      </div>
    </section>

    <!-- ═══════════════════════════════════════════════════
       SPONSOR BY (ELITE SPONSOR) SECTION
   ════════════════════════════════════════════════════ -->
    <section class="py-8 bg-white border-y border-slate-100 relative z-10">
      <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex flex-col md:flex-row items-center justify-between gap-6 md:gap-12">
          <div class="text-center md:text-left max-w-sm">
            <h4 class="text-lg font-black text-slate-900 tracking-tight flex items-center justify-center md:justify-start gap-1.5">
              Backed by:
            </h4>
            <p class="text-slate-500 text-xs leading-relaxed mt-1">
              Thanks to all our Elite Sponsors for fueling OwnPay. Their premium support drives our open-source payment ecosystem forward.
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-6 justify-center">
            <a href="https://namepart.com" target="_blank" rel="noopener noreferrer" class="transition-all duration-300 hover:scale-105">
              <img src="assets/img/sponsors/namepart_logo.png" alt="Namepart Logo" class="h-9 w-auto max-w-[160px] object-contain" />
            </a>
            <!-- Commented Elite Sponsor Placeholders (Uncomment and edit as needed): -->
            <!--
            <a href="https://elitesponsor2.com" target="_blank" class="opacity-40 hover:opacity-100 transition-opacity">
              <img src="assets/img/sponsors/elitesponsor2.png" alt="Elite Sponsor 2" class="h-8 w-auto max-w-[130px] object-contain" />
            </a>
            -->
            <!--
            <a href="https://elitesponsor3.com" target="_blank" class="opacity-40 hover:opacity-100 transition-opacity">
              <img src="assets/img/sponsors/elitesponsor3.png" alt="Elite Sponsor 3" class="h-8 w-auto max-w-[130px] object-contain" />
            </a>
            -->
            <!--
            <a href="https://elitesponsor4.com" target="_blank" class="opacity-40 hover:opacity-100 transition-opacity">
              <img src="assets/img/sponsors/elitesponsor4.png" alt="Elite Sponsor 4" class="h-8 w-auto max-w-[130px] object-contain" />
            </a>
            -->
            <!--
            <a href="https://elitesponsor5.com" target="_blank" class="opacity-40 hover:opacity-100 transition-opacity">
              <img src="assets/img/sponsors/elitesponsor5.png" alt="Elite Sponsor 5" class="h-8 w-auto max-w-[130px] object-contain" />
            </a>
            -->
            <!--
            <a href="https://elitesponsor6.com" target="_blank" class="opacity-40 hover:opacity-100 transition-opacity">
              <img src="assets/img/sponsors/elitesponsor6.png" alt="Elite Sponsor 6" class="h-8 w-auto max-w-[130px] object-contain" />
            </a>
            -->
          </div>
        </div>
      </div>
    </section>

    <!-- ═══════════════════════════════════════════════════
       DEPLOY IN MINUTES — NEW SECTION (NON-TERMINAL)
   ════════════════════════════════════════════════════ -->
    <section class="py-24 px-4 bg-slate-900 relative overflow-hidden">
      <div class="absolute inset-0 pointer-events-none">
        <div class="absolute top-0 left-1/4 w-[400px] h-[400px] bg-blue-600 rounded-full blur-[140px] opacity-15"></div>
        <div class="absolute bottom-0 right-1/4 w-[400px] h-[400px] bg-cyan-500 rounded-full blur-[140px] opacity-10"></div>
      </div>
      <div class="relative z-10 max-w-5xl mx-auto">
        <div class="text-center mb-16 reveal">
          <span class="text-xs font-bold tracking-widest uppercase text-blue-400 mb-3 block">Deployment Workflow</span>
          <h2 class="text-3xl sm:text-4xl font-extrabold text-white leading-tight">Your Server. Your Rules. Your Money.</h2>
          <p class="text-slate-400 text-xs mt-3 max-w-md mx-auto">Zero vendor lock-in. Zero SaaS platform tax. Setup OwnPay in three seamless phases.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8 reveal" style="transition-delay:.1s">
          <!-- Step 1 -->
          <div class="bg-slate-800/40 border border-slate-700/60 rounded-2xl p-8 hover:border-blue-500/50 hover:bg-slate-800/80 transition-all duration-300 relative group">
            <div class="absolute top-6 right-8 text-5xl font-black text-slate-700/25 group-hover:text-blue-500/20 transition-colors select-none font-mono">01</div>
            <div class="w-12 h-12 rounded-xl bg-blue-500/10 border border-blue-500/30 flex items-center justify-center mb-6">
              <svg class="w-6 h-6 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
              </svg>
            </div>
            <h3 class="text-lg font-bold text-white mb-2">Download Package</h3>
            <p class="text-slate-400 text-xs leading-relaxed">
              Retrieve the production build ZIP package directly from our secure GitHub releases or waitlist coordinates.
            </p>
          </div>

          <!-- Step 2 -->
          <div class="bg-slate-800/40 border border-slate-700/60 rounded-2xl p-8 hover:border-cyan-500/50 hover:bg-slate-800/80 transition-all duration-300 relative group" style="transition-delay:.05s">
            <div class="absolute top-6 right-8 text-5xl font-black text-slate-700/25 group-hover:text-cyan-500/20 transition-colors select-none font-mono">02</div>
            <div class="w-12 h-12 rounded-xl bg-cyan-500/10 border border-cyan-500/30 flex items-center justify-center mb-6">
              <svg class="w-6 h-6 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
              </svg>
            </div>
            <h3 class="text-lg font-bold text-white mb-2">Upload &amp; Extract</h3>
            <p class="text-slate-400 text-xs leading-relaxed">
              Upload the distribution ZIP to your VPS or hosting server directory, and unzip it to make files accessible.
            </p>
          </div>

          <!-- Step 3 -->
          <div class="bg-slate-800/40 border border-slate-700/60 rounded-2xl p-8 hover:border-violet-500/50 hover:bg-slate-800/80 transition-all duration-300 relative group" style="transition-delay:.1s">
            <div class="absolute top-6 right-8 text-5xl font-black text-slate-700/25 group-hover:text-violet-500/20 transition-colors select-none font-mono">03</div>
            <div class="w-12 h-12 rounded-xl bg-violet-500/10 border border-violet-500/30 flex items-center justify-center mb-6">
              <svg class="w-6 h-6 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 7a2 2 0 012 2m-5-4v12m0 0l-3-3m3 3l3-3M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
              </svg>
            </div>
            <h3 class="text-lg font-bold text-white mb-2">Launch Installer</h3>
            <p class="text-slate-400 text-xs leading-relaxed">
              Navigate to the domain where OwnPay is extracted. The clean installer wizard automatically configures your workspace.
            </p>
          </div>
        </div>
      </div>
    </section>"""
    
    new_content = content[:span_start] + replacement + content[end_section_idx:]
    with open(file_path, "w", encoding="utf-8") as f:
        f.write(new_content)
    print("Success overhauling bento/indicator sections!")
else:
    print("Fail: indices not found!")
