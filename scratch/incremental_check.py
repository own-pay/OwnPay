import json
from graphify.detect import detect_incremental
from pathlib import Path

try:
    result = detect_incremental(Path('.'))
    print(json.dumps(result, indent=2))
except Exception as e:
    print("Error:", e)
