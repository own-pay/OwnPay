---
title: File Upload Security
impact: HIGH
tags: [file-upload, mime-types, path-traversal]
---

# File Upload Security

Check for secure file upload handling including type validation, size limits, and safe storage.

> **Related:** Path traversal is also covered in [injection-attacks.md](injection-attacks.md) and [broken-access-control.md](broken-access-control.md). XSS prevention is covered in [injection-attacks.md](injection-attacks.md) and [security-headers.md](security-headers.md).

## Why

- **Malware upload**: Attackers upload malicious files
- **Path traversal**: Overwrite system files
- **XSS via files**: SVG/HTML files execute scripts
- **Resource exhaustion**: Huge file uploads

## What to Check

**Vulnerability Indicators:**

- [ ] No file type validation
- [ ] No file size limits
- [ ] Original filename used for storage
- [ ] Files stored in web-accessible directory
- [ ] No MIME type validation
- [ ] Both extension and MIME type not checked

## Bad Patterns

```typescript
// Bad: No validation
async function uploadFile(req: Request): Promise<Response> {
  let formData = await req.formData();
  let file = formData.get("file") as File;

  // No type or size checking!
  await writeFile(`./uploads/${file.name}`, file);

  return new Response("Uploaded");
}

// Bad: Using original filename
await writeFile(`./public/uploads/${file.name}`, buffer);
// User could upload "../../etc/passwd"
```

## Good Patterns

```typescript
// Good: Comprehensive file validation
const ALLOWED_MIME_TYPES = ["image/jpeg", "image/png", "image/webp"];
const ALLOWED_EXTENSIONS = [".jpg", ".jpeg", ".png", ".webp"];
const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB

async function uploadFile(req: Request): Promise<Response> {
  let formData = await req.formData();
  let file = formData.get("file") as File;

  if (!file) {
    return new Response("No file provided", { status: 400 });
  }

  if (!ALLOWED_MIME_TYPES.includes(file.type)) {
    return new Response("Invalid file type", { status: 400 });
  }

  if (file.size > MAX_FILE_SIZE) {
    return new Response("File too large", { status: 400 });
  }

  let ext = path.extname(file.name).toLowerCase();
  if (!ALLOWED_EXTENSIONS.includes(ext)) {
    return new Response("Invalid file extension", { status: 400 });
  }

  // Generate safe random filename
  let randomName = crypto.randomBytes(16).toString("hex");
  let safeFilename = `${randomName}${ext}`;

  // Store outside web root
  let uploadPath = path.join(process.cwd(), "private", "uploads", safeFilename);

  let buffer = await file.arrayBuffer();
  await writeFile(uploadPath, Buffer.from(buffer));

  // Store metadata
  let uploadedFile = await db.file.create({
    data: {
      filename: safeFilename,
      originalName: file.name.slice(0, 255),
      mimeType: file.type,
      size: file.size,
      uploadedAt: new Date(),
    },
  });

  return Response.json(uploadedFile, { status: 201 });
}
```

## Rules

1. **Validate MIME type** - Check file.type
2. **Validate extension** - Check file extension
3. **Enforce size limits** - Prevent huge uploads
4. **Generate random filenames** - Don't use user input
5. **Store outside web root** - Not in public/
6. **Validate both MIME and extension** - Double check
