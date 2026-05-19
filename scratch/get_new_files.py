import json
from pathlib import Path

result = json.loads(open("graphify-out/.graphify_incremental.json").read()) if Path("graphify-out/.graphify_incremental.json").exists() else {}
new_files = result.get("new_files", {})
for category, files in new_files.items():
    if files:
        print(f"{category}:")
        for f in files:
            print("  ", f)
