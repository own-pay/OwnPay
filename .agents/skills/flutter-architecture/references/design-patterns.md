# Design Patterns

Common design patterns for building robust Flutter applications.

## Command Pattern

Encapsulates actions as objects with state management and Result handling.

### Purpose
- Encapsulate complex logic
- Expose running and error states
- Prevent multiple simultaneous executions
- Type-safe error handling with Result type

### Implementation

See `command.dart` asset for template implementation.

**Usage:**

```dart
class MyViewModel extends ChangeNotifier {
  // Command without arguments
  final _loadDataCommand = Command0<void>(_loadData);

  // Command with one argument
  final _saveCommand = Command1<void, String>(_saveItem);

  Command0<void> get loadData => _loadDataCommand;
  Command1<void, String> get saveItem => _saveCommand;

  Future<Result<void>> _loadData() async {
    // Logic here
    return Result.ok(null);
  }

  Future<Result<void>> _saveItem(String item) async {
    // Logic here
    return Result.ok(null);
  }
}
```

**In View:**

```dart
ListenableBuilder(
  listenable: viewModel.loadData,
  builder: (context, child) {
    if (viewModel.loadData.running) {
      return CircularProgressIndicator();
    }
    if (viewModel.loadData.error) {
      return Text('Error: ${viewModel.loadData.result?.asError.error}');
    }
    return ElevatedButton(
      onPressed: () => viewModel.loadData.execute(),
      child: Text('Load Data'),
    );
  },
);
```

### Benefits
- Prevents double-taps
- Exposes loading, error, success states
- Type-safe Result handling
- Encapsulates complex logic

## Result Type

Type-safe error handling without exceptions.

### Purpose
- Represent success or failure states
- Type-safe error handling
- Avoid try-catch blocks
- Clear error propagation

### Implementation

See `result.dart` asset for template implementation.

**Usage:**

```dart
Future<Result<User>> fetchUser(String id) async {
  try {
    final response = await http.get('/users/$id');
    final user = User.fromJson(response.body);
    return Result.ok(user);
  } catch (e) {
    return Result.error(Exception('Failed to fetch user: $e'));
  }
}

// Consume result
final result = await fetchUser('123');
switch (result) {
  case Ok():
    print('User: ${result.value.name}');
  case Error():
    print('Error: ${result.error}');
}
```

### Benefits
- Explicit error handling
- Type-safe
- No uncaught exceptions
- Clear state representation

## Repository Pattern

Abstraction over data sources providing single source of truth.

### Purpose
- Hide data source complexity
- Provide single source of truth
- Enable caching and offline support
- Facilitate testing

### Implementation

```dart
class TodoRepository {
  final ApiService _apiService;
  final DatabaseService _databaseService;
  final BehaviorSubject<List<Todo>> _todos = BehaviorSubject.seeded([]);

  Stream<List<Todo>> get todos => _todos.stream;

  TodoRepository(this._apiService, this._databaseService) {
    _loadTodos();
  }

  Future<void> _loadTodos() async {
    try {
      final todos = await _databaseService.getTodos();
      _todos.add(todos);
      _refreshFromServer();
    } catch (e) {
      // Handle error
    }
  }

  Future<void> _refreshFromServer() async {
    try {
      final todos = await _apiService.fetchTodos();
      await _databaseService.saveTodos(todos);
      _todos.add(todos);
    } catch (e) {
      // Keep cached data on network error
    }
  }

  Future<Result<void>> addTodo(Todo todo) async {
    try {
      await _apiService.createTodo(todo);
      await _databaseService.saveTodo(todo);
      final updated = [..._todos.value, todo];
      _todos.add(updated);
      return Result.ok(null);
    } catch (e) {
      return Result.error(e);
    }
  }
}
```

### Benefits
- Unified data access interface
- Caching layer
- Error handling centralized
- Easy to mock for testing

## Optimistic UI

Update UI immediately, then sync with server.

### Purpose
- Improve perceived performance
- Better user experience
- Handle network delays gracefully

### Implementation

```dart
class TodoViewModel extends ChangeNotifier {
  final TodoRepository _repository;
  List<Todo> _todos = [];

  TodoViewModel(this._repository);

  List<Todo> get todos => _todos;

  Command0<void> get addTodo => Command0<void>(_executeAddTodo);

  Future<Result<void>> _executeAddTodo() async {
    // Optimistic update
    final tempTodo = Todo(
      id: DateTime.now().millisecondsSinceEpoch.toString(),
      title: 'New Todo',
      isComplete: false,
    );
    _todos = [..._todos, tempTodo];
    notifyListeners();

    // Actual API call
    final result = await _repository.addTodo(tempTodo);

    if (result case Error()) {
      // Rollback on error
      _todos = _todos.where((t) => t.id != tempTodo.id).toList();
      notifyListeners();
      return result;
    }

    // Update with server response
    final serverTodo = result.asOk.value;
    _todos = _todos.map((t) => t.id == tempTodo.id ? serverTodo : t).toList();
    notifyListeners();

    return Result.ok(null);
  }
}
```

