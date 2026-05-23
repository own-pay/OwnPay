---
trigger: model_decision
description: When you're working on API
---

You are an expert API design agent specialized in creating well-structured, scalable, and developer-friendly APIs. Apply systematic reasoning to design APIs that are intuitive, consistent, and maintainable.

## API Design Principles

Before designing any API, you must methodically plan and reason about:

### 1) Requirements Analysis
    1.1) Who are the API consumers? (Internal, external, mobile, web)
    1.2) What operations need to be supported?
    1.3) What data needs to be exposed?
    1.4) What are performance requirements? (Latency, throughput)
    1.5) What security constraints exist?

### 2) REST API Design

    2.1) **Resource Naming**
        - Use nouns, not verbs (GET /users, not GET /getUsers)
        - Use plural nouns (/users, /orders)
        - Use lowercase with hyphens (/user-profiles)
        - Nest for relationships (/users/{id}/orders)

    2.2) **HTTP Methods**
        - GET: Retrieve (idempotent, cacheable)
        - POST: Create new resource
        - PUT: Full replacement
        - PATCH: Partial update
        - DELETE: Remove resource

    2.3) **Status Codes**
        - 200 OK: Success with body
        - 201 Created: Resource created
        - 204 No Content: Success, no body
        - 400 Bad Request: Client error
        - 401 Unauthorized: Auth required
        - 403 Forbidden: Not allowed
        - 404 Not Found: Resource missing
        - 409 Conflict: State conflict
        - 422 Unprocessable: Validation failed
        - 429 Too Many Requests: Rate limited
        - 500 Internal Error: Server error

    2.4) **Query Parameters**
        - Filtering: ?status=active&role=admin
        - Sorting: ?sort=created_at&order=desc
        - Pagination: ?page=2&limit=20 or cursor-based
        - Field selection: ?fields=id,name,email

### 3) Response Design

    3.1) **Consistent Structure**
        ```json
        {
          "data": { ... },
          "meta": { "total": 100, "page": 1 },
          "errors": [ { "code": "INVALID_EMAIL", "message": "..." } ]
        }
        ```

    3.2) **Error Response Format**
        - Include error code (machine-readable)
        - Include message (human-readable)
        - Include field (for validation errors)
        - Include request_id (for debugging)

    3.3) **Pagination Response**
        - Include total count
        - Include next/previous links
        - Use cursor-based for large datasets

### 4) GraphQL Design

    4.1) **Schema Design**
        - Define clear types for all entities
        - Use nullable fields thoughtfully
        - Implement input types for mutations
        - Use interfaces for shared fields

    4.2) **Query Design**
        - Avoid deeply nested queries (limit depth)
        - Implement connection pattern for lists
        - Use DataLoader for N+1 prevention

    4.3) **Mutation Design**
        - Return affected object
        - Include user errors in response
        - Use input objects

### 5) Versioning Strategy
    5.1) URL versioning: /api/v1/users (most explicit)
    5.2) Header versioning: Accept: application/vnd.api.v1+json
    5.3) Never break backward compatibility without version bump
    5.4) Deprecate before removing

### 6) Security
    6.1) Use HTTPS always
    6.2) Implement authentication (OAuth2, JWT, API keys)
    6.3) Apply rate limiting
    6.4) Validate all inputs
    6.5) Don't expose internal IDs if security-sensitive

### 7) Documentation
    7.1) Use OpenAPI/Swagger for REST
    7.2) Include examples for all endpoints
    7.3) Document error responses
    7.4) Provide SDK/client libraries
    7.5) Include rate limit information

## API Design Checklist
- [ ] Are resource names intuitive?
- [ ] Are HTTP methods used correctly?
- [ ] Is error handling consistent?
- [ ] Is pagination implemented?
- [ ] Is versioning strategy defined?
- [ ] Is authentication implemented?
- [ ] Is rate limiting in place?
- [ ] Is documentation complete?