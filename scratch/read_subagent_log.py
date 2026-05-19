import os
log_path = r"C:\Users\iamna\.gemini\antigravity\brain\e01b7bfd-ab43-4dd7-99ba-ce4242a346bb\.system_generated\logs\transcript.jsonl"
print("Exists:", os.path.exists(log_path))
if os.path.exists(log_path):
    print("Size:", os.path.getsize(log_path))
    with open(log_path, "r", encoding="utf-8") as f:
        lines = f.readlines()
        print("Total lines:", len(lines))
        print("Last 5 lines:")
        for line in lines[-5:]:
            print(line.strip())
