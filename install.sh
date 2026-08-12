#!/usr/bin/env bash
# ==============================================================================
#
#   ██████╗ ██╗    ██╗███╗   ██╗██████╗  █████╗ ██╗   ██╗
#  ██╔═══██╗██║    ██║████╗  ██║██╔══██╗██╔══██╗╚██╗ ██╔╝
#  ██║   ██║██║ █╗ ██║██╔██╗ ██║██████╔╝███████║ ╚████╔╝
#  ██║   ██║██║███╗██║██║╚██╗██║██╔═══╝ ██╔══██║  ╚██╔╝
#  ╚██████╔╝╚███╔███╔╝██║ ╚████║██║     ██║  ██║   ██║
#   ╚═════╝  ╚══╝╚══╝ ╚═╝  ╚═══╝╚═╝     ╚═╝  ╚═╝   ╚═╝
#
#  Open-Source Payment Gateway — VPS One-Click Installer
#  Version 1.0.0 | https://github.com/ownpay/ownpay
# ==============================================================================
#
#  USAGE:
#    curl -fsSL https://raw.githubusercontent.com/ownpay/ownpay/main/install.sh | sudo bash
#    — or —
#    sudo bash install.sh [--unattended] [--resume] [--help]
#
#  OPTIONS:
#    --unattended   Skip prompts; read values from environment variables
#    --resume       Resume from last checkpoint on a failed install
#    --help         Show this help message and exit
#
# ==============================================================================

set -euo pipefail

# ─────────────────────────────────────────────────────────────────────────────
# CONSTANTS
# ─────────────────────────────────────────────────────────────────────────────

readonly INSTALLER_VERSION="1.0.0"
readonly OWNPAY_GITHUB_ORG="own-pay"
readonly OWNPAY_GITHUB_REPO="OwnPay"
# Primary update source — OwnPay's own manifest (channels.beta is the stable channel)
readonly OWNPAY_MANIFEST_URL="https://update.ownpay.org/manifest.json"
# GitHub fallback (used only if the manifest is unreachable)
readonly OWNPAY_GITHUB_FALLBACK="https://github.com/${OWNPAY_GITHUB_ORG}/${OWNPAY_GITHUB_REPO}/archive/refs/heads/main.zip"

readonly OWNPAY_PHP_MIN="8.3"
readonly OWNPAY_INSTALL_DIR="${OWNPAY_INSTALL_DIR:-/var/www/ownpay}"
readonly LOG_FILE="/var/log/ownpay-install.log"
readonly CHECKPOINT_FILE="/tmp/.ownpay-install-checkpoint"
readonly TOTAL_PHASES=13

# ─────────────────────────────────────────────────────────────────────────────
# COLOURS & FORMATTING
# ─────────────────────────────────────────────────────────────────────────────

# Detect colour support
if [ -t 1 ] || [ "${FORCE_COLOR:-0}" = "1" ]; then
  # ANSI-C quoting ($'...') stores the actual ESC byte (0x1B), not a literal \033 string.
  # This is required for printf to render colors correctly without %b format.
  C_RESET=$'\033[0m'
  C_BOLD=$'\033[1m'
  C_DIM=$'\033[2m'

  # Brand palette
  C_BRAND=$'\033[38;5;99m'       # OwnPay purple/indigo
  C_BRAND_LIGHT=$'\033[38;5;135m'
  C_ACCENT=$'\033[38;5;39m'      # Cyan-blue accent

  # Semantic
  C_SUCCESS=$'\033[38;5;82m'     # Bright green
  C_WARN=$'\033[38;5;220m'       # Amber
  C_ERROR=$'\033[38;5;196m'      # Bright red
  C_INFO=$'\033[38;5;45m'        # Sky blue
  C_MUTED=$'\033[38;5;240m'      # Grey
  C_WHITE=$'\033[38;5;255m'      # Near white

  # Backgrounds
  BG_BRAND=$'\033[48;5;55m'
  BG_SUCCESS=$'\033[48;5;22m'
  BG_ERROR=$'\033[48;5;52m'
  BG_WARN=$'\033[48;5;58m'
else
  C_RESET=''; C_BOLD=''; C_DIM=''
  C_BRAND=''; C_BRAND_LIGHT=''; C_ACCENT=''
  C_SUCCESS=''; C_WARN=''; C_ERROR=''; C_INFO=''; C_MUTED=''; C_WHITE=''
  BG_BRAND=''; BG_SUCCESS=''; BG_ERROR=''; BG_WARN=''
fi

# Symbols
SYM_OK="${C_SUCCESS}✓${C_RESET}"
SYM_FAIL="${C_ERROR}✗${C_RESET}"
SYM_WARN="${C_WARN}⚠${C_RESET}"
SYM_ARROW="${C_ACCENT}›${C_RESET}"
SYM_DOT="${C_MUTED}•${C_RESET}"
SYM_BULLET="${C_BRAND}▸${C_RESET}"

# ─────────────────────────────────────────────────────────────────────────────
# GLOBALS (populated by wizard)
# ─────────────────────────────────────────────────────────────────────────────

DOMAIN=""
APP_NAME="OwnPay"
TIMEZONE="UTC"
WEBSERVER=""          # nginx | apache | caddy
SSL_MODE=""           # letsencrypt | selfsigned | skip
USE_REDIS=0
DB_HOST="127.0.0.1"
DB_PORT="3306"
DB_NAME="ownpay"
DB_USER="ownpay"
DB_PASS=""
ADMIN_NAME=""
ADMIN_EMAIL=""
ADMIN_USERNAME=""
ADMIN_PASSWORD=""
UNATTENDED=0
RESUME=0
OS_ID=""
OS_VERSION=""
OS_CODENAME=""
PHP_FPM_SOCK=""
WEBSERVER_USER="www-data"

# ─────────────────────────────────────────────────────────────────────────────
# SPINNER
# ─────────────────────────────────────────────────────────────────────────────

_SPINNER_PID=""

# Inner loop runs in a subshell — must be a named function so `local` is valid.
_spinner_loop() {
  local msg="$1"
  local frames=('⠋' '⠙' '⠹' '⠸' '⠼' '⠴' '⠦' '⠧' '⠇' '⠏')
  local i=0
  while true; do
    printf "\r  ${C_BRAND}%s${C_RESET}  ${C_WHITE}%s${C_RESET}   " \
      "${frames[$(( i % ${#frames[@]} ))]}" "$msg"
    sleep 0.08
    i=$(( i + 1 ))
  done
}

spinner_start() {
  local msg="${1:-Working...}"
  tput civis 2>/dev/null || true
  _spinner_loop "$msg" &
  _SPINNER_PID=$!
  disown "$_SPINNER_PID" 2>/dev/null || true
}

spinner_stop() {
  if [ -n "${_SPINNER_PID:-}" ]; then
    kill "$_SPINNER_PID"    2>/dev/null || true
    wait "$_SPINNER_PID"    2>/dev/null || true
    _SPINNER_PID=""
  fi
  # Erase the spinner line completely
  printf "\r\033[2K"
  tput cnorm 2>/dev/null || true
}

# ─────────────────────────────────────────────────────────────────────────────
# LOGGING
# ─────────────────────────────────────────────────────────────────────────────

_log_raw() {
  local level="$1"; shift
  local msg="$*"
  local ts
  ts="$(date '+%Y-%m-%d %H:%M:%S')"
  echo "[$ts] [$level] $msg" >> "$LOG_FILE" 2>/dev/null || true
}

log_info() {
  spinner_stop
  printf "  ${C_INFO}${C_BOLD}INFO${C_RESET}  ${C_WHITE}%s${C_RESET}\n" "$*"
  _log_raw INFO "$*"
}

log_success() {
  spinner_stop
  printf "  ${SYM_OK}  ${C_SUCCESS}%s${C_RESET}\n" "$*"
  _log_raw OK "$*"
}

log_warn() {
  spinner_stop
  printf "  ${SYM_WARN}  ${C_WARN}%s${C_RESET}\n" "$*"
  _log_raw WARN "$*"
}

log_error() {
  spinner_stop
  printf "\n  ${SYM_FAIL}  ${C_ERROR}${C_BOLD}%s${C_RESET}\n" "$*" >&2
  _log_raw ERROR "$*"
}

log_step() {
  local phase="$1"
  local title="$2"
  echo ""
  _divider_thin
  printf "  ${C_BRAND}${C_BOLD}[%02d/%02d]${C_RESET}  ${C_WHITE}${C_BOLD}%s${C_RESET}\n" \
    "$phase" "$TOTAL_PHASES" "$title"
  _divider_thin
  _log_raw PHASE "[$phase/$TOTAL_PHASES] $title"
}

# ─────────────────────────────────────────────────────────────────────────────
# UI HELPERS
# ─────────────────────────────────────────────────────────────────────────────

_term_width() {
  tput cols 2>/dev/null || echo 80
}

_divider() {
  local w
  w="$(_term_width)"
  local line
  line="$(printf '%*s' "$w" '' | tr ' ' '─')"
  printf "${C_MUTED}%s${C_RESET}\n" "$line"
}

_divider_thin() {
  local w
  w="$(_term_width)"
  local line
  line="$(printf '%*s' "$w" '' | tr ' ' '·')"
  printf "${C_MUTED}%s${C_RESET}\n" "$line"
}

_box_top() {
  local w="${1:-72}"
  printf "${C_MUTED}┌%s┐${C_RESET}\n" "$(printf '%*s' "$((w-2))" '' | tr ' ' '─')"
}

_box_bot() {
  local w="${1:-72}"
  printf "${C_MUTED}└%s┘${C_RESET}\n" "$(printf '%*s' "$((w-2))" '' | tr ' ' '─')"
}

_box_mid() {
  local w="${1:-72}"
  printf "${C_MUTED}├%s┤${C_RESET}\n" "$(printf '%*s' "$((w-2))" '' | tr ' ' '─')"
}

