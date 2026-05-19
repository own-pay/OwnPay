import os
print("GEMINI_API_KEY:", "set" if os.environ.get("GEMINI_API_KEY") else "NOT set")
print("GOOGLE_API_KEY:", "set" if os.environ.get("GOOGLE_API_KEY") else "NOT set")
