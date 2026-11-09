import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'jsdom',
    globals: true,
    include: ['tests/frontend/**/*.test.js'],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'json', 'html', 'cobertura'],
      include: ['public/assets/js/**/*.js'],
      exclude: ['node_modules/', 'tests/'],
    },
    setupFiles: ['tests/frontend/setup.js'],
  },
});
