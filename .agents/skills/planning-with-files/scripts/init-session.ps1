# Initialize planning files for a new session
# Usage:
#   powershell -File .agent/skills/planning-with-files/scripts/init-session.ps1                             # legacy: root-level task_plan.md, findings.md, progress.md
#   powershell -File .agent/skills/planning-with-files/scripts/init-session.ps1 -Template TYPE              # legacy with template choice
#   powershell -File .agent/skills/planning-with-files/scripts/init-session.ps1 "Backend Refactor"          # slug mode: .planning/<date>-backend-refactor/
#   powershell -File .agent/skills/planning-with-files/scripts/init-session.ps1 -PlanDir                    # slug mode with auto-generated untitled-<short> name
#   powershell -File .agent/skills/planning-with-files/scripts/init-session.ps1 -PlanDir "Quick Spike"      # slug mode, explicit slug
#
# Legacy mode (zero positional args, no -PlanDir) preserves v1.x behavior so
# upgrades stay non-breaking. Slug mode addresses parallel multi-task isolation
# by writing each plan under .planning/<date>-<slug>/ and pinning
# .planning/.active_plan so resolve-plan-dir.ps1 can find it.

param(
    [string]$ProjectName = "",
    [string]$Template = "default",
    [switch]$PlanDir
)

$DATE = Get-Date -Format "yyyy-MM-dd"

# Resolve template directory (skill root is one level up from scripts/)
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$SkillRoot = Split-Path -Parent $ScriptDir
$TemplateDir = Join-Path $SkillRoot "templates"

# Validate template
if ($Template -ne "default" -and $Template -ne "analytics") {
    Write-Host "Unknown template: $Template (available: default, analytics). Using default."
    $Template = "default"
}

# Force slug mode to always use the .planning directory. Legacy mode is retired to ensure all plans are kept as memory in the .planning/ folder.
$SlugMode = $true


function Get-Slug {
    param([string]$inputString)
    $slug = $inputString.ToLowerInvariant()
    $slug = [regex]::Replace($slug, "[^a-z0-9]", "-")
    $slug = [regex]::Replace($slug, "-{2,}", "-")
    $slug = $slug.Trim('-')
    if ($slug.Length -gt 40) {
        $slug = $slug.Substring(0, 40)
    }
    return $slug
}

function Get-ShortUuid {
    return [guid]::NewGuid().ToString("N").Substring(0, 8)
}