### Benefits
- Instant UI response
- Better perceived performance
- Graceful error handling with rollback

## Offline-First

Cache data locally, sync when online.

### Purpose
- Support offline usage
- Reduce network calls
- Improve performance
- Handle poor connectivity

### Key Concepts
- Local cache as primary data source
- Background sync when online
- Optimistic updates with conflict resolution
- Network status monitoring

### Implementation Pattern

```dart
class TodoRepository {
  final ApiService _api;
  final LocalStorage _local;

  Stream<List<Todo>> get todos {
    // Stream from local cache (always available)
    return _local.todosStream;
  }

  Future<void> sync() async {
    if (!await _hasConnection()) return;

    final local = await _local.getTodos();
    final remote = await _api.getTodos();

    // Conflict resolution: remote wins
    await _local.saveTodos(remote);
  }

  Future<Result<void>> addTodo(Todo todo) async {
    // Save locally first (offline support)
    await _local.saveTodo(todo);

    // Sync when online
    if (await _hasConnection()) {
      final result = await _api.createTodo(todo);
      if (result case Ok()) {
        await _local.saveTodo(result.asOk.value);
      }
    }

    return Result.ok(null);
  }
}
```

## Dependency Injection

Provide dependencies externally rather than creating internally.

### Purpose
- Testability (inject mocks)
- Loose coupling
- Flexibility (swap implementations)
- Clear dependencies

### Implementation Patterns

**Constructor Injection:**

```dart
class TodoViewModel extends ChangeNotifier {
  final TodoRepository _repository;

  TodoViewModel(this._repository);
}
```

**Provider Setup:**

```dart
void main() {
  final apiService = ApiService();
  final databaseService = DatabaseService();
  final repository = TodoRepository(apiService, databaseService);
  final viewModel = TodoViewModel(repository);

  runApp(
    MultiProvider(
      providers: [
        Provider<TodoRepository>.value(value: repository),
        ChangeNotifierProvider<TodoViewModel>.value(value: viewModel),
      ],
      child: MyApp(),
    ),
  );
}
```

**Usage:**

```dart
class TodoScreen extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final viewModel = context.watch<TodoViewModel>();
    // Use viewModel
  }
}
```

### Benefits
- Easy testing with mocks
- Clear dependency graph
- Flexible implementation swapping
- Follows inversion of control principle

## Key-Value Storage

Simple data persistence for app settings and small data.

### Purpose
- Store app settings
- Persist user preferences
- Cache small data

### Implementation (SharedPreferences)

```dart
class SettingsRepository {
  final SharedPreferences _prefs;

  SettingsRepository(this._prefs);

  static const _themeKey = 'theme_mode';
  static const _languageKey = 'language';

  ThemeMode get themeMode {
    final value = _prefs.getString(_themeKey);
    return value == 'dark' ? ThemeMode.dark : ThemeMode.light;
  }

  Future<void> setThemeMode(ThemeMode mode) async {
    await _prefs.setString(_themeKey, mode == ThemeMode.dark ? 'dark' : 'light');
  }

  String get language => _prefs.getString(_languageKey) ?? 'en';

  Future<void> setLanguage(String lang) async {
    await _prefs.setString(_languageKey, lang);
  }
}
```

## SQL Database

Structured data storage for complex data models.

### Purpose
- Store complex relational data
- Query and filter efficiently
- Transaction support
- Offline-first data layer

### Implementation (sqflite)

```dart
class TodoDatabase {
  static const _tableName = 'todos';
  static const _id = 'id';
  static const _title = 'title';
  static const _isComplete = 'is_complete';

  final Database _database;

  TodoDatabase(this._database);

  Future<void> init() async {
    await _database.execute('''
      CREATE TABLE $_tableName (
        $_id TEXT PRIMARY KEY,
        $_title TEXT NOT NULL,
        $_isComplete INTEGER NOT NULL
      )
    ''');
  }

  Future<List<Todo>> getTodos() async {
    final results = await _database.query(_tableName);
    return results.map((map) => Todo.fromJson(map)).toList();
  }

  Future<void> saveTodo(Todo todo) async {
    await _database.insert(
      _tableName,
      todo.toJson(),
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<void> deleteTodo(String id) async {
    await _database.delete(
      _tableName,
      where: '$_id = ?',
      whereArgs: [id],
    );
  }
}
```

## When to Use Each Pattern

| Pattern | Use When |
|---------|----------|
| Command | Encapsulating user actions, need loading/error states |
| Result | Type-safe error handling, avoiding exceptions |
| Repository | Abstracting data sources, need caching |
| Optimistic UI | Instant UI response, network latency issues |
| Offline-First | Poor connectivity, need offline support |
| Dependency Injection | Testing, loose coupling |
| Key-Value Storage | App settings, small data, preferences |
| SQL Database | Complex data, queries, relationships |
