# Flutter Architecture Examples

This directory contains example code demonstrating the Flutter architecture patterns.

## Using Command Pattern

The Command pattern encapsulates actions with state management and Result handling.

### Basic Usage

```dart
import 'package:flutter/foundation.dart';
import '../assets/command.dart';
import '../assets/result.dart';

class TodoViewModel extends ChangeNotifier {
  Command0<void> get loadTodos => _loadTodosCommand;
  final _loadTodosCommand = Command0<void>(_loadTodos);

  Future<Result<void>> _loadTodos() async {
    // Load todos from repository
    return Result.ok(null);
  }
}
```

### In Your Widget

```dart
ListenableBuilder(
  listenable: viewModel.loadTodos,
  builder: (context, child) {
    if (viewModel.loadTodos.running) {
      return CircularProgressIndicator();
    }
    return ElevatedButton(
      onPressed: viewModel.loadTodos.execute,
      child: Text('Load Todos'),
    );
  },
);
```

## Using Result Type

The Result type provides type-safe error handling.

```dart
Future<Result<User>> fetchUser(String id) async {
  try {
    final user = await repository.getUser(id);
    return Result.ok(user);
  } catch (e) {
    return Result.error(Exception('Failed: $e'));
  }
}

// Handle result
final result = await fetchUser('123');
switch (result) {
  case Ok():
    print('User: ${result.value.name}');
  case Error():
    print('Error: ${result.error}');
}
```

## Repository Pattern Example

```dart
class TodoRepository {
  final ApiService _api;
  final DatabaseService _database;

  Stream<List<Todo>> get todos => _database.todosStream;

  TodoRepository(this._api, this._database);

  Future<Result<void>> addTodo(Todo todo) async {
    try {
      await _api.createTodo(todo);
      await _database.saveTodo(todo);
      return Result.ok(null);
    } catch (e) {
      return Result.error(e);
    }
  }
}
```

## Optimistic UI Example

Update UI immediately, then sync:

```dart
Future<Result<void>> addTodo(Todo todo) async {
  // Optimistic update
  _todos = [..._todos, todo];
  notifyListeners();

  // Actual API call
  final result = await repository.addTodo(todo);

  if (result case Error()) {
    // Rollback on error
    _todos = _todos.where((t) => t.id != todo.id).toList();
    notifyListeners();
    return result;
  }

  return Result.ok(null);
}
```

## Dependency Injection

Provide dependencies via constructor:

```dart
class TodoViewModel extends ChangeNotifier {
  final TodoRepository _repository;

  TodoViewModel(this._repository);
}

// In main.dart
final apiService = ApiService();
final databaseService = DatabaseService();
final repository = TodoRepository(apiService, databaseService);
final viewModel = TodoViewModel(repository);
```