function New-PlanningFiles {
    param(
        [string]$TargetDir,
        [string]$Template,
        [string]$TemplateDir,
        [string]$Date
    )
    
    if (-not (Test-Path $TargetDir)) {
        New-Item -ItemType Directory -Path $TargetDir -Force | Out-Null
    }
    
    $planPath = Join-Path $TargetDir "task_plan.md"
    $findingsPath = Join-Path $TargetDir "findings.md"
    $progressPath = Join-Path $TargetDir "progress.md"
    
    # Create task_plan.md
    if (-not (Test-Path $planPath)) {
        $analyticsPlan = Join-Path $TemplateDir "analytics_task_plan.md"
        if ($Template -eq "analytics" -and (Test-Path $analyticsPlan)) {
            Copy-Item $analyticsPlan $planPath
        } else {
            $content = @"
# Task Plan: [Brief Description]

## Goal
[One sentence describing the end state]

## Current Phase
Phase 1

## Phases

### Phase 1: Requirements & Discovery
- [ ] Understand user intent
- [ ] Identify constraints
- [ ] Document in findings.md
- **Status:** in_progress

### Phase 2: Planning & Structure
- [ ] Define approach
- [ ] Create project structure
- **Status:** pending

### Phase 3: Implementation
- [ ] Execute the plan
- [ ] Write to files before executing
- **Status:** pending

### Phase 4: Testing & Verification
- [ ] Verify requirements met
- [ ] Document test results
- **Status:** pending

### Phase 5: Delivery
- [ ] Review outputs
- [ ] Deliver to user
- **Status:** pending

## Decisions Made
| Decision | Rationale |
|----------|-----------|

## Errors Encountered
| Error | Resolution |
|-------|------------|
"@
            $content | Out-File -FilePath $planPath -Encoding UTF8
        }
        Write-Host "Created $planPath"
    } else {
        Write-Host "$planPath already exists, skipping"
    }
    
    # Create findings.md
    if (-not (Test-Path $findingsPath)) {
        $analyticsFindings = Join-Path $TemplateDir "analytics_findings.md"
        if ($Template -eq "analytics" -and (Test-Path $analyticsFindings)) {
            Copy-Item $analyticsFindings $findingsPath
        } else {
            $content = @"
# Findings & Decisions

## Requirements
-

## Research Findings
-

## Technical Decisions
| Decision | Rationale |
|----------|-----------|

## Issues Encountered
| Issue | Resolution |
|-------|------------|

## Resources
-
"@
            $content | Out-File -FilePath $findingsPath -Encoding UTF8
        }
        Write-Host "Created $findingsPath"
    } else {
        Write-Host "$findingsPath already exists, skipping"
    }
    
    # Create progress.md
    if (-not (Test-Path $progressPath)) {
        if ($Template -eq "analytics") {
            $content = @"
# Progress Log

## Session: $Date

### Current Status
- **Phase:** 1 - Data Discovery
- **Started:** $Date

### Actions Taken
-

### Query Log
| Query | Result Summary | Interpretation |
|-------|---------------|----------------|

### Errors
| Error | Resolution |
|-------|------------|
"@
            $content | Out-File -FilePath $progressPath -Encoding UTF8
        } else {
            $content = @"
# Progress Log

## Session: $Date

### Current Status
- **Phase:** 1 - Requirements & Discovery
- **Started:** $Date

### Actions Taken
-

### Test Results
| Test | Expected | Actual | Status |
|------|----------|--------|--------|

### Errors
| Error | Resolution |
|-------|------------|
"@
            $content | Out-File -FilePath $progressPath -Encoding UTF8
        }
        Write-Host "Created $progressPath"
    } else {
        Write-Host "$progressPath already exists, skipping"
    }
}

if ($SlugMode) {
    $slug = ""
    if ($ProjectName -ne "") {
        $slug = Get-Slug $ProjectName
    }
    if ($slug -eq "") {
        $slug = "untitled-" + (Get-ShortUuid)
    }
    $baseId = "${DATE}-${slug}"
    $planId = $baseId
    $planRoot = Join-Path (Get-Location) ".planning"
    
    $counter = 2
    while (Test-Path (Join-Path $planRoot $planId)) {
        $planId = "${baseId}-${counter}"
        $counter++
    }
    
    $resolvedPlanDir = Join-Path $planRoot $planId
    New-Item -ItemType Directory -Path $resolvedPlanDir -Force | Out-Null
    
    $dispName = $ProjectName
    if ($dispName -eq "") { $dispName = "untitled" }
    Write-Host "Initializing planning files for: $dispName (template: $Template)"
    Write-Host "PLAN_ID=$planId"
    
    New-PlanningFiles -TargetDir $resolvedPlanDir -Template $Template -TemplateDir $TemplateDir -Date $DATE
    
    if (-not (Test-Path $planRoot)) {
        New-Item -ItemType Directory -Path $planRoot -Force | Out-Null
    }
    $activeFile = Join-Path $planRoot ".active_plan"
    $planId | Out-File -FilePath $activeFile -Encoding UTF8 -NoNewline
    
    Write-Host ""
    Write-Host "Active plan recorded: $activeFile"
    Write-Host "Pin this terminal to the plan for parallel sessions:"
    Write-Host "  `$env:PLAN_ID = '$planId'"
} else {
    Write-Host "Initializing planning files for: project (template: $Template)"
    New-PlanningFiles -TargetDir (Get-Location).Path -Template $Template -TemplateDir $TemplateDir -Date $DATE
    Write-Host ""
    Write-Host "Planning files initialized!"
    Write-Host "Files: task_plan.md, findings.md, progress.md"
}
