<?php
declare(strict_types=1);
include TEMPLATE_PATH . '/header.php';
?>

<!-- ─── HERO ─── -->
<section class="section hero">
    <!-- Pulse grid background -->
    <div style="position: absolute; inset: 0; background: radial-gradient(circle at 50% 50%, rgba(212, 175, 55, 0.04), transparent 70%); pointer-events: none; z-index: 1;"></div>
    
    <div class="container hero-content reveal">
        <!-- Pre-headline badge -->
        <div class="badge mb-6">
            <span class="pulse-dot"></span>
            <?php echo htmlspecialchars($settings['hero_badge'] ?? 'Open Source · Self-Hosted · Payment Infrastructure'); ?>
        </div>

        <!-- Main Headline -->
        <h1 class="mb-6">
            <?php echo htmlspecialchars($settings['hero_headline'] ?? 'Self-Hosted Payments. Zero Platform Tax.'); ?>
        </h1>

        <!-- Sub-headline -->
        <p class="mb-8" style="max-width: 700px; margin-left: auto; margin-right: auto;">
            <?php echo htmlspecialchars($settings['hero_subheadline'] ?? 'OwnPay is the enterprise-grade, open-source payment gateway automation platform. Automate transactions on your own server, maintain absolute data privacy, and extend features via a universal plugin engine.'); ?>
        </p>

        <!-- CTA row -->
        <div class="hero-cta">
            <!-- Waitlist Form (CTA 1) -->
            <form id="waitlist-form" class="hero-form">
                <input id="waitlist-email" type="email" placeholder="your@email.com" required>
                <button type="submit" id="waitlist-btn" class="btn btn-primary">
                    <span><?php echo htmlspecialchars($settings['hero_cta_subscribe'] ?? 'Get Early Access'); ?></span>
                </button>
            </form>

            <div id="waitlist-success" class="badge badge-success mb-6" style="display: none; padding: 12px 24px; text-transform: none; font-size: 0.9rem;">
                <!-- Success message injected here -->
            </div>
            
            <div id="waitlist-error" class="badge badge-planned mb-6" style="display: none; padding: 12px 24px; text-transform: none; font-size: 0.9rem; color: #ef4444; border-color: rgba(239, 68, 68, 0.2); background: rgba(239, 68, 68, 0.05);">
                <span id="error-msg"></span>
            </div>

            <!-- Links (CTA 2 & 3) -->
            <div class="hero-links">
                <a href="<?php echo htmlspecialchars($settings['github_repo_url'] ?? 'https://github.com/own-pay/ownpay'); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary">
                    <i class="ph ph-github-logo" style="font-size: 1.1rem; vertical-align: middle;"></i>
                    <span class="github-stars-badge"><?php echo htmlspecialchars($settings['github_stars_cached'] ?? '142'); ?> stars</span>
                </a>
                
                <a href="/donate" rel="nofollow noopener noreferrer" class="btn btn-ghost">
                    <i class="ph ph-heart-straight" style="font-size: 1.1rem; vertical-align: middle; color: #ef4444;"></i>
                    <?php echo htmlspecialchars($settings['hero_cta_sponsor'] ?? 'Become a Sponsor'); ?>
                </a>
            </div>
        </div>
    </div>
</section>

<!-- ─── BACKED BY ─── -->
<section class="backed-by">
    <div class="container backed-content">
        <span class="backed-label">Supported by the community:</span>
        <div class="backed-logos">
            <?php foreach ($eliteSponsors as $es): ?>
                <?php if (strtolower($es['slug']) !== 'namepart') continue; ?>
                <a href="<?php echo htmlspecialchars($es['website_url']); ?>" target="_blank" rel="nofollow noopener noreferrer">
                    <img src="/<?php echo htmlspecialchars($es['logo_path']); ?>" alt="<?php echo htmlspecialchars($es['name']); ?> Logo">
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
 
