import sys, json
from graphify.build import build_from_json
from graphify.cluster import score_all
from graphify.analyze import god_nodes, surprising_connections, suggest_questions
from graphify.report import generate
from pathlib import Path

extraction = json.loads(Path('graphify-out/.graphify_extract.json').read_text(encoding='utf-8'))
detection  = json.loads(Path('graphify-out/.graphify_detect.json').read_text(encoding='utf-8'))
analysis   = json.loads(Path('graphify-out/.graphify_analysis.json').read_text(encoding='utf-8'))

G = build_from_json(extraction)
communities = {int(k): v for k, v in analysis['communities'].items()}
cohesion = {int(k): v for k, v in analysis['cohesion'].items()}
tokens = {'input': extraction.get('input_tokens', 0), 'output': extraction.get('output_tokens', 0)}

# Map node id to label
node_labels = {n['id']: n.get('label', n['id']) for n in extraction['nodes']}

# Predefined labels for the top 20 communities
labels = {
    0: "Checkout & Installation Pipeline",
    1: "Database Core & Health",
    2: "Ledger Accounting & Transactions",
    3: "Plugin Lifecycle & Job Queue",
    4: "PII Protection & Device Pairing",
    5: "Messaging, Mail & SMS Gateways",
    6: "System Auditing & Logging Security",
    7: "Merchant Brands & Gateways Config",
    8: "Admin Auth & Theme Rendering",
    9: "Admin Session Management",
    10: "Security Helpers & Route Config",
    11: "Mobile Console Dashboard UI",
    12: "System Functional Specifications",
    13: "Filesystem & Render Helpers",
    14: "HTTP Request & Response Cache",
    15: "Gateway Webhook Callback Processing",
    16: "Plugin Loading & Discovery",
    17: "System Architecture Documentation",
    18: "Developer Settings & Currency Admin",
    19: "Hook & Filter Event Manager"
}

# Programmatically label other communities
for cid, nodes in communities.items():
    if cid not in labels:
        if nodes:
            names = []
            for n in nodes[:2]:
                lbl = node_labels.get(n, n)
                lbl = lbl.split('/')[-1].split('(')[0].strip('.')
                names.append(lbl)
            labels[cid] = f"Module: {' & '.join(names)}"
        else:
            labels[cid] = f"Community {cid}"

# Regenerate questions with real community labels
questions = suggest_questions(G, communities, labels)

report = generate(G, communities, cohesion, labels, analysis['gods'], analysis['surprises'], detection, tokens, '.', suggested_questions=questions)
Path('graphify-out/GRAPH_REPORT.md').write_text(report, encoding='utf-8')
Path('graphify-out/.graphify_labels.json').write_text(json.dumps({str(k): v for k, v in labels.items()}, indent=2), encoding='utf-8')
print('Report updated with community labels')
