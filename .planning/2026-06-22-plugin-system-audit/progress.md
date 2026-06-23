# Progress log — Plugin system audit

## 2026-06-22
- Mapped src/Plugin/** + tests. Read: PluginInterface, Capability, PluginInstaller, PluginManifest,
  config/hooks.php, EventManager, PluginLoader.
- Established central tension: sandboxed plugin model (static scanner) vs WP full-trust ambition.
- Wrote task_plan.md + findings.md.
- NEXT: PluginSandbox (dangerous-fn list), PluginManager, PluginController, PluginRegistry; verify hook
  firings + manifest routes/menu/cron consumption + multi-file autoloading.
