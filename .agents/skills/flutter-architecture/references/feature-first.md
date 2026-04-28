# Feature-First Architecture

Organize code by features instead of layers for better maintainability and team scalability.

## Overview

**Feature-first** organizes code by business features, with each feature containing all layers needed for that feature.

```
lib/
├── features/
│   ├── auth/
│   │   ├── data/
│   │   │   ├── repositories/
│   │   │   └── services/
│   │   ├── domain/
│   │   │   ├── models/
│   │   │   └── use-cases/
│   │   └── presentation/
│   │       ├── views/
│   │       └── viewmodels/
│   ├── todos/
│   │   ├── data/
│   │   ├── domain/
│   │   └── presentation/
│   └── settings/
│       ├── data/
│       ├── domain/
│       └── presentation/
├── shared/
│   ├── core/
│   │   ├── constants/
│   │   ├── theme/
│   │   └── utils/
│   ├── data/
│   │   ├── models/
│   │   └── services/
│   └── ui/
│       ├── widgets/
│       └── components/
└── main.dart
```

## Feature-First vs Layer-First

### Layer-First (Traditional)

```
lib/
├── data/
│   ├── repositories/
│   ├── services/
│   └── models/
├── domain/
│   ├── use-cases/
│   └── entities/
├── presentation/
│   ├── views/
│   └── viewmodels/
└── shared/
```

**When to use:**
- Small to medium apps
- Few features (<10)
- Solo developers or small teams
- Simple business logic

**Pros:**
- Clear separation by layer
- Easy to find components by type
- Less nesting

**Cons:**
- Harder to delete features
- Changes often span multiple folders
- Tangled dependencies between layers

### Feature-First (Recommended for teams)

```
lib/
├── features/
│   ├── auth/
│   │   ├── data/
│   │   ├── domain/
│   │   └── presentation/
│   └── todos/
│       ├── data/
│       ├── domain/
│       └── presentation/
└── shared/
```

**When to use:**
- Medium to large apps
- Many features (10+)
- Team development (2+ developers)
- Complex business logic
- Frequently adding/removing features

**Pros:**
- Features are self-contained units
- Easy to add/remove entire features
- Clear feature boundaries
- Reduced merge conflicts
- Teams work independently on features

**Cons:**
- More nesting in folder structure
- Shared code requires careful organization
- Can duplicate similar code across features

## Feature Structure

Each feature contains all layers for that feature:

```
features/
└── todos/
    ├── data/
    │   ├── repositories/
    │   │   └── todo_repository.dart
    │   └── services/
    │       └── todo_api_service.dart
    ├── domain/
    │   ├── models/
    │   │   └── todo.dart
    │   └── use-cases/
    │       └── fetch_todos.dart
    │       └── create_todo.dart
    │       └── delete_todo.dart
    └── presentation/
        ├── views/
        │   └── todo_list_view.dart
        └── viewmodels/
            └── todo_list_viewmodel.dart
```

### Naming Conventions

**Feature folders:** Plural, lowercase, underscore-separated
- `auth/`, `todos/`, `user_profile/`

**Component folders:** Plural, lowercase
- `data/`, `domain/`, `presentation/`
- `repositories/`, `services/`, `models/`
- `views/`, `viewmodels/`

**Files:** snake_case for regular files, camelCase for classes
- `todo_repository.dart` → `class TodoRepository`
- `todo_list_view.dart` → `class TodoListView`
- `fetch_todos.dart` → `class FetchTodos`

## Shared Code

Code used across multiple features goes in `shared/`:

```
shared/
├── core/
│   ├── constants/
│   │   └── app_constants.dart
│   ├── theme/
│   │   ├── app_colors.dart
│   │   └── app_text_styles.dart
│   └── utils/
│       ├── extensions.dart
│       ├── validators.dart
│       └── formatters.dart
├── data/
│   ├── models/
│   │   └── common_models.dart
│   └── services/
│       ├── network_service.dart
│       ├── local_storage_service.dart
│       └── analytics_service.dart
└── ui/
    ├── widgets/
    │   ├── app_scaffold.dart
    │   ├── loading_indicator.dart
    │   └── error_widget.dart
    └── components/
        ├── app_button.dart
        ├── app_text_field.dart
        └── app_card.dart
```

### What goes in `shared/core/`

- **Constants**: App-wide constants (API URLs, timeouts)
- **Theme**: Colors, text styles, themes
- **Utils**: Extensions, validators, formatters used across features

### What goes in `shared/data/`

- **Models**: Domain models used by multiple features (User, etc.)
- **Services**: Platform services (network, storage, analytics)

### What goes in `shared/ui/`

- **Widgets**: Reusable UI components (scaffolds, indicators)
- **Components**: Reusable UI elements (buttons, cards, inputs)

