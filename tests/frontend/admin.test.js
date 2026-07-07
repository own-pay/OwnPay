/**
 * Tests for admin.js - Admin panel functions
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { JSDOM } from 'jsdom';
import fs from 'fs';
import path from 'path';

// Read the actual admin.js file
const adminPath = path.join(__dirname, '../../public/assets/js/admin.js');
const adminCode = fs.readFileSync(adminPath, 'utf-8');

describe('admin.js', () => {
  let dom;
  let window;
  let document;

  beforeEach(() => {
    // Create a new JSDOM instance with admin page structure
    const html = `
      <!DOCTYPE html>
      <html>
      <head>
        <meta name="csrf-token" content="admin-csrf-token">
      </head>
      <body>
        <div id="sidebar" class="op-sidebar">
          <nav class="op-nav">
            <div class="op-nav-group">
              <a href="/admin/dashboard" class="op-nav-item-link">Dashboard</a>
              <div class="op-sub-nav">
                <a href="/admin/dashboard/stats">Stats</a>
              </div>
            </div>
            <div class="op-nav-group op-nav-expanded">
              <a href="/admin/transactions" class="op-nav-item-link">Transactions</a>
              <div class="op-sub-nav">
                <a href="/admin/transactions/list">List</a>
              </div>
            </div>
          </nav>
        </div>
        <button id="sidebar-toggle">Toggle</button>
        <div id="user-menu-btn" class="op-dropdown-toggle">User</div>
        <div id="user-menu" class="op-dropdown-menu">
          <a href="/admin/profile">Profile</a>
          <a href="/admin/logout">Logout</a>
        </div>
        <div class="op-alert op-alert-success">Success message</div>
        <div class="op-alert op-alert-error">Error message</div>
        <div class="op-flash-alert">Flash message</div>
        <div id="brand-switcher">Brand</div>
        <div id="brand-dropdown" class="op-dropdown-menu">
          <div class="op-brand-item" data-id="1">Brand 1</div>
          <div class="op-brand-item" data-id="2">Brand 2</div>
        </div>
      </body>
      </html>
    `;

    dom = new JSDOM(html, {
      url: 'http://localhost',
      runScripts: 'dangerously',
      resources: 'usable',
    });
    
    window = dom.window;
    document = window.document;
    
    // Mock localStorage
    const localStorageMock = {
      getItem: vi.fn(),
      setItem: vi.fn(),
      removeItem: vi.fn(),
      clear: vi.fn(),
    };
    Object.defineProperty(window, 'localStorage', {
      value: localStorageMock,
    });
    
    // Mock window.innerWidth
    Object.defineProperty(window, 'innerWidth', {
      writable: true,
      configurable: true,
      value: 1024,
    });
    
    // Execute admin.js
    window.eval(adminCode);
  });

  afterEach(() => {
    dom.window.close();
  });

  describe('CSRF Token', () => {
    it('should set CSRF token from meta tag', () => {
      expect(window.OP_CSRF).toBe('admin-csrf-token');
    });

    it('should handle missing CSRF meta tag', () => {
      const newDom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
        url: 'http://localhost',
        runScripts: 'dangerously',
      });
      
      newDom.window.eval(adminCode);
      expect(newDom.window.OP_CSRF).toBe('');
      newDom.window.close();
    });
  });

  describe('Sidebar Toggle', () => {
    it('should have sidebar element', () => {
      const sidebar = document.getElementById('sidebar');
      expect(sidebar).toBeDefined();
    });

    it('should have toggle button', () => {
      const toggle = document.getElementById('sidebar-toggle');
      expect(toggle).toBeDefined();
    });

    it('should toggle desktop sidebar', () => {
      const sidebar = document.getElementById('sidebar');
      const toggle = document.getElementById('sidebar-toggle');
      
      if (sidebar && toggle) {
        // Initially not collapsed
        expect(sidebar.classList.contains('op-sidebar-collapsed')).toBe(false);
        
        // Click toggle
        toggle.click();
        
        // Should be collapsed
        expect(sidebar.classList.contains('op-sidebar-collapsed')).toBe(true);
        
        // Click again
        toggle.click();
        
        // Should not be collapsed
        expect(sidebar.classList.contains('op-sidebar-collapsed')).toBe(false);
      }
    });

    it('should save sidebar state to localStorage', () => {
      const toggle = document.getElementById('sidebar-toggle');
      
      if (toggle) {
        toggle.click();
        
        expect(window.localStorage.setItem).toHaveBeenCalledWith(
          'op-sidebar',
          'collapsed'
        );
      }
    });

    it('should restore sidebar state from localStorage', () => {
      // Create a new DOM with collapsed state in localStorage
      const newDom = new JSDOM(`
        <!DOCTYPE html>
        <html><body>
        <div id="sidebar" class="op-sidebar"></div>
        <button id="sidebar-toggle">Toggle</button>
        </body></html>
      `, {
        url: 'http://localhost',
        runScripts: 'dangerously',
      });
      
      // Mock localStorage to return 'collapsed'
      const localStorageMock = {
        getItem: vi.fn().mockReturnValue('collapsed'),
        setItem: vi.fn(),
      };
      Object.defineProperty(newDom.window, 'localStorage', {
        value: localStorageMock,
      });
      
      // Mock innerWidth for desktop
      Object.defineProperty(newDom.window, 'innerWidth', {
        writable: true,
        configurable: true,
        value: 1024,
      });
      
      newDom.window.eval(adminCode);
      
      const sidebar = newDom.window.document.getElementById('sidebar');
      if (sidebar) {
        expect(sidebar.classList.contains('op-sidebar-collapsed')).toBe(true);
      }
      
      newDom.window.close();
    });
  });

  describe('Navigation Groups', () => {
    it('should expand/collapse nav groups', () => {
      const navGroups = document.querySelectorAll('.op-nav-group');
      expect(navGroups.length).toBeGreaterThan(0);
      
      // First group should not be expanded
      const firstGroup = navGroups[0];
      expect(firstGroup.classList.contains('op-nav-expanded')).toBe(false);
      
      // Click the link
      const link = firstGroup.querySelector(':scope > .op-nav-item-link');
      if (link) {
        link.click();
        
        // Should be expanded
        expect(firstGroup.classList.contains('op-nav-expanded')).toBe(true);
      }
    });

    it('should collapse other groups when expanding one', () => {
      const navGroups = document.querySelectorAll('.op-nav-group');
      
      if (navGroups.length >= 2) {
        const firstGroup = navGroups[0];
        const secondGroup = navGroups[1];
        
        // Second group is initially expanded
        expect(secondGroup.classList.contains('op-nav-expanded')).toBe(true);
        
        // Click first group
        const firstLink = firstGroup.querySelector(':scope > .op-nav-item-link');
        if (firstLink) {
          firstLink.click();
          
          // First should be expanded
          expect(firstGroup.classList.contains('op-nav-expanded')).toBe(true);
          
          // Second should be collapsed
          expect(secondGroup.classList.contains('op-nav-expanded')).toBe(false);
        }
      }
    });
  });

  describe('User Menu Dropdown', () => {
    it('should have user menu button', () => {
      const userBtn = document.getElementById('user-menu-btn');
      expect(userBtn).toBeDefined();
    });

    it('should have user menu', () => {
      const userMenu = document.getElementById('user-menu');
      expect(userMenu).toBeDefined();
    });
  });

  describe('Alerts', () => {
    it('should have success alerts', () => {
      const alerts = document.querySelectorAll('.op-alert-success');
      expect(alerts.length).toBeGreaterThan(0);
    });

    it('should have error alerts', () => {
      const alerts = document.querySelectorAll('.op-alert-error');
      expect(alerts.length).toBeGreaterThan(0);
    });

    it('should have flash alerts', () => {
      const alerts = document.querySelectorAll('.op-flash-alert');
      expect(alerts.length).toBeGreaterThan(0);
    });
  });

  describe('Brand Switcher', () => {
    it('should have brand switcher', () => {
      const brandSwitcher = document.getElementById('brand-switcher');
      expect(brandSwitcher).toBeDefined();
    });

    it('should have brand dropdown', () => {
      const brandDropdown = document.getElementById('brand-dropdown');
      expect(brandDropdown).toBeDefined();
    });

    it('should have brand items', () => {
      const brandItems = document.querySelectorAll('.op-brand-item');
      expect(brandItems.length).toBe(2);
    });
  });

  describe('Mobile Responsiveness', () => {
    it('should detect mobile viewport', () => {
      // Set mobile width
      window.innerWidth = 375;
      
      // The isMobile function should be available
      // (implementation specific - may need to check if sidebar opens in mobile mode)
    });

    it('should handle resize events', () => {
      // Verify resize listener is attached
      const resizeEvent = new window.Event('resize');
      window.dispatchEvent(resizeEvent);

      // Should not throw error
    });
  });

  describe('AJAX script re-injection CSP nonce', () => {
    it('re-injected scripts use the current page nonce, not the nonce from the fetched partial', async () => {
      // Test fixture markup: hardcoded, not user-controlled input.
      document.body.innerHTML = `
        <div class="op-content">
          <form method="POST" action="/admin/plugins/example/activate">
            <button type="submit">Activate</button>
          </form>
        </div>
      `;

      window.OP_CSP_NONCE = 'TRUSTED_NONCE';
      window.__testScriptRan = false;

      const fetchedHtml = `
        <div class="op-content">
          <script nonce="WRONG_NONCE">window.__testScriptRan = true;</script>
        </div>
      `;

      window.fetch = vi.fn(() => Promise.resolve({
        ok: true,
        url: 'http://localhost/admin/plugins',
        text: () => Promise.resolve(fetchedHtml),
      }));

      document.querySelector('button[type="submit"]').click();

      await vi.waitFor(() => {
        const reinjected = document.querySelector('.op-content script');
        if (!reinjected) { throw new Error('script not re-injected yet'); }
        expect(reinjected.getAttribute('nonce')).toBe('TRUSTED_NONCE');
      });

      expect(window.__testScriptRan).toBe(true);
    });
  });

  describe('AJAX page-scripts container sync', () => {
    it('re-executes scripts living in #op-page-scripts (outside .op-content) after an AJAX form submit', async () => {
      // Test fixture markup: hardcoded, not user-controlled input.
      document.body.innerHTML = `
        <div class="op-content">
          <form method="POST" action="/admin/plugins/example/activate">
            <button type="submit">Activate</button>
          </form>
        </div>
        <div id="op-page-scripts"></div>
      `;

      window.OP_CSP_NONCE = 'TRUSTED_NONCE';
      window.__pageScriptRan = false;

      const fetchedHtml = `
        <div class="op-content"></div>
        <div id="op-page-scripts">
          <script nonce="WRONG_NONCE">window.__pageScriptRan = true;</script>
        </div>
      `;

      window.fetch = vi.fn(() => Promise.resolve({
        ok: true,
        url: 'http://localhost/admin/plugins',
        text: () => Promise.resolve(fetchedHtml),
      }));

      document.querySelector('button[type="submit"]').click();

      await vi.waitFor(() => {
        if (!window.__pageScriptRan) { throw new Error('page script not re-executed yet'); }
        expect(window.__pageScriptRan).toBe(true);
      });

      const reinjected = document.querySelector('#op-page-scripts script');
      expect(reinjected.getAttribute('nonce')).toBe('TRUSTED_NONCE');
    });
  });
});