_box_row() {
  local content="$1"
  local w="${2:-72}"
  local inner=$((w - 4))
  # Strip real ESC-based ANSI codes for accurate visible-length calculation.
  # The \033 here is interpreted by $'...' in the pattern passed to sed -E.
  local stripped
  stripped="$(printf '%s' "$content" | sed $'s/\033\\[[0-9;]*m//g')"
  local pad=$((inner - ${#stripped}))
  [ "$pad" -lt 0 ] && pad=0
  printf "${C_MUTED}│${C_RESET} %s%*s ${C_MUTED}│${C_RESET}\n" "$content" "$pad" ""
}

_box_empty() {
  local w="${1:-72}"
  local inner=$((w - 2))
  printf "${C_MUTED}│%*s│${C_RESET}\n" "$inner" ""
}

show_banner() {
  clear
  echo ""
  printf "${C_BRAND}${C_BOLD}"
  cat << 'BANNER'
  ╔═══════════════════════════════════════════════════════════════════════╗
  ║                                                                       ║
  ║    ██████╗ ██╗    ██╗███╗   ██╗██████╗  █████╗ ██╗   ██╗            ║
  ║   ██╔═══██╗██║    ██║████╗  ██║██╔══██╗██╔══██╗╚██╗ ██╔╝            ║
  ║   ██║   ██║██║ █╗ ██║██╔██╗ ██║██████╔╝███████║ ╚████╔╝             ║
  ║   ██║   ██║██║███╗██║██║╚██╗██║██╔═══╝ ██╔══██║  ╚██╔╝              ║
  ║   ╚██████╔╝╚███╔███╔╝██║ ╚████║██║     ██║  ██║   ██║               ║
  ║    ╚═════╝  ╚══╝╚══╝ ╚═╝  ╚═══╝╚═╝     ╚═╝  ╚═╝   ╚═╝               ║
  ║                                                                       ║
  ║         Open-Source Payment Gateway — VPS Production Installer        ║
  ║                                                                       ║
  ╚═══════════════════════════════════════════════════════════════════════╝
BANNER
  printf "${C_RESET}"
  printf "  ${C_MUTED}Installer version %s  •  Log → %s${C_RESET}\n\n" \
    "$INSTALLER_VERSION" "$LOG_FILE"
}

show_phase_header() {
  local phase="$1"
  local icon="$2"
  local title="$3"
  local subtitle="${4:-}"
  local w=72
  echo ""
  _box_top $w
  _box_empty $w
  _box_row "  ${C_BRAND}${C_BOLD}${icon}  PHASE ${phase}/${TOTAL_PHASES}${C_RESET}  ${C_WHITE}${C_BOLD}${title}${C_RESET}" $w
  if [ -n "$subtitle" ]; then
    _box_row "     ${C_MUTED}${subtitle}${C_RESET}" $w
  fi
  _box_empty $w
  _box_bot $w
  echo ""
}

confirm() {
  local prompt="${1:-Are you sure?}"
  local default="${2:-y}"
  local answer
  local hint
  if [ "$default" = "y" ]; then hint="[Y/n]"; else hint="[y/N]"; fi
  printf "  ${C_ACCENT}?${C_RESET}  ${C_WHITE}%s${C_RESET} ${C_MUTED}%s${C_RESET} " "$prompt" "$hint"
  read -r answer </dev/tty || answer="$default"
  answer="${answer:-$default}"
  [[ "$answer" =~ ^[Yy]$ ]]
}

prompt_value() {
  local label="$1"
  local varname="$2"
  local default="${3:-}"
  local secret="${4:-0}"
  local hint=""
  local answer=""

  [ -n "$default" ] && hint=" ${C_MUTED}[${default}]${C_RESET}"

  if [ "$secret" = "1" ]; then
    printf "  ${C_ACCENT}›${C_RESET}  ${C_WHITE}%s${C_RESET}%s ${C_MUTED}(hidden)${C_RESET}: " "$label" "$hint"
    read -rs answer </dev/tty || true
    echo ""
  else
    printf "  ${C_ACCENT}›${C_RESET}  ${C_WHITE}%s${C_RESET}%s: " "$label" "$hint"
    read -r answer </dev/tty || true
  fi

  answer="${answer:-$default}"
  printf -v "$varname" '%s' "$answer"
}

prompt_menu() {
  # Usage: prompt_menu "Title" "subtitle" VAR_NAME option1 label1 option2 label2 ...
  local title="$1"; shift
  local subtitle="$1"; shift
  local varname="$1"; shift
  local -a options=()
  local -a labels=()
  local i=1
  while [ $# -ge 2 ]; do
    options+=("$1"); labels+=("$2"); shift 2
    i=$((i+1))
  done

  local w=72
  echo ""
  _box_top $w
  _box_row "  ${C_WHITE}${C_BOLD}${title}${C_RESET}" $w
  if [ -n "$subtitle" ]; then
    _box_row "  ${C_MUTED}${subtitle}${C_RESET}" $w
  fi
  _box_mid $w
  _box_empty $w
  local idx=1
  for lbl in "${labels[@]}"; do
    _box_row "  ${C_BRAND}${C_BOLD}  ${idx})${C_RESET}  ${C_WHITE}${lbl}${C_RESET}" $w
    idx=$((idx+1))
  done
  _box_empty $w
  _box_bot $w

  local answer=""
  while true; do
    printf "  ${C_ACCENT}›${C_RESET}  ${C_WHITE}Enter your choice${C_RESET} ${C_MUTED}[1-${#options[@]}]${C_RESET}: "
    read -r answer </dev/tty || answer="1"
    answer="${answer:-1}"
    if [[ "$answer" =~ ^[0-9]+$ ]] && [ "$answer" -ge 1 ] && [ "$answer" -le "${#options[@]}" ]; then
      printf -v "$varname" '%s' "${options[$((answer-1))]}"
      log_success "Selected: ${labels[$((answer-1))]}"
      return
    fi
    log_warn "Invalid choice. Please enter a number between 1 and ${#options[@]}."
  done
}

# ─────────────────────────────────────────────────────────────────────────────
# CHECKPOINT SYSTEM
# ─────────────────────────────────────────────────────────────────────────────

checkpoint_save() {
  echo "$1" > "$CHECKPOINT_FILE"
}

checkpoint_get() {
  [ -f "$CHECKPOINT_FILE" ] && cat "$CHECKPOINT_FILE" || echo "0"
}

checkpoint_clear() {
  rm -f "$CHECKPOINT_FILE"
}

phase_done() {
  checkpoint_save "$1"
}

should_run_phase() {
  local phase="$1"
  local last_done
  last_done="$(checkpoint_get)"
  [ "$RESUME" = "0" ] || [ "$phase" -gt "$last_done" ]
}

# ─────────────────────────────────────────────────────────────────────────────
# OS DETECTION
# ─────────────────────────────────────────────────────────────────────────────

detect_os() {
  if [ -f /etc/os-release ]; then
    # shellcheck disable=SC1091
    . /etc/os-release
    OS_ID="${ID:-unknown}"
    OS_VERSION="${VERSION_ID:-0}"
    OS_CODENAME="${VERSION_CODENAME:-}"
  else
    log_error "Cannot detect OS. /etc/os-release not found."
    exit 1
  fi

  case "$OS_ID" in
    ubuntu)
      case "$OS_VERSION" in
        20.04|22.04|24.04) ;;
        *) log_warn "Ubuntu $OS_VERSION is untested. Proceeding anyway." ;;
      esac
      ;;
    debian)
      case "$OS_VERSION" in
        11|12) ;;
        *) log_warn "Debian $OS_VERSION is untested. Proceeding anyway." ;;
      esac
      ;;
    *)
      log_error "Unsupported OS: $OS_ID. OwnPay installer supports Ubuntu 20/22/24 and Debian 11/12."
      exit 1
      ;;
  esac
}

# ─────────────────────────────────────────────────────────────────────────────
# UTILITIES
# ─────────────────────────────────────────────────────────────────────────────

require_root() {
  if [ "$(id -u)" -ne 0 ]; then
    log_error "This installer must be run as root or with sudo."
    printf "  Try: ${C_ACCENT}sudo bash install.sh${C_RESET}\n\n"
    exit 1
  fi
}

check_internet() {
  spinner_start "Checking internet connectivity..."
  if ! curl -fsS --max-time 10 https://api.github.com > /dev/null 2>&1; then
    spinner_stop
    log_error "No internet connection detected. Please ensure the server can reach the internet."
    exit 1
  fi
  spinner_stop
  log_success "Internet connectivity confirmed"
}

run_quiet() {
  local desc="$1"; shift
  spinner_start "$desc"
  # Always suppress apt interactive prompts regardless of caller
  if [ "${1:-}" = "apt-get" ] || [ "${1:-}" = "apt" ]; then
    DEBIAN_FRONTEND=noninteractive "$@" >> "$LOG_FILE" 2>&1
  else
    "$@" >> "$LOG_FILE" 2>&1
  fi
  local rc=$?
  spinner_stop
  if [ $rc -eq 0 ]; then
    log_success "$desc"
  else
    log_error "$desc — FAILED (exit code $rc). Check $LOG_FILE for details."
  fi
  return $rc
}

pkg_install() {
  DEBIAN_FRONTEND=noninteractive apt-get install -y -q "$@" >> "$LOG_FILE" 2>&1
}

cmd_exists() {
  command -v "$1" > /dev/null 2>&1
}

gen_password() {
  openssl rand -base64 20 | tr -dc 'a-zA-Z0-9@#%' | head -c 20
}

gen_secret() {
  openssl rand -hex 32
}

# ─────────────────────────────────────────────────────────────────────────────
# PHASE 0: PRE-FLIGHT
# ─────────────────────────────────────────────────────────────────────────────

