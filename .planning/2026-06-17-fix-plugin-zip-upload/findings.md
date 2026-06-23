# Findings: Fix Plugin Zip Upload Bug

## 1. Problem Analysis
During plugin installation from an uploaded ZIP archive, the core framework fails with the message `No plugin.json found in ZIP` (or equivalent error).

The user reported two zip layouts:
1. `gateway_name.zip` -> `gateway_name` -> gateway files
2. `gateway_name.zip` -> gateway files

In both cases, the plugin validation fails.

## 2. Root Cause Discovery
Inside `src/Plugin/PluginInstaller.php`, the file checks for `plugin.json` to find the root directory of the plugin:
- `PluginInstaller::findPluginRoot()` checks:
  ```php
  if (file_exists($dir . '/plugin.json')) return $dir;
  // ...
  if (is_dir($subDir) && file_exists($subDir . '/plugin.json')) return $subDir;
  ```
- And `PluginInstaller::loadAndValidateManifest()` checks:
  ```php
  $pluginDir = $this->findPluginRoot($tempDir);
  if ($pluginDir === null) {
      return $this->fail('No plugin.json found in ZIP');
  }
  ```

However, across the entire OwnPay codebase:
- Plugin manifest files are standardly named `manifest.json` (as implemented in `PluginManifest.php`, `PluginLoader.php`, etc.), not `plugin.json`.
- There are no `plugin.json` files anywhere in the project or within standard plugins (e.g. `modules/gateways/bkash-api/manifest.json`).

Therefore, when the installer extracts a ZIP, it searches for `plugin.json` instead of `manifest.json`. Since it cannot find `plugin.json`, it fails with the error message indicating no plugin was found.

## 3. Zip Security Considerations
Additionally, `PluginInstaller::scanZipSecurity()` checks ZIP entries for backslashes:
```php
if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
    return $this->fail('ZIP contains path traversal attempt');
}
```
If a user compresses a plugin on Windows, some older zip archivers might store directory separators as backslashes (`\`). This would trigger a path traversal failure. We can make this check safer by normalizing paths before validating them (replacing `\` with `/`).

## 4. Test Coverage
Currently, the unit test suite does not directly cover `PluginInstaller::installFromZip()`. We will create a test case to cover this to prevent regressions in the future.