<!-- ─── DEPLOYMENT FLOW DIAGRAM ─── -->
<section class="section section-alt">
    <div class="container text-center">
        <div class="section-header reveal">
            <span class="badge mb-4">Architecture Flow</span>
            <h2>Your Server. Your Rules. Your Money.</h2>
            <p>Zero vendor lock-in. Zero platform tax. OwnPay runs entirely on your own infrastructure.</p>
        </div>

        <!-- SVG Flow diagram -->
        <div class="deployment-flow reveal">
            <?php echo file_get_contents(PUBLIC_PATH . '/assets/img/flow.svg'); ?>
        </div>

        <div class="why-grid reveal" style="margin-top: var(--space-12);">
            <div class="feature-card">
                <div class="feature-icon"><i class="ph ph-server"></i></div>
                <h3>1. Download Package</h3>
                <p>Retrieve the official production build directly from our waitlist update channels.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><i class="ph ph-folder-open"></i></div>
                <h3>2. Upload &amp; Unzip</h3>
                <p>Upload the distribution ZIP to your VPS host or server folder and unzip the core package.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><i class="ph ph-planet"></i></div>
                <h3>3. Visit Domain</h3>
                <p>Visit the domain where OwnPay is unzipped, and the clean installer wizard automatically boots.</p>
            </div>
        </div>
    </div>
</section>

<!-- ─── WHY OWNPAY ─── -->
<section class="section" id="how-it-works">
    <div class="container">
        <div class="section-header reveal">
            <span class="badge mb-4">Platform Pillars</span>
            <h2>Why Not Just Stripe / PayPal?</h2>
            <p>Fintech giants harvest your customer logs and tax your revenue. Regain complete control.</p>
        </div>

        <div class="why-grid reveal">
            <!-- Pillar 1 -->
            <div class="feature-card">
                <div class="feature-icon"><i class="ph ph-shield-check"></i></div>
                <h3>Full Data Ownership</h3>
                <p>No third-party harvesting of your customer or transaction logs. Your data remains on your server.</p>
            </div>

            <!-- Pillar 2 -->
            <div class="feature-card">
                <div class="feature-icon"><i class="ph ph-link-break"></i></div>
                <h3>Zero Vendor Lock-in</h3>
                <p>Switch VPS hosts, domains, or payment adapters instantly. You own your processing system.</p>
            </div>

            <!-- Pillar 3 -->
            <div class="feature-card">
                <div class="feature-icon"><i class="ph ph-code"></i></div>
                <h3>Custom Lightweight MVC</h3>
                <p>Zero dead code. Fully auditable surface designed strictly for sovereign payment gateway operations.</p>
            </div>

            <!-- Pillar 4 -->
            <div class="feature-card">
                <div class="feature-icon"><i class="ph ph-scales"></i></div>
                <h3>AGPL-3.0 Licensed</h3>
                <p>Community first. Forever open source, transparent, and collaborative under a free software model.</p>
            </div>

            <!-- Pillar 5 -->
            <div class="feature-card">
                <div class="feature-icon"><i class="ph ph-tree-structure"></i></div>
                <h3>Multi-Gateway Adapted</h3>
                <p>Process bkash, SSLCommerz, Stripe, and manual payments under one single white-label roof.</p>
            </div>
        </div>
    </div>
</section>

<!-- ─── SPONSORS BENTO GRID ─── -->
<section class="section section-alt">
    <div class="container">
        <div class="section-header reveal">
            <span class="badge mb-4">Showcase</span>
            <h2>Sponsors Fueling OwnPay</h2>
            <p>Click on any sponsor logo to visit their website and learn more about their backing.</p>
        </div>

        <div class="bento-grid reveal">
            <?php foreach ($eliteSponsors as $es): ?>
                <a href="<?php echo htmlspecialchars($es['website_url']); ?>" target="_blank" rel="nofollow noopener noreferrer" class="bento-cell">
                    <img src="/<?php echo htmlspecialchars($es['logo_path']); ?>" alt="<?php echo htmlspecialchars($es['name']); ?>">
                </a>
            <?php endforeach; ?>
            <?php foreach ($regularSponsors as $rs): ?>
                <a href="<?php echo htmlspecialchars($rs['website_url']); ?>" target="_blank" rel="nofollow noopener noreferrer" class="bento-cell">
                    <img src="/<?php echo htmlspecialchars($rs['logo_path']); ?>" alt="<?php echo htmlspecialchars($rs['name']); ?>">
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>