phase_preflight() {
  show_phase_header "0" "🛫" "Pre-flight Checks" "Verifying environment before installation begins"

  require_root
  detect_os
  log_success "OS detected: ${OS_ID^} ${OS_VERSION} (${OS_CODENAME})"

  # Ensure log file is writable
  touch "$LOG_FILE" 2>/dev/null || { log_error "Cannot write to $LOG_FILE"; exit 1; }
  log_success "Log file ready: $LOG_FILE"

  check_internet

  # Check disk space (minimum 2GB free)
  local free_kb
  free_kb="$(df /var/www 2>/dev/null | awk 'NR==2 {print $4}' || df / | awk 'NR==2 {print $4}')"
  if [ "${free_kb:-0}" -lt 2097152 ]; then
    log_warn "Less than 2GB free disk space. Installation may fail."
  else
    log_success "Disk space: $(( free_kb / 1024 / 1024 ))GB free"
  fi

  # Check RAM (minimum 512MB)
  local mem_kb
  mem_kb="$(grep MemTotal /proc/meminfo | awk '{print $2}')"
  if [ "${mem_kb:-0}" -lt 524288 ]; then
    log_warn "Less than 512MB RAM. Consider upgrading for production use."
  else
    log_success "Memory: $(( mem_kb / 1024 ))MB available"
  fi

  # Check existing installation
  if [ -f "${OWNPAY_INSTALL_DIR}/storage/.installed" ]; then
    echo ""
    log_warn "OwnPay appears to already be installed at ${OWNPAY_INSTALL_DIR}"
    if ! confirm "Reinstall anyway? This will overwrite your existing installation." "n"; then
      printf "\n  ${C_MUTED}Installation cancelled.${C_RESET}\n\n"
      exit 0
    fi
  fi

  phase_done 0
}

# ─────────────────────────────────────────────────────────────────────────────
# PHASE 1: INTERACTIVE WIZARD
# ─────────────────────────────────────────────────────────────────────────────

phase_wizard() {
  show_phase_header "1" "🧙" "Configuration Wizard" "Set up your OwnPay installation"

  if [ "$UNATTENDED" = "1" ]; then
    log_info "Running in unattended mode — reading from environment variables"
    DOMAIN="${OWNPAY_DOMAIN:-}"
    APP_NAME="${OWNPAY_APP_NAME:-OwnPay}"
    TIMEZONE="${OWNPAY_TIMEZONE:-UTC}"
    WEBSERVER="${OWNPAY_WEBSERVER:-nginx}"
    SSL_MODE="${OWNPAY_SSL:-letsencrypt}"
    DB_HOST="${OWNPAY_DB_HOST:-127.0.0.1}"
    DB_PORT="${OWNPAY_DB_PORT:-3306}"
    DB_NAME="${OWNPAY_DB_NAME:-ownpay}"
    DB_USER="${OWNPAY_DB_USER:-ownpay}"
    DB_PASS="${OWNPAY_DB_PASS:-$(gen_password)}"
    USE_REDIS="${OWNPAY_USE_REDIS:-0}"
    ADMIN_NAME="${OWNPAY_ADMIN_NAME:-Administrator}"
    ADMIN_EMAIL="${OWNPAY_ADMIN_EMAIL:-}"
    ADMIN_USERNAME="${OWNPAY_ADMIN_USERNAME:-admin}"
    ADMIN_PASSWORD="${OWNPAY_ADMIN_PASSWORD:-$(gen_password)}"

    if [ -z "$DOMAIN" ] || [ -z "$ADMIN_EMAIL" ]; then
      log_error "Unattended mode requires OWNPAY_DOMAIN and OWNPAY_ADMIN_EMAIL env vars."
      exit 1
    fi
    return
  fi

  # ── Application ──────────────────────────────────────────────────────────

  echo ""
  printf "  ${C_BRAND}${C_BOLD}APPLICATION SETTINGS${C_RESET}\n"
  _divider_thin

  prompt_value "Application name" APP_NAME "OwnPay"
  prompt_value "Your domain (e.g. pay.yourbrand.com)" DOMAIN ""
  while [ -z "$DOMAIN" ]; do
    log_warn "Domain is required."
    prompt_value "Your domain" DOMAIN ""
  done

  # Timezone
  prompt_value "Timezone" TIMEZONE "UTC"

  # ── Web Server ────────────────────────────────────────────────────────────

  prompt_menu \
    "Web Server" \
    "Choose a web server to install and configure" \
    WEBSERVER \
    "nginx"   "Nginx     — Recommended. High performance, low memory." \
    "apache"  "Apache    — Battle-tested. Great .htaccess support." \
    "caddy"   "Caddy     — Modern. Automatic HTTPS out of the box."

  # ── SSL ───────────────────────────────────────────────────────────────────

  if [ "$WEBSERVER" != "caddy" ]; then
    prompt_menu \
      "SSL / HTTPS" \
      "How should the installer provision an SSL certificate?" \
      SSL_MODE \
      "letsencrypt" "Let's Encrypt  — Free, auto-renewing (requires public domain & DNS)" \
      "selfsigned"  "Self-signed    — Generate a local certificate (browser warning)" \
      "skip"        "Skip SSL       — HTTP only (⚠ not recommended for production)"
  else
    SSL_MODE="caddy"
    log_info "Caddy manages SSL automatically — no extra configuration needed."
  fi

  # ── Database ──────────────────────────────────────────────────────────────

  echo ""
  printf "  ${C_BRAND}${C_BOLD}DATABASE SETTINGS${C_RESET}\n"
  _divider_thin

  prompt_value "Database host" DB_HOST "127.0.0.1"
  prompt_value "Database port" DB_PORT "3306"
  prompt_value "Database name" DB_NAME "ownpay"
  prompt_value "Database user" DB_USER "ownpay"
  local db_pass_default
  db_pass_default="$(gen_password)"
  prompt_value "Database password" DB_PASS "$db_pass_default" "1"

  # ── Redis ─────────────────────────────────────────────────────────────────

  prompt_menu \
    "Cache & Queue Driver" \
    "Redis provides better performance; file driver works on any server." \
    _REDIS_CHOICE \
    "redis" "Redis (Recommended for production — will be installed)" \
    "file"  "File driver (Simpler, works everywhere)"

  [ "$_REDIS_CHOICE" = "redis" ] && USE_REDIS=1 || USE_REDIS=0

  # ── Admin Account ─────────────────────────────────────────────────────────

  echo ""
  printf "  ${C_BRAND}${C_BOLD}ADMIN ACCOUNT${C_RESET}\n"
  _divider_thin

  prompt_value "Your full name" ADMIN_NAME "Administrator"
  prompt_value "Admin email address" ADMIN_EMAIL ""
  while [ -z "$ADMIN_EMAIL" ] || ! echo "$ADMIN_EMAIL" | grep -qE '^[^@]+@[^@]+\.[^@]+$'; do
    log_warn "Please enter a valid email address."
    prompt_value "Admin email address" ADMIN_EMAIL ""
  done

  prompt_value "Admin username" ADMIN_USERNAME "admin"
  local pw_default
  pw_default="$(gen_password)"
  prompt_value "Admin password (min 8 chars)" ADMIN_PASSWORD "$pw_default" "1"
  while [ ${#ADMIN_PASSWORD} -lt 8 ]; do
    log_warn "Password must be at least 8 characters."
    prompt_value "Admin password" ADMIN_PASSWORD "" "1"
  done

  # ── Summary ───────────────────────────────────────────────────────────────

  echo ""
  _divider
  local w=72
  _box_top $w
  _box_row "  ${C_WHITE}${C_BOLD}📋  Installation Summary${C_RESET}" $w
  _box_mid $w
  _box_empty $w
  _box_row "  ${C_MUTED}App Name   ${C_RESET}${C_WHITE}${APP_NAME}${C_RESET}" $w
  _box_row "  ${C_MUTED}Domain     ${C_RESET}${C_WHITE}${DOMAIN}${C_RESET}" $w
  _box_row "  ${C_MUTED}Timezone   ${C_RESET}${C_WHITE}${TIMEZONE}${C_RESET}" $w
  _box_row "  ${C_MUTED}Web Server ${C_RESET}${C_WHITE}${WEBSERVER^}${C_RESET}" $w
  _box_row "  ${C_MUTED}SSL Mode   ${C_RESET}${C_WHITE}${SSL_MODE^}${C_RESET}" $w
  _box_row "  ${C_MUTED}Database   ${C_RESET}${C_WHITE}${DB_NAME} @ ${DB_HOST}:${DB_PORT}${C_RESET}" $w
  _box_row "  ${C_MUTED}Redis      ${C_RESET}${C_WHITE}$([ "$USE_REDIS" = "1" ] && echo "Yes" || echo "No (file driver)")${C_RESET}" $w
  _box_row "  ${C_MUTED}Admin      ${C_RESET}${C_WHITE}${ADMIN_USERNAME} <${ADMIN_EMAIL}>${C_RESET}" $w
  _box_row "  ${C_MUTED}Install to ${C_RESET}${C_WHITE}${OWNPAY_INSTALL_DIR}${C_RESET}" $w
  _box_empty $w
  _box_bot $w
  echo ""

  if ! confirm "Proceed with installation?" "y"; then
    printf "\n  ${C_MUTED}Installation cancelled.${C_RESET}\n\n"
    exit 0
  fi

  phase_done 1
}

# ─────────────────────────────────────────────────────────────────────────────
# PHASE 2: SYSTEM PACKAGES
# ─────────────────────────────────────────────────────────────────────────────

phase_system_packages() {
  show_phase_header "2" "📦" "System Packages" "Installing core system dependencies"

  run_quiet "Updating package index" apt-get update -qq
  pkg_install \
      curl wget git unzip zip gnupg ca-certificates \
      software-properties-common lsb-release \
      apt-transport-https dnsutils net-tools \
      openssl cron logrotate
  log_success "Installing base utilities"

  phase_done 2
}

# ─────────────────────────────────────────────────────────────────────────────
# PHASE 3: PHP 8.3
# ─────────────────────────────────────────────────────────────────────────────

phase_php() {
  show_phase_header "3" "🐘" "PHP 8.3" "Installing PHP and all required extensions"

  # Add PHP repository
  if [ "$OS_ID" = "ubuntu" ]; then
    if ! grep -r 'ondrej/php' /etc/apt/sources.list.d/ > /dev/null 2>&1; then
      run_quiet "Adding Ondrej/PHP PPA" \
        add-apt-repository -y ppa:ondrej/php
    else
      log_success "Ondrej/PHP PPA already added"
    fi
  elif [ "$OS_ID" = "debian" ]; then
    if [ ! -f /etc/apt/sources.list.d/php.list ]; then
      spinner_start "Adding Sury PHP repository..."
      curl -sSL --max-time 30 \
        -o /usr/share/keyrings/deb.sury.org-php.gpg \
        https://packages.sury.org/php/apt.gpg >>"$LOG_FILE" 2>&1
      echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] \
https://packages.sury.org/php/ ${OS_CODENAME} main" \
        > /etc/apt/sources.list.d/php.list
      spinner_stop
      log_success "Sury PHP repository added"
    else
      log_success "PHP repository already configured"
    fi
  fi

  run_quiet "Updating package index" apt-get update -qq

  local php_extensions=(
    "php8.3" "php8.3-fpm" "php8.3-cli"
    "php8.3-mysql" "php8.3-pdo"
    "php8.3-bcmath" "php8.3-mbstring"
    "php8.3-xml" "php8.3-curl"
    "php8.3-intl" "php8.3-zip"
    "php8.3-gd" "php8.3-common"
    "php8.3-opcache" "php8.3-readline"
  )

  pkg_install "${php_extensions[@]}"
  log_success "Installing PHP 8.3 + extensions"

  # Determine FPM socket path
  PHP_FPM_SOCK="/run/php/php8.3-fpm.sock"

  # Configure PHP (php.ini tweaks)
  local php_ini
  for ini_path in /etc/php/8.3/fpm/php.ini /etc/php/8.3/cli/php.ini; do
    if [ -f "$ini_path" ]; then
      sed -i 's/^upload_max_filesize.*/upload_max_filesize = 64M/' "$ini_path"
      sed -i 's/^post_max_size.*/post_max_size = 64M/' "$ini_path"
      sed -i 's/^memory_limit.*/memory_limit = 256M/' "$ini_path"
      sed -i 's/^max_execution_time.*/max_execution_time = 120/' "$ini_path"
      sed -i 's/^;date.timezone.*/date.timezone = '"${TIMEZONE}"'/' "$ini_path"
      sed -i 's/^date.timezone.*/date.timezone = '"${TIMEZONE}"'/' "$ini_path"
      sed -i 's/^expose_php.*/expose_php = Off/' "$ini_path"
    fi
  done
  log_success "PHP configuration tuned"

  # Configure OPcache
  local opcache_ini="/etc/php/8.3/fpm/conf.d/10-opcache.ini"
  if [ -f "$opcache_ini" ]; then
    cat >> "$opcache_ini" << 'OPCACHE'

; OwnPay OPcache tuning
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.validate_timestamps=0
opcache.save_comments=1
opcache.fast_shutdown=1
OPCACHE
    log_success "OPcache configured"
  fi

  run_quiet "Restarting PHP-FPM" systemctl restart php8.3-fpm
  run_quiet "Enabling PHP-FPM on boot" systemctl enable php8.3-fpm

  local php_version
  php_version="$(php8.3 -r 'echo PHP_VERSION;' 2>/dev/null || echo "unknown")"
  log_success "PHP ${php_version} is ready"

  phase_done 3
}

# ─────────────────────────────────────────────────────────────────────────────
# PHASE 4: MARIADB
# ─────────────────────────────────────────────────────────────────────────────

phase_mariadb() {
  show_phase_header "4" "🗄️" "MariaDB" "Installing and configuring the database server"

  if cmd_exists mysql && mysql --version 2>/dev/null | grep -qi 'mariadb\|mysql'; then
    log_success "MySQL/MariaDB already installed"
  else
    # Add MariaDB 10.11 LTS repository
    spinner_start "Adding MariaDB repository..."
    local arch
    arch="$(dpkg --print-architecture 2>/dev/null || echo amd64)"
    # --max-time guards against stalled mirror downloads
    curl -fsSL --max-time 120 \
      "https://downloads.mariadb.com/MariaDB/mariadb_repo_setup" | \
      bash -s -- --mariadb-server-version="mariadb-10.11" >> "$LOG_FILE" 2>&1
    spinner_stop
    log_success "MariaDB repository added"

    run_quiet "Updating package index" apt-get update -qq

    # Use pkg_install so DEBIAN_FRONTEND=noninteractive is always set
    spinner_start "Installing MariaDB 10.11..."
    pkg_install mariadb-server mariadb-client
    spinner_stop
    log_success "Installing MariaDB 10.11"
  fi

  # Use timeout to prevent systemctl from hanging indefinitely
  run_quiet "Starting MariaDB" \
    timeout 60 systemctl start mariadb
  run_quiet "Enabling MariaDB on boot" \
    timeout 30 systemctl enable mariadb

  # Wait until MariaDB socket is actually responsive before proceeding
  spinner_start "Waiting for MariaDB to accept connections..."
  local attempts=0
  until mysqladmin ping --silent 2>/dev/null || [ $attempts -ge 30 ]; do
    attempts=$((attempts + 1))
    sleep 1
  done
  spinner_stop
  if [ $attempts -ge 30 ]; then
    log_error "MariaDB did not become ready in time. Check: journalctl -u mariadb"
    exit 1
  fi
  log_success "MariaDB is accepting connections"

  # Secure MariaDB (mysql_secure_installation equivalent, non-interactive)
  spinner_start "Securing MariaDB installation..."
  mysql -e "DELETE FROM mysql.user WHERE User='';"                                              >> "$LOG_FILE" 2>&1 || true
  mysql -e "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost','127.0.0.1','::1');" >> "$LOG_FILE" 2>&1 || true
  mysql -e "DROP DATABASE IF EXISTS test;"                                                       >> "$LOG_FILE" 2>&1 || true
  mysql -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';"                             >> "$LOG_FILE" 2>&1 || true
  mysql -e "FLUSH PRIVILEGES;"                                                                   >> "$LOG_FILE" 2>&1 || true
  spinner_stop
  log_success "MariaDB secured"

  # Create OwnPay database and application user
  spinner_start "Creating OwnPay database and user..."
  mysql << SQL >> "$LOG_FILE" 2>&1
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'${DB_HOST}' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'${DB_HOST}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
  spinner_stop
  log_success "Database '${DB_NAME}' created — user '${DB_USER}' granted access"

  phase_done 4
}

# ─────────────────────────────────────────────────────────────────────────────
# PHASE 5: WEB SERVER
# ─────────────────────────────────────────────────────────────────────────────

phase_webserver() {
  show_phase_header "5" "🌐" "Web Server: ${WEBSERVER^}" "Installing and configuring ${WEBSERVER^}"

  case "$WEBSERVER" in
    nginx)   _install_nginx ;;
    apache)  _install_apache ;;
    caddy)   _install_caddy ;;
  esac

  phase_done 5
}

