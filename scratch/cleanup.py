import json
from pathlib import Path
from datetime import datetime, timezone
from graphify.detect import save_manifest

# Save manifest for --update
detect = json.loads(Path('graphify-out/.graphify_detect.json').read_text(encoding='utf-8'))
save_manifest(detect['files'])

# Update cumulative cost tracker
extract = json.loads(Path('graphify-out/.graphify_extract.json').read_text(encoding='utf-8'))
input_tok = extract.get('input_tokens', 0)
output_tok = extract.get('output_tokens', 0)

cost_path = Path('graphify-out/cost.json')
if cost_path.exists():
    cost = json.loads(cost_path.read_text(encoding='utf-8'))
else:
    cost = {'runs': [], 'total_input_tokens': 0, 'total_output_tokens': 0}

cost['runs'].append({
    'date': datetime.now(timezone.utc).isoformat(),
    'input_tokens': input_tok,
    'output_tokens': output_tok,
    'files': detect.get('total_files', 0),
})
cost['total_input_tokens'] += input_tok
cost['total_output_tokens'] += output_tok
cost_path.write_text(json.dumps(cost, indent=2), encoding='utf-8')

print(f'This run: {input_tok:,} input tokens, {output_tok:,} output tokens')
print(f'All time: {cost["total_input_tokens"]:,} input, {cost["total_output_tokens"]:,} output ({len(cost["runs"])} runs)')

# Clean up temp files
import glob, os
temp_patterns = [
    'graphify-out/.graphify_detect.json',
    'graphify-out/.graphify_extract.json',
    'graphify-out/.graphify_ast.json',
    'graphify-out/.graphify_semantic.json',
    'graphify-out/.graphify_analysis.json',
    'graphify-out/.graphify_chunk_*.json',
    'graphify-out/.needs_update'
]
for pattern in temp_patterns:
    for f in glob.glob(pattern):
        try:
            os.remove(f)
            print(f"Removed temporary file: {f}")
        except Exception as e:
            print(f"Could not remove {f}: {e}")
