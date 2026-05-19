import json
from pathlib import Path
from graphify.detect import detect_incremental

result = detect_incremental(Path('.'))
print("new_total:", result.get("new_total"))
print("new_files categories:", list(result.get("new_files", {}).keys()))
for cat, files in result.get("new_files", {}).items():
    print(f"Category '{cat}' has {len(files)} files")
    for f in files[:5]:
        print("  -", f)