_install_nginx() {
  if ! cmd_exists nginx; then
    pkg_install nginx
    log_success "Installing Nginx"
  else
    log_success "Nginx already installed"
  fi

  # Write vhost
  local vhost_file="/etc/nginx/sites-available/ownpay"
  cat > "$vhost_file" << NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    root ${OWNPAY_INSTALL_DIR}/public;
    index index.php;
    charset utf-8;

    # OwnPay front controller
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    # Block sensitive files
    location ~ \.(env|git|md|json|lock|installed)$ {
        return 404;
    }

    # Block internal directories
    location ~ ^/(src|tests|vendor|storage|database|config)/ {
        return 404;
    }

    # PHP-FPM
    location ~ \.php$ {
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:${PHP_FPM_SOCK};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;

        # Only allow index.php execution
        if (\$fastcgi_script_name !~ "^/index\\.php$") {
            return 403;
        }
    }

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # Deny hidden files
    location ~ /\. {
        deny all;
    }

    # Logging
    access_log /var/log/nginx/ownpay-access.log;
    error_log  /var/log/nginx/ownpay-error.log;
}
NGINX

  ln -sf "$vhost_file" /etc/nginx/sites-enabled/ownpay 2>/dev/null || true
  rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true

  run_quiet "Testing Nginx configuration" nginx -t
  run_quiet "Reloading Nginx" systemctl reload nginx
  run_quiet "Enabling Nginx on boot" systemctl enable nginx
  log_success "Nginx configured for ${DOMAIN}"
}

_install_apache() {
  if ! cmd_exists apache2; then
    pkg_install apache2
    log_success "Installing Apache2"
  else
    log_success "Apache2 already installed"
  fi

  run_quiet "Enabling mod_rewrite" a2enmod rewrite
  run_quiet "Enabling mod_headers" a2enmod headers

  local vhost_file="/etc/apache2/sites-available/ownpay.conf"
  cat > "$vhost_file" << APACHE
<VirtualHost *:80>
    ServerName ${DOMAIN}
    DocumentRoot ${OWNPAY_INSTALL_DIR}/public

    <Directory ${OWNPAY_INSTALL_DIR}/public>
        AllowOverride All
        Require all granted
        DirectoryIndex index.php
    </Directory>

    # Block sensitive directories
    <DirectoryMatch "/(src|tests|vendor|storage|database|config)">
        Require all denied
    </DirectoryMatch>

    # PHP-FPM
    <FilesMatch \.php$>
        SetHandler "proxy:unix:${PHP_FPM_SOCK}|fcgi://localhost"
    </FilesMatch>

    # Security headers
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"

    ErrorLog \${APACHE_LOG_DIR}/ownpay-error.log
    CustomLog \${APACHE_LOG_DIR}/ownpay-access.log combined
</VirtualHost>
APACHE

  run_quiet "Enabling proxy_fcgi" a2enmod proxy_fcgi
  run_quiet "Enabling OwnPay vhost" a2ensite ownpay
  run_quiet "Disabling default vhost" a2dissite 000-default || true
  run_quiet "Restarting Apache2" systemctl restart apache2
  run_quiet "Enabling Apache2 on boot" systemctl enable apache2
  log_success "Apache2 configured for ${DOMAIN}"
}

