// ownpay-guard — blocks file-modifying agent tools from touching paths that
// must never be edited in this repository: vendor/ (tracked, must match
// composer.lock), node_modules/ (local install), .env (secrets), and the
// storage/ runtime tree (logs, sessions, cache — local state, gitignored
// except .gitkeep/.htaccess placeholders).
//
// Why a hook on top of opencode.json's permission.edit denies: the permission
// table is the primary control, but pattern-vs-absolute-path matching is not
// documented, so this hook is the enforcement backstop. Blocking is done by
// throwing inside tool.execute.before, which is the documented opencode
// ".env protection" pattern (https://opencode.ai/docs/plugins).
//
// Matching is by path segment, not substring, so "/home/me/myvendor/x" does
// not match the "vendor" rule while "vendor/x", "/repo/vendor/x" and Windows
// "C:\repo\vendor\x" all do. Backslashes are normalized first.
//
// Non-goal: this hook does not parse `bash` commands for redirects such as
// `echo x > vendor/file`. The permission layer already governs file tools, and
// guessing shell grammar would be unreliable; bash restrictions belong in
// permission.bash rules if ever needed.

const PROTECTED_DIRS = ["vendor", "node_modules", "storage"];
const PROTECTED_FILE = ".env";

function pathSegments(filePath) {
  const normalized = String(filePath).replace(/\\/g, "/");
  return normalized.split("/").filter(function (segment) {
    return segment.length > 0;
  });
}

function isProtectedPath(filePath) {
  const segments = pathSegments(filePath);
  if (segments.indexOf(PROTECTED_FILE) !== -1) {
    return true;
  }
  for (let i = 0; i < segments.length; i++) {
    if (PROTECTED_DIRS.indexOf(segments[i]) !== -1) {
      return true;
    }
  }
  return false;
}

export default async function ownpayGuard() {
  return {
    "tool.execute.before": async (input, output) => {
      if (input.tool !== "edit" && input.tool !== "write" && input.tool !== "patch") {
        return;
      }
      const filePath = output && output.args ? output.args.filePath : undefined;
      if (typeof filePath !== "string" || filePath.length === 0) {
        return;
      }
      if (isProtectedPath(filePath)) {
        throw new Error(
          "ownpay-guard: refusing to modify protected path '" + filePath +
            "'. vendor/, node_modules/, .env and storage/ are not editable by agents."
        );
      }
    },
  };
}
