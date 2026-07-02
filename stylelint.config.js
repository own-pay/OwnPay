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
    "property-no-vendor-prefix": [
      true,
      {
        ignoreProperties: ["backdrop-filter", "text-size-adjust", "user-select"]
      }
    ]
  },
  ignoreFiles: [
    "node_modules/**/*",
    "vendor/**/*",
    "graphify-out/**/*"
  ]
};
