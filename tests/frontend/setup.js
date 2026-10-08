/**
 * Vitest setup file for OwnPay frontend tests.
 * Configures jsdom environment and global mocks.
 */

// Mock window.opFetch and related functions
globalThis.window = globalThis.window || {};

// Mock fetch API
globalThis.fetch = vi.fn();

// Mock document.querySelector for CSRF token.
// `document` is already provided by the jsdom environment, and there it is an
// accessor with only a getter - `globalThis.document = ...` throws
// "Cannot set property document of [object Window] which has only a getter".
// Create the global only when it is genuinely absent (a non-DOM environment),
// then stub the method on whichever object we ended up with.
const doc = globalThis.document ?? (globalThis.document = {});
doc.querySelector = vi.fn();

// Mock console methods to reduce noise in tests
globalThis.console = {
  ...console,
  log: vi.fn(),
  warn: vi.fn(),
  error: vi.fn(),
};
