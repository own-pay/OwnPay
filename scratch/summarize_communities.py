import json
from pathlib import Path

analysis = json.loads(Path('graphify-out/.graphify_analysis.json').read_text(encoding='utf-8'))
extraction = json.loads(Path('graphify-out/.graphify_extract.json').read_text(encoding='utf-8'))

# Map node id to label
node_labels = {n['id']: n.get('label', n['id']) for n in extraction['nodes']}
node_files = {n['id']: n.get('source_file', '') for n in extraction['nodes']}

communities = analysis['communities']
# Sort communities by number of nodes
sorted_communities = sorted(communities.items(), key=lambda x: len(x[1]), reverse=True)

print(f"Total communities: {len(sorted_communities)}")
# Print details of the 20 largest communities
for i, (cid, nodes) in enumerate(sorted_communities[:20]):
    print(f"\n[{i}] Community {cid} (Size: {len(nodes)}):")
    for n in nodes[:10]:
        print(f"  - {node_labels.get(n, n)} ({node_files.get(n, '')})")
    if len(nodes) > 10:
        print(f"  ... and {len(nodes) - 10} more")
