# Design Audit & Discovery Findings

## Theme Tokens & Variables
The OwnPay admin dashboard uses CSS variables to handle light/dark mode styling dynamically:
- `--op-bg`: Main page background.
- `--op-bg-card`: Card background (`#fff` in light, `#1a1a2e` in dark).
- `--op-bg-input`: Sub-elements / inputs (`#f1f5f9` in light, `#16213e` in dark).
- `--op-border`: Border elements (`#e2e8f0` in light, `--op-border` in dark).
- `--op-text`: Standard text.
- `--op-text-muted`: Secondary text.
- `--op-primary`: Accent violet color (`#6C5CE7`).

## Current Design Pain Points
1. **Plain Accordions and Headers:** Collapsible elements (release notes) look like simple gray boxes, lacking a premium interactive feel.
2. **Version Comparison Banner:** Simple dashed borders and flat colors do not project a secure, robust product update environment.
3. **Empty Space Discrepancy:** The "Updater Settings" card contains a single line and leaves a huge, empty block. We should adjust columns or make components more cohesive.
4. **Environment Metrics Grid:** The metrics look like generic text blocks inside gray background divs. They should look like high-tech telemetry readouts.
5. **Color & Typography Contrast:** In light mode, some elements lack crisp contrast, making readability slightly strained.
6. **No Micro-animations:** The interface feels static. It needs smooth hover effects, active stepper pulsing, and refined transition animations.
