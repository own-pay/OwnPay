# Architecture Concepts

Core architectural principles for Flutter applications.

## Separation of Concerns

Divide application functionality into distinct, self-contained units. Separate UI logic from business logic.

In Flutter:
- Separate widgets by functionality (authentication vs search logic)
- Write reusable, lean widgets with minimal logic
- Keep widgets focused on presentation only

## Layered Architecture

Organize app into 2-3 distinct layers:

### UI Layer
Displays data to users and handles user interaction. Also called the presentation layer.

### Logic Layer (Domain Layer - Optional)
Implements core business logic, facilitates interaction between data and UI layers. Only needed for apps with complex client-side business logic.

### Data Layer
Manages interactions with data sources (databases, APIs, platform plugins). Exposes data and methods to business logic layer.

Layers communicate only with adjacent layers. UI doesn't know data layer exists, and vice versa.

## Single Source of Truth (SSOT)

Every data type has one authoritative source responsible for representing local or remote state. The SSOT class should be the only class that can modify the data.

Benefits:
- Reduces bugs
- Simplifies code (one copy of data)
- Prevents sync issues

In Flutter, SSOT is typically held in a **Repository** class in the data layer. One repository per data type.

Apply SSOT across layers and within classes:
- Use getters to derive values from SSOT field
- Use records to group related values instead of parallel lists

## Unidirectional Data Flow (UDF)

Decouple state from UI that displays it. State flows from data → logic → UI. User events flow from UI → logic → data.

Update cycle:
1. UI: User interaction triggers event handler
2. Logic: Logic class calls repository methods
3. Data: Repository updates data and provides new data
4. Logic: Logic class saves state, sends to UI
5. UI: UI displays new state

Data can also originate from data layer (e.g., repository polling HTTP server). Key principle: data changes always happen in SSOT (data layer).

## UI is a Function of State

Flutter is declarative - UI reflects current app state. When state changes, trigger UI rebuild.

Key principles:
- Data drives UI, not the other way
- Data should be immutable and persistent
- Views contain minimal logic
- Minimizes data loss during app closure
- Improves testability and bug resistance

## Extensibility

Each architecture piece has well-defined inputs and outputs. Example: ViewModel takes data sources (repositories) as inputs, exposes commands and formatted data for views.

Benefits:
- Swap implementations without changing consuming code
- Test components in isolation
- Easy to add new features

## Testability

Principles making software extensible also make it testable:
- Test self-contained logic (ViewModel) by mocking Repository
- Test UI logic separate from Flutter widgets
- Add new logic/UI with low risk and high flexibility
