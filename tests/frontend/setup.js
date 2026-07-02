/**
 * Vitest setup file for OwnPay frontend tests.
 * Configures jsdom environment and global mocks.
 */

// Mock window.opFetch and related functions
globalThis.window = globalThis.window || {};

// Mock fetch API
globalThis.fetch = vi.fn();

// Mock document.querySelector for CSRF token
globalThis.document = globalThis.document || {};
globalThis.document.querySelector = vi.fn();

// Mock console methods to reduce noise in tests
globalThis.console = {
  ...console,
  log: vi.fn(),
  warn: vi.fn(),
  error: vi.fn(),
};
