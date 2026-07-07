import globals from "globals";
import eslintPluginJsonc from "eslint-plugin-jsonc";

export default [
  // Global ignores (applies to all files and blocks)
  {
    ignores: [
      "**/node_modules/**",
      "**/vendor/**",
      "composer.lock",
      "package-lock.json",
      "graphify-out/**/*",
      "update/**/*",
      "mobile-app/**/*",
      "landing_page/**/*",
      "cli/**/*",
      "scratch/**/*",
      ".antigravitycli/**/*",
      ".planning/**/*",
      ".phpunit.cache/**/*"
    ]
  },

  // 1. Lint JavaScript files
  {
    files: ["public/assets/js/**/*.js"],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: "module",
      globals: {
        ...globals.browser,
        ...globals.jquery,
        ...globals.node,
      },
    },
    rules: {
      "no-unused-vars": "warn",
      "no-console": "off",
      "eqeqeq": ["error", "always"],
      "curly": ["error", "all"],
      "semi": ["error", "always"],
      "quotes": ["error", "double", { "avoidEscape": true }],
    },
  },

  // 2. Lint JSON files
  ...eslintPluginJsonc.configs["flat/recommended-with-json"],
  {
    files: ["**/*.json"],
    rules: {
      "jsonc/no-comments": "error",
      "jsonc/indent": ["error", 2],
    },
  },
];
