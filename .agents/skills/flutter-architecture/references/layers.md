# Architecture Layers

Detailed guide to layer responsibilities and interactions in Flutter apps.

## Layer Overview

```
┌─────────────────────────────────────┐
│           UI Layer                  │
│  ┌────────────┐  ┌─────────────┐ │
│  │   Views    │  │ ViewModels  │ │
│  └────────────┘  └─────────────┘ │
└─────────────────────────────────────┘
                  ↓
┌─────────────────────────────────────┐
│      Domain Layer (Optional)        │
│       ┌─────────────────────┐      │
│       │     Use-cases       │      │
│       └─────────────────────┘      │
└─────────────────────────────────────┘
                  ↓
┌─────────────────────────────────────┐
│          Data Layer                │
│  ┌────────────┐  ┌─────────────┐ │
│  │Repositories │  │  Services   │ │
│  └────────────┘  └─────────────┘ │
└─────────────────────────────────────┘
```

## UI Layer

Responsibility: Interact with user, display data, receive input.

### Views
**What they do:**
- Compose widgets to present data
- Handle user interactions (taps, form inputs)
- Pass events to ViewModel
- Re-render when ViewModel state changes

**What they don't do:**
- Business logic
- Data transformation
- State persistence
- API calls

**Logic allowed in Views:**
- Simple if-statements for conditional rendering
- Animation logic
- Layout logic (responsive design, orientation)
- Simple routing

### ViewModels
**What they do:**
- Transform repository data into UI state
- Maintain UI state (survives configuration changes)
- Expose commands for user actions
- Aggregate data from multiple repositories

**What they don't do:**
- Direct UI manipulation
- Platform API calls
- File I/O
- Network requests directly (use repositories)

**State management:**
- Use ChangeNotifier for simple apps
- Consider Provider, Riverpod, or Bloc for complex apps
- Expose state as Streams or ChangeNotifiers

## Domain Layer (Optional)

**Purpose**: Abstract complex business logic from ViewModels.

### When to add Domain Layer
Add when ViewModels have logic that:
1. Merges data from multiple repositories
2. Is exceedingly complex
3. Will be reused by different ViewModels

### Use-cases
**What they do:**
- Take data from repositories
- Transform for UI layer consumption
- Encapsulate reusable business logic

**Relationships:**
- Use-cases depend on Repositories
- Use-cases and Repositories: many-to-many
- ViewModels depend on Use-cases AND Repositories (can skip use-cases for simple logic)

**Pros:**
- Avoid code duplication in ViewModels
- Improve testability by separating complex logic
- Improve code readability in ViewModels

**Cons:**
- Increases complexity
- Additional mocks for testing
- Adds boilerplate code

**Recommendation**: Add use-cases only when needed. Don't force all data access through use-cases for simple operations.

## Data Layer

Responsibility: Handle business data and logic.

### Repositories
**What they do:**
- Single source of truth for data types
- Poll data from Services
- Transform raw data into domain models
- Handle business logic:
  - Caching
  - Error handling
  - Retry logic
  - Data refresh (polling, user-triggered)

**What they don't do:**
- Direct UI rendering
- Business logic better suited for Domain layer
- Direct knowledge of other repositories

**Output:**
- Domain models (data classes tailored for app needs)
- Exposed as Streams (real-time) or Futures (one-time)

**Relationships:**
- Many-to-many with ViewModels
- Many-to-many with Services
- Should not be aware of each other

### Services
**What they do:**
- Wrap external data sources
- Expose async response objects (Future, Stream)
- Isolate data loading
- One service per data source

**What they don't do:**
- Hold state (stateless)
- Business logic
- Data transformation
- Caching

**Examples:**
- Platform APIs (iOS/Android native)
- REST/GraphQL API clients
- Local file access
- Database access
- Platform plugins (geolocation, camera, etc.)

**Relationships:**
- Many-to-many with Repositories
- Can be shared across the app

## Layer Communication Rules

1. **Adjacent layers only**: UI ↔ Domain ↔ Data
2. **Unidirectional data flow**: Data → ViewModel → View
3. **Events flow opposite**: View → ViewModel → Repository → Service
4. **No skipping layers**: Views don't call Services directly
5. **Dependencies**: Lower layers don't depend on upper layers

## When to Use Which Layer

**Small CRUD apps:**
- UI Layer (Views + ViewModels)
- Data Layer (Repositories + Services)
- Skip Domain Layer

**Apps with complex business logic:**
- UI Layer
- Domain Layer (for complex, reusable logic)
- Data Layer
- ViewModels may use Repositories directly for simple operations, Use-cases for complex

**Enterprise apps with complex rules:**
- All three layers
- Consider enforcing Use-cases for all data access

## Testing Strategy

**UI Layer Tests:**
- Widget tests for Views
- Unit tests for ViewModels (mock Repositories)

**Domain Layer Tests:**
- Unit tests for Use-cases (mock Repositories)

**Data Layer Tests:**
- Unit tests for Repositories (mock Services)
- Integration tests for Services (actual APIs/databases)