<!-- ─── ROADMAP TIMELINE ─── -->
<section class="section" id="roadmap">
    <div class="container">
        <div class="section-header reveal">
            <span class="badge mb-4">Milestones</span>
            <h2>Development Roadmap</h2>
            <p>Our progressive roadmap towards public launch and full fintech ecosystem stabilization.</p>
        </div>

        <div class="roadmap-timeline reveal">
            <!-- Milestone 1 -->
            <div class="roadmap-item completed">
                <div class="roadmap-marker"></div>
                <div class="roadmap-card">
                    <div class="roadmap-card-header">
                        <h3>Core MVC Framework</h3>
                        <span class="badge badge-success">Completed</span>
                    </div>
                    <p>Designed the custom reflection dependency injection container, database transaction wrappers, and white-label multi-brand resolver.</p>
                </div>
            </div>

            <!-- Milestone 2 -->
            <div class="roadmap-item completed">
                <div class="roadmap-marker"></div>
                <div class="roadmap-card">
                    <div class="roadmap-card-header">
                        <h3>Plugin System Architecture</h3>
                        <span class="badge badge-success">Completed</span>
                    </div>
                    <p>Implemented sandboxed ZIP-plugin loaders, event hooks triggers, and granular resource capability matrices.</p>
                </div>
            </div>

            <!-- Milestone 3 -->
            <div class="roadmap-item completed">
                <div class="roadmap-marker"></div>
                <div class="roadmap-card">
                    <div class="roadmap-card-header">
                        <h3>Multi-Gateway Abstraction Layer</h3>
                        <span class="badge badge-success">Completed</span>
                    </div>
                    <p>Standardized unified Adapters for automated local and international gateways under a single double-entry bookkeeping ledger.</p>
                </div>
            </div>

            <!-- Milestone 4 -->
            <div class="roadmap-item in_progress">
                <div class="roadmap-marker"></div>
                <div class="roadmap-card">
                    <div class="roadmap-card-header">
                        <h3>Security Hardening &amp; Audit</h3>
                        <span class="badge badge-progress">In Progress</span>
                    </div>
                    <p>Hardening system inputs, running threat modeling, compliance audits, and fixing edge case validation flaws.</p>
                </div>
            </div>

            <!-- Milestone 5 -->
            <div class="roadmap-item">
                <div class="roadmap-marker"></div>
                <div class="roadmap-card">
                    <div class="roadmap-card-header">
                        <h3>Public Beta v0.1.0</h3>
                        <span class="badge badge-planned">Planned</span>
                    </div>
                    <p>Complete open-source release on GitHub with installer wizards, docs site, and developer guides.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ─── OPEN SOURCE / GITHUB STARS ─── -->
<section class="section section-alt">
    <div class="container text-center">
        <div class="section-header reveal" style="margin-bottom: var(--space-8);">
            <span class="badge mb-4">Community Proof</span>
            <h2>Free. Open. Yours.</h2>
            <p>OwnPay is built by developers, for developers. Check our repository on GitHub.</p>
        </div>

        <div class="reveal" style="margin-bottom: var(--space-8);">
            <a href="<?php echo htmlspecialchars($settings['github_repo_url'] ?? 'https://github.com/own-pay/ownpay'); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary" style="font-size: 1.1rem; padding: 16px 32px;">
                <i class="ph ph-github-logo" style="font-size: 1.3rem; vertical-align: middle;"></i>
                View on GitHub
            </a>
        </div>

        <div style="display: flex; justify-content: center; gap: var(--space-8);" class="reveal">
            <div>
                <h3 class="github-stars-badge" style="font-size: 2.5rem; color: var(--color-primary);"><?php echo htmlspecialchars($settings['github_stars_cached'] ?? '142'); ?> stars</h3>
                <p style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em;">GitHub Stars</p>
            </div>
            <div style="border-left: 1px solid var(--color-border);"></div>
            <div>
                <h3 style="font-size: 2.5rem; color: var(--color-primary);">AGPL-3.0</h3>
                <p style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em;">Community License</p>
            </div>
        </div>
    </div>
</section>

