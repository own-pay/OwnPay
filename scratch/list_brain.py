import os
sub_dir = r"C:\Users\iamna\.gemini\antigravity\brain\e01b7bfd-ab43-4dd7-99ba-ce4242a346bb"
if os.path.exists(sub_dir):
    print("Contents of sub_dir:", os.listdir(sub_dir))
    # search recursively for files
    for root, dirs, files in os.walk(sub_dir):
        print(f"Walking {root} -> dirs: {dirs}, files: {files}")
else:
    print("sub_dir does not exist")