_install_caddy() {
  if ! cmd_exists caddy; then
    spinner_start "Adding Caddy repository..."
    curl -1sLf --max-time 30 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | \
      gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg >>"$LOG_FILE" 2>&1
    curl -1sLf --max-time 30 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | \
      tee /etc/apt/sources.list.d/caddy-stable.list >>"$LOG_FILE" 2>&1
    spinner_stop
    run_quiet "Updating package index" apt-get update -qq
    pkg_install caddy
    log_success "Installing Caddy"
  else
    log_success "Caddy already installed"
  fi

  cat > /etc/caddy/Caddyfile << CADDY
${DOMAIN} {
    root * ${OWNPAY_INSTALL_DIR}/public
    encode gzip

    php_fastcgi unix/${PHP_FPM_SOCK}

    # Only allow index.php execution
    @notindex {
        path_regexp php \.php$
        not path /index.php
    }
    respond @notindex 403

    # Block sensitive paths
    @blocked {
        path *.env *.git *.md *.lock *.installed
        path /src/* /tests/* /vendor/* /storage/* /database/* /config/*
    }
    respond @blocked 404

    file_server
    log {
        output file /var/log/caddy/ownpay-access.log
    }
}
CADDY

  run_quiet "Reloading Caddy" systemctl reload caddy 2>/dev/null || \
    run_quiet "Starting Caddy" systemctl start caddy
  run_quiet "Enabling Caddy on boot" systemctl enable caddy
  log_success "Caddy configured for ${DOMAIN} with automatic HTTPS"
}

# ─────────────────────────────────────────────────────────────────────────────
# PHASE 6: SSL
# ─────────────────────────────────────────────────────────────────────────────

phase_ssl() {
  show_phase_header "6" "🔒" "SSL / HTTPS" "Provisioning SSL certificate for ${DOMAIN}"

  case "$SSL_MODE" in
    letsencrypt) _ssl_letsencrypt ;;
    selfsigned)  _ssl_selfsigned ;;
    caddy)       log_info "Caddy handles SSL automatically — no action needed." ;;
    skip)        log_warn "SSL skipped. Site will be served over HTTP only." ;;
  esac

  phase_done 6
}

_ssl_letsencrypt() {
  if ! cmd_exists certbot; then
    pkg_install certbot
    log_success "Installing Certbot"

    case "$WEBSERVER" in
      nginx)  pkg_install python3-certbot-nginx;  log_success "Installing Certbot Nginx plugin"  ;;
      apache) pkg_install python3-certbot-apache; log_success "Installing Certbot Apache plugin" ;;
    esac
  else
    log_success "Certbot already installed"
  fi

  spinner_start "Issuing Let's Encrypt certificate for ${DOMAIN}..."
  local certbot_args=(
    --non-interactive
    --agree-tos
    --no-eff-email
    -m "$ADMIN_EMAIL"
    -d "$DOMAIN"
  )

  case "$WEBSERVER" in
    nginx)
      certbot --nginx "${certbot_args[@]}" >> "$LOG_FILE" 2>&1 || {
        spinner_stop
        log_warn "Let's Encrypt failed (DNS may not be pointing to this server yet)."
        log_warn "You can run certbot manually later: sudo certbot --nginx -d ${DOMAIN}"
        return
      }
      ;;
    apache)
      certbot --apache "${certbot_args[@]}" >> "$LOG_FILE" 2>&1 || {
        spinner_stop
        log_warn "Let's Encrypt failed. Run: sudo certbot --apache -d ${DOMAIN}"
        return
      }
      ;;
  esac
  spinner_stop
  log_success "SSL certificate issued for ${DOMAIN}"

  # Enable auto-renewal via cron
  if ! crontab -l 2>/dev/null | grep -q 'certbot renew'; then
    (crontab -l 2>/dev/null || true; echo "0 3 * * * /usr/bin/certbot renew --quiet") | crontab -
    log_success "Auto-renewal cron job added"
  fi
}

_ssl_selfsigned() {
  local ssl_dir="/etc/ssl/ownpay"
  mkdir -p "$ssl_dir"
  spinner_start "Generating self-signed certificate..."
  openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout "${ssl_dir}/privkey.pem" \
    -out "${ssl_dir}/fullchain.pem" \
    -subj "/C=US/ST=State/L=City/O=OwnPay/CN=${DOMAIN}" \
    >> "$LOG_FILE" 2>&1
  chmod 600 "${ssl_dir}/privkey.pem"
  spinner_stop
  log_success "Self-signed certificate created at ${ssl_dir}"

  # Update web server config for HTTPS
  if [ "$WEBSERVER" = "nginx" ]; then
    cat > "/etc/nginx/sites-available/ownpay" << NGINX_SSL
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    return 301 https://\$host\$request_uri;
}
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name ${DOMAIN};
    root ${OWNPAY_INSTALL_DIR}/public;
    index index.php;
    charset utf-8;

    ssl_certificate     ${ssl_dir}/fullchain.pem;
    ssl_certificate_key ${ssl_dir}/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    location ~ \.(env|git|md|json|lock|installed)$ { return 404; }
    location ~ ^/(src|tests|vendor|storage|database|config)/ { return 404; }
    location ~ \.php$ {
        fastcgi_pass unix:${PHP_FPM_SOCK};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
        if (\$fastcgi_script_name !~ "^/index\\.php$") { return 403; }
    }
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Strict-Transport-Security "max-age=31536000" always;
    location ~ /\. { deny all; }
}
NGINX_SSL
    run_quiet "Reloading Nginx with SSL config" systemctl reload nginx
  fi

  log_warn "Self-signed cert active. Browsers will show a security warning."
  log_warn "Replace with a real certificate when your domain's DNS is ready."
}

# ─────────────────────────────────────────────────────────────────────────────
# PHASE 7: COMPOSER
# ─────────────────────────────────────────────────────────────────────────────

phase_composer() {
  show_phase_header "7" "🎼" "Composer 2" "Installing PHP dependency manager"

  # Export env vars that prevent all interactive prompts and network checks
  export COMPOSER_NO_INTERACTION=1
  export COMPOSER_DISABLE_XDEBUG_WARN=1
  export COMPOSER_MEMORY_LIMIT=-1

  if cmd_exists composer && composer --version --no-interaction 2>/dev/null | grep -q 'Composer version 2'; then
    log_success "Composer 2 already installed"
  else
    # Download the installer with a hard timeout
    spinner_start "Downloading Composer installer..."
    curl -fsSL --max-time 60 \
      https://getcomposer.org/installer \
      -o /tmp/composer-setup.php >>"$LOG_FILE" 2>&1
    spinner_stop

    # Verify SHA-384 checksum (skip gracefully if sig endpoint is unreachable)
    spinner_start "Verifying Composer installer checksum..."
    local expected actual
    expected="$(curl -fsSL --max-time 15 \
      https://composer.github.io/installer.sig 2>/dev/null || echo '')"
    actual="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');" 2>/dev/null || echo '')"
    spinner_stop

    if [ -n "$expected" ] && [ -n "$actual" ]; then
      if [ "$actual" != "$expected" ]; then
        log_error "Composer installer checksum mismatch — aborting (tampered download?)"
        rm -f /tmp/composer-setup.php
        exit 1
      fi
      log_success "Composer installer verified (SHA-384 checksum OK)"
    else
      log_warn "Checksum verification skipped (signature endpoint unreachable)"
    fi

    # Install — fully non-interactive, memory-unlimited, quiet
    spinner_start "Installing Composer globally..."
    php /tmp/composer-setup.php \
      --quiet \
      --no-interaction \
      --install-dir=/usr/local/bin \
      --filename=composer >>"$LOG_FILE" 2>&1
    spinner_stop
    rm -f /tmp/composer-setup.php
  fi

  # Disable Composer's automatic update check (prevents network pause on each run)
  composer config --global allow-plugins.* true >>"$LOG_FILE" 2>&1 || true

  log_success "$(composer --version --no-interaction 2>/dev/null)"
  phase_done 7
}


# ─────────────────────────────────────────────────────────────────────────────
# PHASE 8: OWNPAY DEPLOYMENT
# ─────────────────────────────────────────────────────────────────────────────

phase_deploy() {
  show_phase_header "8" "🚀" "OwnPay Deployment" "Downloading and deploying OwnPay from GitHub Releases"

  # ── Case A: Running piped from inside the OwnPay source tree ─────────────
  if [ -f "$(pwd)/composer.json" ] && grep -q '"own-pay/ownpay"' "$(pwd)/composer.json" 2>/dev/null; then
    local src_dir="$(pwd)"
    log_info "Detected local OwnPay source at: ${src_dir}"
    if [ "$src_dir" != "$OWNPAY_INSTALL_DIR" ]; then
      spinner_start "Copying OwnPay files to ${OWNPAY_INSTALL_DIR}..."
      mkdir -p "$OWNPAY_INSTALL_DIR"
      rsync -a --exclude='.git' --exclude='vendor' --exclude='.env' \
        --exclude='storage/.installed' --exclude='.planning' \
        "${src_dir}/" "${OWNPAY_INSTALL_DIR}/" >> "$LOG_FILE" 2>&1
      spinner_stop
      log_success "Files copied to ${OWNPAY_INSTALL_DIR}"
    fi

  # ── Case B: Already deployed ─────────────────────────────────────────────
  elif [ -d "$OWNPAY_INSTALL_DIR" ] && [ -f "${OWNPAY_INSTALL_DIR}/composer.json" ]; then
    log_success "OwnPay files already present at ${OWNPAY_INSTALL_DIR}"

  # ── Case C: Download from OwnPay update manifest ────────────────────────────
  else
    # ─ Step 1: Fetch manifest.json from the OwnPay update server ─────────────
    spinner_start "Fetching OwnPay release manifest..."
    local manifest version zip_url zip_name checksum_expected size_bytes
    manifest="$(curl -fsSL --max-time 30 \
      -H 'Accept: application/json' \
      "${OWNPAY_MANIFEST_URL}" 2>>"$LOG_FILE" || echo '{}')"
    spinner_stop

    # Parse channels.beta fields using grep+sed (no jq required)
    # Field path: .channels.beta.latest_version_name
    version="$(printf '%s' "$manifest" | \
      grep -o '"latest_version_name":"[^"]*"' | head -1 | \
      sed 's/"latest_version_name":"//;s/"//')" || true

    # Field path: .channels.beta.download_url
    zip_url="$(printf '%s' "$manifest" | \
      grep -o '"download_url":"[^"]*"' | head -1 | \
      sed 's/"download_url":"//;s/"//')" || true

    # Field path: .channels.beta.checksum_sha256
    checksum_expected="$(printf '%s' "$manifest" | \
      grep -o '"checksum_sha256":"[^"]*"' | head -1 | \
      sed 's/"checksum_sha256":"//;s/"//')" || true

    # Field path: .channels.beta.size_bytes  (for display only)
    size_bytes="$(printf '%s' "$manifest" | \
      grep -o '"size_bytes":[0-9]*' | head -1 | \
      sed 's/"size_bytes"://')" || true

    if [ -n "$version" ] && [ -n "$zip_url" ]; then
      zip_name="ownpay-${version}.zip"
      local size_mb="?"
      [ -n "$size_bytes" ] && size_mb="$(( size_bytes / 1048576 ))"
      log_success "Latest version: ${C_BOLD}${version}${C_RESET}  (${size_mb} MB)"
    else
      # Manifest unreachable or malformed — fall back to GitHub main branch
      log_warn "Update manifest unavailable — falling back to GitHub main branch"
      version="main"
      zip_name="OwnPay-main.zip"
      zip_url="${OWNPAY_GITHUB_FALLBACK}"
      checksum_expected=""
    fi

    # ─ Step 2: Animated download with live KB counter ──────────────────────
    local tmp_zip="/tmp/${zip_name}"
    rm -f "$tmp_zip"

    printf "\n  ${C_BRAND}${C_BOLD}\u25b8${C_RESET}  ${C_WHITE}${C_BOLD}%s${C_RESET}\n" "$zip_name"
    printf "  ${C_MUTED}  %s${C_RESET}\n\n" "$zip_url"

    # Named function — local is valid here (fixes the subshell local bug)
    _dl_anim_loop() {
      local frames=('\u280b' '\u2819' '\u2839' '\u2838' '\u283c' '\u2834' '\u2826' '\u2827' '\u2807' '\u280f')
      local out_file="$1"
      local i=0 kb=0
      while true; do
        [ -f "$out_file" ] && kb="$(du -k "$out_file" 2>/dev/null | cut -f1)" || kb=0
        printf "\r  ${C_BRAND}%s${C_RESET}  ${C_WHITE}${C_BOLD}Downloading...${C_RESET}  ${C_MUTED}%s KB received${C_RESET}   " \
          "${frames[$(( i % ${#frames[@]} ))]}" "$kb"
        sleep 0.15
        i=$(( i + 1 ))
      done
    }
    tput civis 2>/dev/null || true
    _dl_anim_loop "$tmp_zip" &
    local dl_pid=$!
    disown "$dl_pid" 2>/dev/null || true

    local curl_rc=0
    curl -fL --max-time 300 "$zip_url" -o "$tmp_zip" >>"$LOG_FILE" 2>&1 || curl_rc=$?

    kill "$dl_pid" 2>/dev/null || true
    wait "$dl_pid" 2>/dev/null || true
    printf "\r\033[2K"
    tput cnorm 2>/dev/null || true

    if [ "$curl_rc" -ne 0 ] || [ ! -s "$tmp_zip" ]; then
      log_error "Download FAILED  (curl exit: ${curl_rc})"
      log_error "URL: ${zip_url}"
      log_error "Details: tail -30 ${LOG_FILE}"
      exit 1
    fi

    local dl_kb
    dl_kb="$(du -k "$tmp_zip" 2>/dev/null | cut -f1 || echo '?')"
    log_success "Download complete — ${dl_kb} KB  →  /tmp/${zip_name}"

    # ─ Step 3: SHA-256 checksum verification ────────────────────────────────
    if [ -n "$checksum_expected" ] && command -v sha256sum >/dev/null 2>&1; then
      spinner_start "Verifying SHA-256 checksum..."
      local checksum_actual
      checksum_actual="$(sha256sum "$tmp_zip" | cut -d' ' -f1)"
      spinner_stop
      if [ "$checksum_actual" = "$checksum_expected" ]; then
        log_success "Checksum verified ✔  ${checksum_actual:0:16}..."
      else
        log_error "Checksum MISMATCH — the download may be corrupted or tampered with"
        log_error "  Expected: ${checksum_expected}"
        log_error "  Got:      ${checksum_actual}"
        rm -f "$tmp_zip"
        exit 1
      fi
    else
      [ -z "$checksum_expected" ] && log_info "No checksum in manifest — skipping verification"
    fi

    # ─ Step 4: Extract ────────────────────────────────────────────────────
    local tmp_extract="/tmp/ownpay-extract-$$"
    rm -rf "$tmp_extract"
    mkdir -p "$tmp_extract"

    spinner_start "Extracting ${zip_name}..."
    if ! unzip -q "$tmp_zip" -d "$tmp_extract" >>"$LOG_FILE" 2>&1; then
      spinner_stop
      log_error "Extraction failed — file may be corrupt. Check: tail -20 ${LOG_FILE}"
      exit 1
    fi
    spinner_stop
    log_success "Archive extracted"

    # ZIP may nest under a wrapper dir: ownpay-0.1.0/ or OwnPay-main/
    local source_dir="$tmp_extract"
    local nested
    nested="$(find "$tmp_extract" -mindepth 1 -maxdepth 1 -type d | head -1)"
    [ -n "$nested" ] && [ -f "${nested}/composer.json" ] && source_dir="$nested"

    if [ ! -f "${source_dir}/composer.json" ]; then
      log_error "composer.json not found in extracted archive — invalid package"
      exit 1
    fi

    mkdir -p "$OWNPAY_INSTALL_DIR"
    spinner_start "Installing files to ${OWNPAY_INSTALL_DIR}..."
    rsync -a "${source_dir}/" "${OWNPAY_INSTALL_DIR}/" >>"$LOG_FILE" 2>&1
    spinner_stop

    rm -rf "$tmp_extract" "$tmp_zip"
    log_success "OwnPay ${version} installed to ${OWNPAY_INSTALL_DIR}"
  fi

  # ── Storage directories ────────────────────────────────────────────────────
  local dirs_writable=(
    "${OWNPAY_INSTALL_DIR}/storage"
    "${OWNPAY_INSTALL_DIR}/storage/logs"
    "${OWNPAY_INSTALL_DIR}/storage/cache"
    "${OWNPAY_INSTALL_DIR}/storage/sessions"
    "${OWNPAY_INSTALL_DIR}/storage/uploads"
    "${OWNPAY_INSTALL_DIR}/storage/backups"
    "${OWNPAY_INSTALL_DIR}/storage/languages"
    "${OWNPAY_INSTALL_DIR}/public/assets/img/gateways"
  )
  for d in "${dirs_writable[@]}"; do
    mkdir -p "$d"
  done
  log_success "Storage directories ready"

  # ── Composer dependencies ─────────────────────────────────────────────────
  # Skip if vendor/autoload.php already exists (re-run safety)
  if [ -f "${OWNPAY_INSTALL_DIR}/vendor/autoload.php" ]; then
    log_success "Composer dependencies already installed (vendor/ exists — skipping)"
  else
    export COMPOSER_NO_INTERACTION=1
    export COMPOSER_MEMORY_LIMIT=-1
    run_quiet "Installing Composer dependencies" \
      composer install \
        --working-dir="$OWNPAY_INSTALL_DIR" \
        --no-dev \
        --optimize-autoloader \
        --no-interaction \
        --no-audit \
        --quiet
  fi

  # ── File ownership and permissions ────────────────────────────────────────
  spinner_start "Setting file permissions..."
  chown -R "${WEBSERVER_USER}:${WEBSERVER_USER}" "$OWNPAY_INSTALL_DIR"
  find "$OWNPAY_INSTALL_DIR" -type f -exec chmod 644 {} \;
  find "$OWNPAY_INSTALL_DIR" -type d -exec chmod 755 {} \;
  find "${OWNPAY_INSTALL_DIR}/storage" -type d -exec chmod 775 {} \;
  chmod 644 "${OWNPAY_INSTALL_DIR}/public/.htaccess" 2>/dev/null || true
  spinner_stop
  log_success "File permissions configured"

  # ── OwnPay CLI ────────────────────────────────────────────────────────────
  if [ -f "${OWNPAY_INSTALL_DIR}/cli/ownpay" ]; then
    chmod +x "${OWNPAY_INSTALL_DIR}/cli/ownpay"
    ln -sf "${OWNPAY_INSTALL_DIR}/cli/ownpay" /usr/local/bin/ownpay 2>/dev/null || true
    log_success "ownpay CLI linked to /usr/local/bin/ownpay"
  fi

  phase_done 8
}

# ─────────────────────────────────────────────────────────────────────────────
# PHASE 9: REDIS (OPTIONAL)
# ─────────────────────────────────────────────────────────────────────────────

phase_redis() {
  show_phase_header "9" "⚡" "Redis" "Installing Redis cache & queue server"

  if [ "$USE_REDIS" != "1" ]; then
    log_info "Redis skipped — using file driver"
    phase_done 9
    return
  fi

  if ! cmd_exists redis-server; then
    pkg_install redis-server
    log_success "Installing Redis server"
    pkg_install php8.3-redis
    log_success "Installing PHP Redis extension"
  else
    log_success "Redis already installed"
  fi

  # Configure Redis
  sed -i 's/^# maxmemory-policy.*/maxmemory-policy allkeys-lru/' /etc/redis/redis.conf 2>/dev/null || true

  run_quiet "Restarting Redis" systemctl restart redis-server
  run_quiet "Enabling Redis on boot" systemctl enable redis-server
  run_quiet "Restarting PHP-FPM" systemctl restart php8.3-fpm
  log_success "Redis is running and ready"

  phase_done 9
}

# ─────────────────────────────────────────────────────────────────────────────
# PHASE 10: ENVIRONMENT CONFIGURATION
# ─────────────────────────────────────────────────────────────────────────────

phase_env() {
  show_phase_header "10" "⚙️" "Environment" "Generating .env configuration file"

  local env_file="${OWNPAY_INSTALL_DIR}/.env"
  local env_example="${OWNPAY_INSTALL_DIR}/.env.example"

  if [ ! -f "$env_example" ]; then
    log_error ".env.example not found at ${env_example}"
    exit 1
  fi

  # Determine scheme based on SSL mode
  local scheme="http"
  [[ "$SSL_MODE" =~ ^(letsencrypt|selfsigned|caddy)$ ]] && scheme="https"

  local app_url="${scheme}://${DOMAIN}"
  local cache_driver="file"
  local queue_driver="file"
  [ "$USE_REDIS" = "1" ] && cache_driver="redis" && queue_driver="redis"

  spinner_start "Generating encryption secrets..."
  local app_key
  app_key="$(openssl rand -base64 32)"
  local encryption_key
  encryption_key="$(openssl rand -base64 32)"
  local hmac_key
  hmac_key="$(gen_secret)"
  local jwt_secret
  jwt_secret="$(gen_secret)"
  local audit_hmac
  audit_hmac="$(gen_secret)"
  spinner_stop
  log_success "Cryptographic secrets generated"

  # Build .env from .env.example with substitutions
  cp "$env_example" "$env_file"

  _env_set() {
    local key="$1"
    local val="$2"
    # Escape special characters for sed
    local safe_val
    safe_val="$(printf '%s' "$val" | sed 's/[\/&]/\\&/g')"
    sed -i "s|^${key}=.*|${key}=${safe_val}|" "$env_file" || true
    # If key doesn't exist, append it
    if ! grep -q "^${key}=" "$env_file"; then
      echo "${key}=${val}" >> "$env_file"
    fi
  }

  _env_set "APP_NAME"        "\"${APP_NAME}\""
  _env_set "APP_ENV"         "production"
  _env_set "APP_DEBUG"       "false"
  _env_set "APP_URL"         "${app_url}"
  _env_set "APP_DOMAIN"      "${DOMAIN}"
  _env_set "APP_KEY"         "base64:${app_key}"
  _env_set "APP_TIMEZONE"    "${TIMEZONE}"

  _env_set "DB_HOST"         "${DB_HOST}"
  _env_set "DB_PORT"         "${DB_PORT}"
  _env_set "DB_NAME"         "${DB_NAME}"
  _env_set "DB_USER"         "${DB_USER}"
  _env_set "DB_PASS"         "${DB_PASS}"
  _env_set "DB_PREFIX"       "op_"
  _env_set "DB_CHARSET"      "utf8mb4"
  _env_set "DB_COLLATION"    "utf8mb4_unicode_ci"

  _env_set "ENCRYPTION_KEY"  "base64:${encryption_key}"
  _env_set "SESSION_LIFETIME" "86400"
  _env_set "SESSION_SECURE"  "$([ "$scheme" = "https" ] && echo "true" || echo "false")"
  _env_set "BCRYPT_COST"     "12"

  _env_set "JWT_SECRET"      "${jwt_secret}"
  _env_set "JWT_TTL"         "900"
  _env_set "JWT_REFRESH_TTL" "7776000"

  _env_set "CACHE_DRIVER"    "${cache_driver}"
  _env_set "QUEUE_DRIVER"    "${queue_driver}"
  _env_set "CACHE_TTL"       "3600"

  if [ "$USE_REDIS" = "1" ]; then
    _env_set "REDIS_HOST"    "127.0.0.1"
    _env_set "REDIS_PORT"    "6379"
    _env_set "REDIS_PASSWORD" ""
    _env_set "REDIS_DB"      "0"
    _env_set "REDIS_PREFIX"  "op:"
  fi

  _env_set "LOG_CHANNEL"     "file"
  _env_set "LOG_LEVEL"       "error"
  _env_set "LOG_MAX_FILES"   "7"

  _env_set "HMAC_KEY"        "${hmac_key}"
  _env_set "AUDIT_HMAC_SECRET" "${audit_hmac}"

  _env_set "RATE_LIMIT_PER_MINUTE" "60"
  _env_set "RATE_LIMIT_BURST"      "20"

  # Secure the .env file
  chown "${WEBSERVER_USER}:${WEBSERVER_USER}" "$env_file"
  chmod 640 "$env_file"
  log_success ".env file written and secured (chmod 640)"

  phase_done 10
}

# ─────────────────────────────────────────────────────────────────────────────
# PHASE 11: HEADLESS WEB INSTALLER
# ─────────────────────────────────────────────────────────────────────────────

phase_web_installer() {
  show_phase_header "11" "🏗️" "OwnPay Setup Wizard" "Running the installation wizard headlessly"

  local base_url="http://127.0.0.1"
  local host_header="Host: ${DOMAIN}"

  # Give web server a moment to start
  sleep 2

  # ── Step 1: Test database connection ────────────────────────────────────
  spinner_start "Step 1/4 — Testing database connection..."
  local resp
  resp="$(curl -fsSL --max-time 30 \
    -H "$host_header" \
    -H 'Content-Type: application/json' \
    -d "{\"host\":\"${DB_HOST}\",\"port\":${DB_PORT},\"name\":\"${DB_NAME}\",\"user\":\"${DB_USER}\",\"pass\":\"${DB_PASS}\",\"prefix\":\"op_\"}" \
    "${base_url}/install/test-db" 2>&1 || echo '{"success":false,"error":"curl failed"}')"
  spinner_stop

  if echo "$resp" | grep -q '"success":true'; then
    log_success "Database connection verified"
  else
    local err
    err="$(echo "$resp" | grep -o '"error":"[^"]*"' | sed 's/"error":"//;s/"//')" || true
    log_warn "DB test response: ${err:-$resp}"
    log_warn "Proceeding — the web installer at ${base_url}/install can complete this manually."
  fi

  # ── Step 2: Import schema ────────────────────────────────────────────────
  spinner_start "Step 2/4 — Importing database schema..."
  resp="$(curl -fsSL --max-time 60 \
    -H "$host_header" \
    -H 'Content-Type: application/json' \
    -d "{\"host\":\"${DB_HOST}\",\"port\":${DB_PORT},\"name\":\"${DB_NAME}\",\"user\":\"${DB_USER}\",\"pass\":\"${DB_PASS}\",\"prefix\":\"op_\",\"confirm_overwrite\":true}" \
    "${base_url}/install/import-schema" 2>&1 || echo '{"success":false,"error":"curl failed"}')"
  spinner_stop

  if echo "$resp" | grep -q '"success":true'; then
    log_success "Database schema imported successfully"
  else
    local err
    err="$(echo "$resp" | grep -o '"error":"[^"]*"' | sed 's/"error":"//;s/"//')" || true
    log_error "Schema import failed: ${err:-$resp}"
    log_warn "You can complete setup manually at: http://${DOMAIN}/install"
    phase_done 11
    return
  fi

  # ── Step 3: Create admin account ────────────────────────────────────────
  spinner_start "Step 3/4 — Creating admin account..."
  resp="$(curl -fsSL --max-time 30 \
    -H "$host_header" \
    -H 'Content-Type: application/json' \
    -d "{\"name\":\"${ADMIN_NAME}\",\"email\":\"${ADMIN_EMAIL}\",\"username\":\"${ADMIN_USERNAME}\",\"password\":\"${ADMIN_PASSWORD}\"}" \
    "${base_url}/install/create-admin" 2>&1 || echo '{"success":false,"error":"curl failed"}')"
  spinner_stop

  if echo "$resp" | grep -q '"success":true'; then
    log_success "Admin account created for ${ADMIN_USERNAME}"
  else
    local err
    err="$(echo "$resp" | grep -o '"error":"[^"]*"' | sed 's/"error":"//;s/"//')" || true
    log_error "Admin creation failed: ${err:-$resp}"
    log_warn "Complete manually at: http://${DOMAIN}/install"
    phase_done 12
    return
  fi

  # ── Step 4: Finalize ─────────────────────────────────────────────────────
  spinner_start "Step 4/4 — Finalizing installation..."
  resp="$(curl -fsSL --max-time 30 \
    -H "$host_header" \
    -H 'Content-Type: application/json' \
    -d "{\"app_name\":\"${APP_NAME}\",\"currency\":\"USD\",\"timezone\":\"${TIMEZONE}\"}" \
    "${base_url}/install/finalize" 2>&1 || echo '{"success":false,"error":"curl failed"}')"
  spinner_stop

  if echo "$resp" | grep -q '"success":true'; then
    log_success "Installation finalized successfully"
  else
    local err
    err="$(echo "$resp" | grep -o '"error":"[^"]*"' | sed 's/"error":"//;s/"//')" || true
    log_warn "Finalize response: ${err:-$resp}"
    log_warn "This may be normal if .env was already written. Checking for .installed marker..."
  fi

  # Verify the installation marker
  if [ -f "${OWNPAY_INSTALL_DIR}/storage/.installed" ]; then
    log_success "Installation marker confirmed: storage/.installed"
  else
    log_warn "Marker file missing — please visit http://${DOMAIN}/install to complete setup."
  fi

  phase_done 11
}

# ─────────────────────────────────────────────────────────────────────────────
# PHASE 12: FIREWALL
# ─────────────────────────────────────────────────────────────────────────────

phase_firewall() {
  show_phase_header "12" "🛡️" "Firewall (UFW)" "Hardening server with UFW rules"

  if ! cmd_exists ufw; then
    pkg_install ufw
    log_success "Installing UFW"
  fi

  # Allow SSH (safety first — prevent lockout)
  ufw allow OpenSSH >> "$LOG_FILE" 2>&1 || true
  ufw allow 80/tcp >> "$LOG_FILE" 2>&1 || true
  ufw allow 443/tcp >> "$LOG_FILE" 2>&1 || true

  # Enable without prompt
  echo "y" | ufw enable >> "$LOG_FILE" 2>&1 || true

  log_success "UFW enabled: SSH + HTTP (80) + HTTPS (443) allowed"
  log_warn "All other ports are blocked by default"

  # Fail2ban (optional, best-effort)
  if apt-cache show fail2ban > /dev/null 2>&1; then
    pkg_install fail2ban
    log_success "Installing Fail2ban"
    systemctl enable fail2ban >> "$LOG_FILE" 2>&1 || true
    systemctl start fail2ban >> "$LOG_FILE" 2>&1 || true
    log_success "Fail2ban active — brute force protection enabled"
  fi

  phase_done 12
}

# ─────────────────────────────────────────────────────────────────────────────
# PHASE 13: LOGROTATE & CRON
# ─────────────────────────────────────────────────────────────────────────────

phase_finishing() {
  show_phase_header "13" "🏁" "Final Configuration" "Setting up log rotation and scheduled tasks"

  # Log rotation for OwnPay logs
  cat > /etc/logrotate.d/ownpay << LOGROTATE
${OWNPAY_INSTALL_DIR}/storage/logs/*.log {
    daily
    missingok
    rotate 7
    compress
    delaycompress
    notifempty
    create 664 ${WEBSERVER_USER} ${WEBSERVER_USER}
}
LOGROTATE
  log_success "Log rotation configured (7 days retention)"

  # Cron job for OwnPay scheduler (if CLI exists)
  if [ -f "${OWNPAY_INSTALL_DIR}/cli/ownpay" ]; then
    if ! crontab -l -u "${WEBSERVER_USER}" 2>/dev/null | grep -q 'ownpay'; then
      (crontab -l -u "${WEBSERVER_USER}" 2>/dev/null || true; \
       echo "* * * * * php ${OWNPAY_INSTALL_DIR}/cli/ownpay schedule:run >> /dev/null 2>&1") | \
        crontab -u "${WEBSERVER_USER}" - 2>/dev/null || true
      log_success "OwnPay scheduler cron job added"
    fi
  fi

  # Write credentials to a secure summary file
  local creds_file="/root/.ownpay-credentials"
  cat > "$creds_file" << CREDS
# OwnPay Installation Credentials
# Generated: $(date)
# KEEP THIS FILE SECURE - DELETE AFTER NOTING CREDENTIALS

Admin URL:      https://${DOMAIN}/admin
Admin Username: ${ADMIN_USERNAME}
Admin Password: ${ADMIN_PASSWORD}
Admin Email:    ${ADMIN_EMAIL}

Database:       ${DB_NAME}
DB User:        ${DB_USER}
DB Password:    ${DB_PASS}

Install Dir:    ${OWNPAY_INSTALL_DIR}
Log File:       ${LOG_FILE}
CREDS
  chmod 600 "$creds_file"
  log_success "Credentials saved to ${creds_file} (chmod 600 — root only)"

  checkpoint_clear

  phase_done 13
}

# ─────────────────────────────────────────────────────────────────────────────
# FINAL SUMMARY
# ─────────────────────────────────────────────────────────────────────────────

show_summary() {
  local scheme="https"
  [[ "$SSL_MODE" =~ ^(skip)$ ]] && [ "$WEBSERVER" != "caddy" ] && scheme="http"
  local admin_url="${scheme}://${DOMAIN}/admin"

  echo ""
  echo ""
  printf "${C_SUCCESS}${C_BOLD}"
  cat << 'EOF'
  ╔══════════════════════════════════════════════════════════════════════╗
  ║                                                                      ║
  ║    🎉  OwnPay Installation Complete!                                 ║
  ║                                                                      ║
  ╚══════════════════════════════════════════════════════════════════════╝
EOF
  printf "${C_RESET}"

  local w=72
  echo ""
  _box_top $w
  _box_row "  ${C_WHITE}${C_BOLD}🌐  Access Your Dashboard${C_RESET}" $w
  _box_mid $w
  _box_empty $w
  _box_row "  ${C_MUTED}Admin Panel  ${C_RESET}${C_ACCENT}${C_BOLD}${admin_url}${C_RESET}" $w
  _box_row "  ${C_MUTED}Username     ${C_RESET}${C_WHITE}${ADMIN_USERNAME}${C_RESET}" $w
  _box_row "  ${C_MUTED}Password     ${C_RESET}${C_WARN}${ADMIN_PASSWORD}${C_RESET}" $w
  _box_empty $w
  _box_mid $w
  _box_row "  ${C_WHITE}${C_BOLD}🔧  Installation Details${C_RESET}" $w
  _box_mid $w
  _box_empty $w
  _box_row "  ${C_MUTED}Domain       ${C_RESET}${C_WHITE}${DOMAIN}${C_RESET}" $w
  _box_row "  ${C_MUTED}Web Server   ${C_RESET}${C_WHITE}${WEBSERVER^}${C_RESET}" $w
  _box_row "  ${C_MUTED}PHP          ${C_RESET}${C_WHITE}8.3 (FPM)${C_RESET}" $w
  _box_row "  ${C_MUTED}Database     ${C_RESET}${C_WHITE}MariaDB → ${DB_NAME}${C_RESET}" $w
  _box_row "  ${C_MUTED}Install Dir  ${C_RESET}${C_WHITE}${OWNPAY_INSTALL_DIR}${C_RESET}" $w
  _box_row "  ${C_MUTED}Log File     ${C_RESET}${C_WHITE}${LOG_FILE}${C_RESET}" $w
  _box_row "  ${C_MUTED}Credentials  ${C_RESET}${C_WHITE}/root/.ownpay-credentials${C_RESET}" $w
  _box_empty $w
  _box_mid $w
  _box_row "  ${C_WHITE}${C_BOLD}📋  Next Steps${C_RESET}" $w
  _box_mid $w
  _box_empty $w
  _box_row "  ${C_BRAND}${C_BOLD}1.${C_RESET}  ${C_WHITE}Log in at the admin URL above${C_RESET}" $w
  _box_row "  ${C_BRAND}${C_BOLD}2.${C_RESET}  ${C_WHITE}Configure payment gateways under Gateways${C_RESET}" $w
  _box_row "  ${C_BRAND}${C_BOLD}3.${C_RESET}  ${C_WHITE}Add your brands under People → Brands${C_RESET}" $w
  _box_row "  ${C_BRAND}${C_BOLD}4.${C_RESET}  ${C_WHITE}Invite staff members via People → Staff${C_RESET}" $w
  _box_row "  ${C_BRAND}${C_BOLD}5.${C_RESET}  ${C_WHITE}Delete /root/.ownpay-credentials after noting them${C_RESET}" $w
  _box_empty $w
  _box_bot $w

  echo ""
  printf "  ${C_MUTED}Full install log: ${LOG_FILE}${C_RESET}\n"
  printf "  ${C_MUTED}OwnPay documentation: https://docs.ownpay.io${C_RESET}\n"
  echo ""
  printf "  ${C_BRAND}${C_BOLD}Thank you for choosing OwnPay! 💜${C_RESET}\n"
  echo ""
  _divider
  echo ""
}

# ─────────────────────────────────────────────────────────────────────────────
# ERROR TRAP
# ─────────────────────────────────────────────────────────────────────────────

_on_error() {
  local exit_code=$?
  local line="${BASH_LINENO[0]}"
  spinner_stop
  tput cnorm 2>/dev/null || true
  echo ""
  log_error "Installation failed at line ${line} (exit code: ${exit_code})"
  echo ""
  printf "  ${C_MUTED}Troubleshooting:${C_RESET}\n"
  printf "  ${SYM_ARROW}  Full log:  ${C_WHITE}${LOG_FILE}${C_RESET}\n"
  printf "  ${SYM_ARROW}  Resume:    ${C_WHITE}sudo bash install.sh --resume${C_RESET}\n"
  printf "  ${SYM_ARROW}  Support:   ${C_WHITE}https://github.com/ownpay/ownpay/issues${C_RESET}\n"
  echo ""
}

trap '_on_error' ERR
trap 'spinner_stop; tput cnorm 2>/dev/null || true' EXIT

# ─────────────────────────────────────────────────────────────────────────────
# ARGUMENT PARSING
# ─────────────────────────────────────────────────────────────────────────────

parse_args() {
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --unattended) UNATTENDED=1; shift ;;
      --resume)     RESUME=1; shift ;;
      --help|-h)
        printf "\nOwnPay VPS Installer v%s\n\n" "$INSTALLER_VERSION"
        printf "Usage:\n"
        printf "  sudo bash install.sh [--unattended] [--resume] [--help]\n\n"
        printf "Options:\n"
        printf "  --unattended   Skip interactive prompts; read from OWNPAY_* env vars\n"
        printf "  --resume       Resume from last checkpoint after a failed install\n"
        printf "  --help         Show this message\n\n"
        printf "Unattended environment variables:\n"
        printf "  OWNPAY_DOMAIN, OWNPAY_APP_NAME, OWNPAY_TIMEZONE\n"
        printf "  OWNPAY_WEBSERVER (nginx|apache|caddy)\n"
        printf "  OWNPAY_SSL (letsencrypt|selfsigned|skip)\n"
        printf "  OWNPAY_DB_NAME, OWNPAY_DB_USER, OWNPAY_DB_PASS\n"
        printf "  OWNPAY_ADMIN_NAME, OWNPAY_ADMIN_EMAIL\n"
        printf "  OWNPAY_ADMIN_USERNAME, OWNPAY_ADMIN_PASSWORD\n\n"
        exit 0
        ;;
      *) log_warn "Unknown argument: $1"; shift ;;
    esac
  done
}

# ─────────────────────────────────────────────────────────────────────────────
# MAIN
# ─────────────────────────────────────────────────────────────────────────────

main() {
  # Re-attach stdin to TTY for interactive prompts (handles curl-pipe execution)
  exec < /dev/tty 2>/dev/null || true

  parse_args "$@"
  show_banner

  local last_done=0
  [ "$RESUME" = "1" ] && last_done="$(checkpoint_get)" && \
    log_info "Resuming from checkpoint: phase ${last_done} completed"

  should_run_phase 0 && phase_preflight
  should_run_phase 1 && phase_wizard
  should_run_phase 2 && phase_system_packages
  should_run_phase 3 && phase_php
  should_run_phase 4 && phase_mariadb
  should_run_phase 5 && phase_webserver
  should_run_phase 6 && phase_ssl
  should_run_phase 7 && phase_composer
  should_run_phase 8 && phase_deploy
  should_run_phase 9 && phase_redis
  should_run_phase 10 && phase_env
  should_run_phase 11 && phase_web_installer
  should_run_phase 12 && phase_firewall
  should_run_phase 13 && phase_finishing

  show_summary
}

main "$@"