## Dependency Rules

### Within a Feature

```
presentation/ → domain/ → data/
      ↓          ↓
    └─ shared/ui/
              └─ shared/data/
```

- **Presentation layer** can use Domain, Shared/UI
- **Domain layer** can use Shared/Data
- **Data layer** can use Shared/Data

### Between Features

**Features should NOT depend on each other.**

If Feature A needs Feature B's functionality:
1. Move common logic to `shared/`
2. Use dependency injection (interfaces in domain)
3. Consider if features should be merged

```dart
// ❌ BAD: Feature depending on another feature
import 'package:my_app/features/auth/data/repositories/auth_repository.dart';

// ✅ GOOD: Using shared service
import 'package:my_app/shared/data/services/auth_service.dart';

// ✅ GOOD: Using domain interface
import 'package:my_app/features/todos/domain/repositories/todo_repository.dart';
```

## When to Add a New Feature

1. **Create feature folder:**
   ```
   features/new_feature/
   ├── data/
   ├── domain/
   └── presentation/
   ```

2. **Add subfolders based on complexity:**
   - Simple: Skip `domain/`, use only `data/` and `presentation/`
   - Complex: Add `domain/` for use-cases

3. **Implement bottom-up:**
   - Add models in `data/` or `domain/models/`
   - Add services in `data/services/`
   - Add repositories in `data/repositories/`
   - Add viewmodels in `presentation/viewmodels/`
   - Add views in `presentation/views/`

4. **Add exports:**
   ```dart
   // features/new_feature/new_feature.dart
   export 'data/repositories/new_feature_repository.dart';
   export 'presentation/views/new_feature_view.dart';
   export 'presentation/viewmodels/new_feature_viewmodel.dart';
   ```

5. **Register in DI container** (main.dart)

## Best Practices

### 1. Barrel Files

Export feature contents from barrel file:

```dart
// features/todos/todos.dart
library todos;

export 'data/repositories/todo_repository.dart';
export 'domain/models/todo.dart';
export 'domain/use-cases/fetch_todos.dart';
export 'presentation/views/todo_list_view.dart';
export 'presentation/viewmodels/todo_list_viewmodel.dart';
```

Usage:
```dart
import 'package:my_app/features/todos/todos.dart';

// Now have access to Todo, TodoRepository, etc.
```

### 2. Lazy Loading

For large apps, use lazy loading:

```dart
// In router
GoRoute(
  path: '/todos',
  builder: (context, state) => const LazyLoad(
    builder: () => const TodosView(),
  ),
);
```

### 3. Feature Scoping

Keep feature dependencies minimal:
- Use `shared/` for common code
- Avoid cross-feature imports
- When needed, use dependency inversion

### 4. Testing

Each feature is testable independently:

```
features/
└── todos/
    ├── data/
    │   └── repositories/
    │       └── todo_repository_test.dart
    ├── domain/
    │   └── use-cases/
    │       └── fetch_todos_test.dart
    └── presentation/
        └── viewmodels/
            └── todo_list_viewmodel_test.dart
```

## Migration from Layer-First

Gradual migration strategy:

1. **Start with new features:**
   - Add new features using feature-first
   - Keep old code in layer-first

2. **Migrate one feature at a time:**
   ```
   // Before (layer-first)
   data/
     ├── todo_repository.dart
     └── user_repository.dart
   presentation/
     ├── todo_view.dart
     └── user_view.dart

   // After (feature-first)
   features/
     ├── todos/
     │   ├── data/repositories/todo_repository.dart
     │   └── presentation/views/todo_view.dart
     └── users/
         ├── data/repositories/user_repository.dart
         └── presentation/views/user_view.dart
   ```

3. **Move shared code to `shared/`:**
   - Identify cross-feature code
   - Move to appropriate `shared/` folder
   - Update imports

4. **Update barrel files** for clean imports

## Example: Complete Feature

```
features/
└── todos/
    ├── todos.dart                    // Barrel file
    ├── data/
    │   ├── models/
    │   │   └── todo_model.dart       // DTOs from API
    │   ├── repositories/
    │   │   └── todo_repository.dart  // SSOT for todos
    │   └── services/
    │       └── todo_api_service.dart   // API calls
    ├── domain/
    │   ├── models/
    │   │   └── todo.dart             // Domain entity
    │   └── use-cases/
    │       ├── fetch_todos.dart
    │       ├── create_todo.dart
    │       └── update_todo.dart
    │       └── delete_todo.dart
    └── presentation/
        ├── viewmodels/
        │   └── todo_list_viewmodel.dart
        └── views/
            └── todo_list_view.dart
```

This structure provides:
- Complete feature encapsulation
- Clear layer separation within feature
- Easy to add/remove entire feature
- Minimal dependencies on other features
