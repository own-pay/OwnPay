export default {
  extends: ["stylelint-config-standard"],
  rules: {
    "color-hex-length": "short",
    "declaration-empty-line-before": "never",
    "selector-class-pattern": null,
    "no-descending-specificity": null,
    "media-feature-range-notation": null,
    "keyframes-name-pattern": null,
    "declaration-block-single-line-max-declarations": null,
    "no-duplicate-selectors": null,
    // stylelint 17 changed `ignoreProperties` to match the FULL prefixed
    // property name (e.g. "-webkit-backdrop-filter") instead of the
    // unprefixed name (e.g. "backdrop-filter"). The OwnPay CSS still
    // ships vendor prefixes for legitimate legacy browser support
    // (-webkit-backdrop-filter for Safari < 18, -webkit-text-size-adjust
    // for iOS Safari, -webkit-user-select for Safari, -webkit-overflow-
    // scrolling for iOS momentum scroll, -webkit-font-smoothing /
    // -moz-osx-font-smoothing for macOS font rendering, -webkit-line-
    // clamp / -webkit-box-orient for legacy flexbox line clamping,
    // -webkit-mask-image for Safari). A regex ignore matches every
    // current and future prefixed property without listing each one.
    "property-no-vendor-prefix": [
      true,
      {
        ignoreProperties: ["/^-(webkit|moz|ms|o)-/"]
      }
    ],
    // stylelint-config-standard@40 introduced these new rules. They are
    // valid modern CSS guidance but break on legitimate legacy browser
    // support code (the `word-break: break-word` legacy fallback that
    // still has no direct modern equivalent, the deprecated `clip`
    // property used for screen-reader-only elements, and `grid-gap`
    // which is still widely supported). Disabling them here keeps the
    // linter useful for the rules that do apply to this codebase
    // without forcing churn on working CSS. Revisit when the legacy
    // fallbacks are no longer needed.
    "color-function-alias-notation": null,
    "declaration-property-value-keyword-no-deprecated": null,
    "property-no-deprecated": null
  },
  ignoreFiles: [
    "node_modules/**/*",
    "vendor/**/*",
    "graphify-out/**/*"
  ]
};
