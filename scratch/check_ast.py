import json
with open("graphify-out/.graphify_ast.json", "r", encoding="utf-8") as f:
    data = json.load(f)
print("Nodes:", len(data.get("nodes", [])))
print("Edges:", len(data.get("edges", [])))