<!-- ─── FAQ ACCORDIONS ─── -->
<section class="section" id="faq">
    <div class="container">
        <div class="section-header reveal">
            <span class="badge mb-4">FAQ</span>
            <h2>Frequently Asked Questions</h2>
            <p>Find quick answers to common architectural and platform launch questions.</p>
        </div>

        <div class="faq-accordion reveal">
            <!-- Q1 -->
            <div class="faq-item">
                <button class="faq-header">
                    <span>What is the current project status?</span>
                    <span class="faq-icon">+</span>
                </button>
                <div class="faq-content">
                    <p>OwnPay has completed its core development phase. The platform is currently undergoing bug fixing and final validation before the Public Beta v0.1.0 release.</p>
                </div>
            </div>

            <!-- Q2 -->
            <div class="faq-item">
                <button class="faq-header">
                    <span>When will OwnPay officially release?</span>
                    <span class="faq-icon">+</span>
                </button>
                <div class="faq-content">
                    <p>The Public Beta v0.1.0 release is coming soon. We do not commit to a specific date — the release will happen when the quality bar is met, not when a calendar says so. Star the repository to get notified the instant it drops.</p>
                </div>
            </div>

            <!-- Q3 -->
            <div class="faq-item">
                <button class="faq-header">
                    <span>Why is the release taking longer than expected?</span>
                    <span class="faq-icon">+</span>
                </button>
                <div class="faq-content">
                    <p>Because we refuse to release something that isn't secure. OwnPay handles real financial transactions — a rushed release with unresolved vulnerabilities would be a disservice to the community. The additional time is invested in thorough security hardening, bug fixing, and edge case validation. Quality over speed. Always.</p>
                </div>
            </div>

            <!-- Q4 -->
            <div class="faq-item">
                <button class="faq-header">
                    <span>Why was a custom framework built instead of using Laravel or Symfony?</span>
                    <span class="faq-icon">+</span>
                </button>
                <div class="faq-content">
                    <p>OwnPay was architected around very specific requirements that off-the-shelf frameworks don't solve cleanly — primarily around multi-brand domain isolation, a sandboxed plugin execution model, and a domain-specific hook engine. Using a full framework would mean fighting against its conventions rather than leveraging them. The custom foundation gives us full control over the boot pipeline, zero dead code, and a security surface that we own completely. It's more work upfront, and the right call long-term.</p>
                </div>
            </div>

            <!-- Q5 -->
            <div class="faq-item">
                <button class="faq-header">
                    <span>Do you accept sponsors?</span>
                    <span class="faq-icon">+</span>
                </button>
                <div class="faq-content">
                    <p>Yes. OwnPay welcomes sponsors who align with the open-source mission. If you're interested in supporting the project and gaining visibility in the community, visit ownpay.org/donate or reach out at ping@ownpay.org.</p>
                </div>
            </div>

            <!-- Q6 -->
            <div class="faq-item">
                <button class="faq-header">
                    <span>Do you accept donations?</span>
                    <span class="faq-icon">+</span>
                </button>
                <div class="faq-content">
                    <p>Yes. Every contribution, however small, helps keep the project moving. Donations go directly toward infrastructure costs, developer time, and security tooling. [Link: <a href="/donate">Donate to OwnPay</a>]</p>
                </div>
            </div>

            <!-- Q7 -->
            <div class="faq-item">
                <button class="faq-header">
                    <span>Is OwnPay production-ready?</span>
                    <span class="faq-icon">+</span>
                </button>
                <div class="faq-content">
                    <p>The platform is approaching its Public Beta v0.1.0 release. For production deployments, we recommend waiting for the official beta tag, which will include installation documentation, migration tooling, and a full security disclosure report. Star the repository to get notified.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ─── CONTRIBUTORS & SPONSORS COMBINED SHOWCASE ─── -->
<section class="section section-alt">
    <div class="container">
        <div class="section-header reveal">
            <span class="badge mb-4">Core Team</span>
            <h2>Supporters &amp; Contributors Showcase</h2>
            <p>Hover over any team member circle to view their contributions and profile links.</p>
        </div>

        <div class="contributors-grid reveal">
            <?php foreach ($contributors as $c): ?>
                <div class="contributor-wrapper">
                    <?php if (!empty($c['avatar_path']) && file_exists(PUBLIC_PATH . '/' . $c['avatar_path'])): ?>
                        <img src="/<?php echo htmlspecialchars($c['avatar_path']); ?>" alt="<?php echo htmlspecialchars($c['name']); ?>" class="contributor-avatar">
                    <?php else: ?>
                        <!-- Initials-based SVG Fallback generated inline -->
                        <div class="contributor-fallback">
                            <?php 
                                $words = explode(' ', $c['name']);
                                $initials = '';
                                foreach ($words as $w) {
                                    $initials .= strtoupper(substr($w, 0, 1));
                                }
                                echo htmlspecialchars(substr($initials, 0, 2));
                            ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Floating tooltip card anchored to the avatar -->
                    <div class="tooltip">
                        <h4><?php echo htmlspecialchars($c['name']); ?></h4>
                        <span><?php echo htmlspecialchars($c['role']); ?></span>
                        <p style="margin-bottom: var(--space-2);"><?php echo htmlspecialchars($c['description'] ?? ''); ?></p>
                        <?php if (!empty($c['profile_url'])): ?>
                            <a href="<?php echo htmlspecialchars($c['profile_url']); ?>" target="_blank" rel="noopener noreferrer" style="font-size: 0.75rem; font-weight: 700; color: var(--color-primary);">
                                View Profile &rarr;
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php
include TEMPLATE_PATH . '/footer.php';
?>
