# MVVM Pattern

Model-View-ViewModel architectural pattern for Flutter applications.

## Overview

MVVM separates application features into three parts:
- **Model**: Data and business logic (Repositories, Services)
- **View**: UI presentation (Widgets)
- **ViewModel**: UI logic and state management

## Component Relationships

Every feature contains:
- One **View** (UI)
- One **ViewModel** (UI logic)
- One or more **Repositories** (data sources)
- Zero or more **Services** (external API access)

Views and ViewModels have a **one-to-one relationship**.

## View Layer

### Views (Widgets)
- Compose widgets to display UI
- Pass events to ViewModel via commands
- Receive data from ViewModel
- Contain minimal logic:
  - Simple if-statements for conditional rendering
  - Animation logic
  - Layout logic based on device info
  - Simple routing logic

**Important**: A View is not a single widget. Views are collections of widgets. One view may contain many widgets. ViewModels have one-to-one relationship with views, not individual widgets.

### ViewModels
- Transform repository data into UI state
- Maintain current UI state for rebuilds
- Expose commands (callback functions) for user actions
- Hold state that survives configuration changes

Responsibilities:
- Retrieve application data from repositories
- Filter, sort, aggregate data for presentation
- Track UI state (flags, carousel positions, etc.)
- Expose commands for button presses, form submissions, etc.

## Model Layer

### Repositories
Single source of truth for model data. Each data type has one repository class.

Responsibilities:
- Poll data from services
- Transform raw data into domain models
- Handle business logic:
  - Caching
  - Error handling
  - Retry logic
  - Refreshing data (polling, user actions)

Output: Domain models as Streams or Futures

Relationships:
- Many-to-many with ViewModels
- One ViewModel can use multiple Repositories
- One Repository can be used by multiple ViewModels
- Repositories should never be aware of each other

### Services
Lowest layer, wrap API endpoints and expose async response objects.

Responsibilities:
- Isolate data-loading
- Stateless (no state held)
- One service per data source

Examples:
- Platform APIs (iOS, Android)
- REST endpoints
- Local files
- Databases

Relationships:
- Many-to-many with Repositories
- One Repository can use multiple Services
- One Service can be used by multiple Repositories

## Data Flow

### User Interaction Flow
1. View: User interaction triggers event
2. View: Event handler calls ViewModel command
3. ViewModel: Command calls Repository method
4. Repository: Updates data and returns new data
5. ViewModel: Saves new state
6. View: UI rebuilds with new state

### Data-Originated Flow
1. Repository: Polls service for new data
2. Repository: Updates data
3. ViewModel: Receives new data from Repository
4. View: UI rebuilds with new state

## Benefits

- **Testability**: Test ViewModel logic by mocking Repositories
- **Maintainability**: Clear separation of concerns
- **Scalability**: Easy to add features without breaking existing code
- **Reusability**: Components have well-defined interfaces
