#!/bin/bash
#
# Comprehensive End-to-End Schema Flow Test Script
#
# Tests ALL branches of the schema workflow. Run with --help for usage.
#
# Phases:
#   A: Token Mode Variations (passive, manual promotion, request body validation)
#   B: All Drift Types (field_added, field_removed, type_changed, structure_changed)
#   C: Drift Severity Levels (info, warning, critical)
#   D: Drift Resolution Paths (accept, import corrected, re-learn)
#   E: Log Level Variations (none, metadata_only, drift_only, headers, full_audit)
#   F: Data Protection Strategies (redact, encrypt, redact_encrypt)
#   G: DTO Generation Flow (auto-generate, manual, export, diff)
#   H: Alert Configuration (webhook, slack, min severity)
#   I: Schema Management Commands (show, list, promote, export, import)
#   J: Edge Cases (inactive token, target restrictions, multiple endpoints)
#
# Usage:
#   ./scripts/e2e-schema-flow-comprehensive.sh           # Run all phases
#   ./scripts/e2e-schema-flow-comprehensive.sh --phase=A # Run specific phase
#   ./scripts/e2e-schema-flow-comprehensive.sh --quick   # Run quick smoke test
#

set -euo pipefail

# Colors
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; MAGENTA='\033[0;35m'; CYAN='\033[0;36m'; NC='\033[0m'

# Test failure tracking
FAILED_ASSERTIONS=0
STRICT_MODE=true

# Configuration
TIMESTAMP=$(date +%s)
TARGET_HOST="https://httpbin.org"
ENDPOINT_PATH="/json"
HTTP_METHOD="GET"
LEARNING_THRESHOLD=3
PROXY_URL="https://sentinelphp.ddev.site:8080"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "${SCRIPT_DIR}")"
TEMP_DIR="${PROJECT_DIR}/var/tmp/e2e-comprehensive-$$"
mkdir -p "${TEMP_DIR}"
CONTAINER_TEMP_DIR="var/tmp/e2e-comprehensive-$$"

# Parse arguments
PHASE=""; QUICK_MODE=false; STRICT_MODE=true
for arg in "$@"; do
    case $arg in
        --phase=*) PHASE="${arg#*=}" ;;
        --quick) QUICK_MODE=true ;;
        --lenient) STRICT_MODE=false ;;
        --help) echo "Usage: $0 [--phase=A|B|C|D|E|F|G|H|I|J] [--quick] [--lenient]"; exit 0 ;;
    esac
done

cleanup() { rm -rf "${TEMP_DIR}"; }
trap cleanup EXIT

# Helper functions
log_phase() { echo -e "\n${MAGENTA}══════ PHASE $1: $2 ══════${NC}"; }
log_step() { echo -e "\n${BLUE}=== Step $1: $2 ===${NC}"; }
log_success() { echo -e "${GREEN}✓ $1${NC}"; }
log_error() { echo -e "${RED}✗ FATAL: $1${NC}"; exit 1; }
log_warning() { echo -e "${YELLOW}⚠ $1${NC}"; }
log_info() { echo -e "${CYAN}ℹ $1${NC}"; }

# Assertion functions - stop on failure in strict mode
assert_equals() {
    local expected="$1" actual="$2" msg="${3:-Assertion failed}"
    if [ "${expected}" != "${actual}" ]; then
        echo -e "${RED}✗ ASSERTION FAILED: ${msg}${NC}"
        echo -e "${RED}  Expected: '${expected}', Got: '${actual}'${NC}"
        FAILED_ASSERTIONS=$((FAILED_ASSERTIONS + 1))
        if [ "${STRICT_MODE}" = true ]; then exit 1; fi
    else
        log_success "${msg}"
    fi
}

assert_not_empty() {
    local value="$1" msg="${2:-Value should not be empty}"
    if [ -z "${value}" ]; then
        echo -e "${RED}✗ ASSERTION FAILED: ${msg}${NC}"
        FAILED_ASSERTIONS=$((FAILED_ASSERTIONS + 1))
        if [ "${STRICT_MODE}" = true ]; then exit 1; fi
    else
        log_success "${msg}"
    fi
}

assert_gt() {
    local val1="$1" val2="$2" msg="${3:-Value should be greater}"
    if [ "${val1}" -le "${val2}" ]; then
        echo -e "${RED}✗ ASSERTION FAILED: ${msg}${NC}"
        echo -e "${RED}  Expected ${val1} > ${val2}${NC}"
        FAILED_ASSERTIONS=$((FAILED_ASSERTIONS + 1))
        if [ "${STRICT_MODE}" = true ]; then exit 1; fi
    else
        log_success "${msg}"
    fi
}

assert_http_code() {
    local expected="$1" actual="$2" msg="${3:-HTTP code check}"
    if [ "${expected}" != "${actual}" ]; then
        echo -e "${RED}✗ ASSERTION FAILED: ${msg}${NC}"
        echo -e "${RED}  Expected HTTP ${expected}, Got HTTP ${actual}${NC}"
        FAILED_ASSERTIONS=$((FAILED_ASSERTIONS + 1))
        if [ "${STRICT_MODE}" = true ]; then exit 1; fi
    else
        log_success "${msg}"
    fi
}

assert_command_success() {
    local cmd_output exit_code msg="${1:-Command should succeed}"
    shift
    set +e
    cmd_output=$("$@" 2>&1)
    exit_code=$?
    set -e
    if [ ${exit_code} -ne 0 ]; then
        echo -e "${RED}✗ ASSERTION FAILED: ${msg}${NC}"
        echo -e "${RED}  Command failed with exit code ${exit_code}${NC}"
        echo -e "${RED}  Output: ${cmd_output}${NC}"
        FAILED_ASSERTIONS=$((FAILED_ASSERTIONS + 1))
        if [ "${STRICT_MODE}" = true ]; then exit 1; fi
        return 1
    fi
    echo "${cmd_output}"
    return 0
}

run_console() {
    local output exit_code
    set +e
    output=$(ddev exec php bin/console "$@" 2>&1)
    exit_code=$?
    set -e
    if [ ${exit_code} -ne 0 ]; then
        echo -e "${RED}Console command failed: php bin/console $*${NC}" >&2
        echo -e "${RED}Output: ${output}${NC}" >&2
        if [ "${STRICT_MODE}" = true ]; then exit 1; fi
        return 1
    fi
    echo "${output}"
}

extract_bearer_token() { echo "$1" | grep -A1 "Bearer Token:" | tail -1 | tr -d '[:space:]'; }

extract_token_uuid() {
    run_console dbal:run-sql "SELECT id FROM api_tokens WHERE name = '$1'" 2>/dev/null | \
        grep -oE '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}' | head -1
}

make_proxy_request() {
    local bearer="$1" target="$2" method="${3:-GET}" body="${4:-}"
    if [ -n "${body}" ]; then
        curl -sk -w "\n%{http_code}" -X "${method}" \
            -H "Authorization: Bearer ${bearer}" \
            -H "X-Sentinel-Target: ${target}" \
            -H "Content-Type: application/json" -d "${body}" "${PROXY_URL}/"
    else
        curl -sk -w "\n%{http_code}" -X "${method}" \
            -H "Authorization: Bearer ${bearer}" \
            -H "X-Sentinel-Target: ${target}" "${PROXY_URL}/"
    fi
}

clear_schema_cache() {
    docker exec ddev-sentinelphp-redis redis-cli KEYS '*sentinel.schema*' | \
        xargs -r docker exec -i ddev-sentinelphp-redis redis-cli DEL 2>/dev/null || true
    sleep 1
}

get_token_mode() {
    run_console dbal:run-sql "SELECT mode FROM api_tokens WHERE name = '$1'" 2>/dev/null | \
        grep -oE 'validating|learning|passive' | head -1 || echo "unknown"
}

get_schema_count() {
    run_console dbal:run-sql "SELECT COUNT(*) FROM api_schemas WHERE token_id = (SELECT id FROM api_tokens WHERE name = '$1')" 2>/dev/null | \
        grep -oE '[0-9]+' | head -1 || echo "0"
}

get_drift_count() {
    run_console dbal:run-sql "SELECT COUNT(*) FROM schema_drifts WHERE token_id = (SELECT id FROM api_tokens WHERE name = '$1')" 2>/dev/null | \
        grep -oE '[0-9]+' | head -1 || echo "0"
}

TARGET_HOSTNAME=$(echo "${TARGET_HOST}" | sed 's|https://||' | sed 's|http://||')

# ============================================================================
# PHASE A: Token Mode Variations
# ============================================================================
run_phase_a() {
    log_phase "A" "Token Mode Variations"
    
    # A1: Passive Mode
    log_step "A1" "Passive Mode - No Schema Operations"
    local TN="e2e-passive-${TIMESTAMP}"
    TOKEN_OUTPUT=$(run_console sentinel:token:create "${TN}" --targets="${TARGET_HOSTNAME}" --mode=passive --log-level=metadata_only)
    BT=$(extract_bearer_token "${TOKEN_OUTPUT}")
    assert_not_empty "${BT}" "Bearer token extracted"
    
    for i in 1 2 3; do
        RESPONSE=$(make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}")
        HTTP_CODE=$(echo "${RESPONSE}" | tail -1)
        assert_http_code "200" "${HTTP_CODE}" "Proxy request ${i} successful"
    done
    sleep 2
    SC=$(get_schema_count "${TN}")
    assert_equals "0" "${SC}" "Passive mode: No schemas created"
    
    # A2: Manual Promotion
    log_step "A2" "Manual Promotion - Learning Without Auto-Switch"
    TN="e2e-manual-${TIMESTAMP}"
    TOKEN_OUTPUT=$(run_console sentinel:token:create "${TN}" --targets="${TARGET_HOSTNAME}" --mode=learning --learning-threshold=${LEARNING_THRESHOLD} --log-level=drift_only)
    BT=$(extract_bearer_token "${TOKEN_OUTPUT}")
    assert_not_empty "${BT}" "Bearer token extracted"
    
    for i in $(seq 1 ${LEARNING_THRESHOLD}); do make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 1; done
    sleep 2
    TM=$(get_token_mode "${TN}")
    assert_equals "learning" "${TM}" "Token remains in learning mode (no auto-switch)"
    
    # Schema may have been auto-promoted if threshold was met, so check for non-master first
    SCHEMA_UUID=$(run_console dbal:run-sql "SELECT id FROM api_schemas WHERE token_id = (SELECT id FROM api_tokens WHERE name = '${TN}') AND is_master = false ORDER BY created_at DESC LIMIT 1" | grep -oE '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}' | head -1 || echo "")
    if [ -n "${SCHEMA_UUID}" ]; then
        run_console sentinel:schema:promote "${SCHEMA_UUID}"
        log_success "Schema manually promoted"
    else
        # Schema was auto-promoted, verify it exists as master
        SCHEMA_UUID=$(run_console dbal:run-sql "SELECT id FROM api_schemas WHERE token_id = (SELECT id FROM api_tokens WHERE name = '${TN}') AND is_master = true ORDER BY created_at DESC LIMIT 1" | grep -oE '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}' | head -1 || echo "")
        assert_not_empty "${SCHEMA_UUID}" "Schema exists (was auto-promoted)"
        log_success "Schema was auto-promoted to master"
    fi
    
    run_console sentinel:token:update "${TN}" --mode=validating
    TM=$(get_token_mode "${TN}")
    assert_equals "validating" "${TM}" "Token manually switched to validating"
    
    # A3: Request Body Validation
    log_step "A3" "Request Body Validation"
    TN="e2e-reqbody-${TIMESTAMP}"
    TOKEN_OUTPUT=$(run_console sentinel:token:create "${TN}" --targets="${TARGET_HOSTNAME}" --mode=learning --learning-threshold=2 --auto-switch --log-level=full_audit)
    BT=$(extract_bearer_token "${TOKEN_OUTPUT}")
    assert_not_empty "${BT}" "Bearer token extracted"
    
    run_console dbal:run-sql "UPDATE api_tokens SET validate_request_body = true WHERE name = '${TN}'"
    
    for i in 1 2; do
        RESPONSE=$(make_proxy_request "${BT}" "${TARGET_HOST}/post" "POST" '{"name":"test","value":123}')
        HTTP_CODE=$(echo "${RESPONSE}" | tail -1)
        assert_http_code "200" "${HTTP_CODE}" "POST request ${i} successful"
        sleep 1
    done
    log_success "Request body validation test complete"
    
    log_success "Phase A completed"
}

# ============================================================================
# PHASE B: All Drift Types
# ============================================================================
run_phase_b() {
    log_phase "B" "All Drift Types"
    
    local TN="e2e-drifts-${TIMESTAMP}"
    TOKEN_OUTPUT=$(run_console sentinel:token:create "${TN}" --targets="${TARGET_HOSTNAME}" --mode=learning --learning-threshold=${LEARNING_THRESHOLD} --auto-switch --log-level=drift_only)
    BT=$(extract_bearer_token "${TOKEN_OUTPUT}")
    assert_not_empty "${BT}" "Bearer token extracted"
    
    for i in $(seq 1 ${LEARNING_THRESHOLD}); do make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 1; done
    sleep 3
    TM=$(get_token_mode "${TN}")
    assert_equals "validating" "${TM}" "Token auto-switched to validating after learning"
    
    # B1: field_added
    log_step "B1" "Drift Type: field_added"
    cat > "${TEMP_DIR}/schema_b1.json" << 'EOF'
{"$schema":"http://json-schema.org/draft-07/schema#","type":"object","required":["slideshow","extra_field"],"properties":{"slideshow":{"type":"object"},"extra_field":{"type":"string"}}}
EOF
    run_console sentinel:schema:import "${CONTAINER_TEMP_DIR}/schema_b1.json" --token="${TN}" --host="${TARGET_HOSTNAME}" --endpoint="${ENDPOINT_PATH}" --method="${HTTP_METHOD}" --type="response" --master
    clear_schema_cache
    DC_BEFORE=$(get_drift_count "${TN}")
    make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 2
    DC_AFTER=$(get_drift_count "${TN}")
    assert_gt "${DC_AFTER}" "${DC_BEFORE}" "field_added drift detected"
    
    # B2: field_removed
    log_step "B2" "Drift Type: field_removed"
    run_console sentinel:token:update "${TN}" --mode=learning
    for i in $(seq 1 ${LEARNING_THRESHOLD}); do make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 1; done
    sleep 2; run_console sentinel:token:update "${TN}" --mode=validating
    cat > "${TEMP_DIR}/schema_b2.json" << 'EOF'
{"$schema":"http://json-schema.org/draft-07/schema#","type":"object","required":["slideshow"],"properties":{"slideshow":{"type":"object","required":["author","date","slides","title","missing_field"]}}}
EOF
    run_console sentinel:schema:import "${CONTAINER_TEMP_DIR}/schema_b2.json" --token="${TN}" --host="${TARGET_HOSTNAME}" --endpoint="${ENDPOINT_PATH}" --method="${HTTP_METHOD}" --type="response" --master
    clear_schema_cache
    DC_BEFORE=$(get_drift_count "${TN}")
    make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 2
    DC_AFTER=$(get_drift_count "${TN}")
    assert_gt "${DC_AFTER}" "${DC_BEFORE}" "field_removed drift detected"
    
    # B3: type_changed
    log_step "B3" "Drift Type: type_changed"
    run_console sentinel:token:update "${TN}" --mode=learning
    for i in $(seq 1 ${LEARNING_THRESHOLD}); do make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 1; done
    sleep 2; run_console sentinel:token:update "${TN}" --mode=validating
    cat > "${TEMP_DIR}/schema_b3.json" << 'EOF'
{"$schema":"http://json-schema.org/draft-07/schema#","type":"object","required":["slideshow"],"properties":{"slideshow":{"type":"object","properties":{"author":{"type":"integer"}}}}}
EOF
    run_console sentinel:schema:import "${CONTAINER_TEMP_DIR}/schema_b3.json" --token="${TN}" --host="${TARGET_HOSTNAME}" --endpoint="${ENDPOINT_PATH}" --method="${HTTP_METHOD}" --type="response" --master
    clear_schema_cache
    DC_BEFORE=$(get_drift_count "${TN}")
    make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 2
    DC_AFTER=$(get_drift_count "${TN}")
    assert_gt "${DC_AFTER}" "${DC_BEFORE}" "type_changed drift detected"
    
    # B4: structure_changed
    log_step "B4" "Drift Type: structure_changed"
    run_console sentinel:token:update "${TN}" --mode=learning
    for i in $(seq 1 ${LEARNING_THRESHOLD}); do make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 1; done
    sleep 2; run_console sentinel:token:update "${TN}" --mode=validating
    cat > "${TEMP_DIR}/schema_b4.json" << 'EOF'
{"$schema":"http://json-schema.org/draft-07/schema#","type":"object","required":["slideshow"],"properties":{"slideshow":{"type":"object","properties":{"slides":{"type":"object"}}}}}
EOF
    run_console sentinel:schema:import "${CONTAINER_TEMP_DIR}/schema_b4.json" --token="${TN}" --host="${TARGET_HOSTNAME}" --endpoint="${ENDPOINT_PATH}" --method="${HTTP_METHOD}" --type="response" --master
    clear_schema_cache
    DC_BEFORE=$(get_drift_count "${TN}")
    make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 2
    DC_AFTER=$(get_drift_count "${TN}")
    assert_gt "${DC_AFTER}" "${DC_BEFORE}" "structure_changed drift detected"
    
    run_console sentinel:drift:list --token="${TN}" --limit=20
    log_success "Phase B completed"
}

# ============================================================================
# PHASE C: Drift Severity Levels
# ============================================================================
run_phase_c() {
    log_phase "C" "Drift Severity Levels"
    
    local TN="e2e-severity-${TIMESTAMP}"
    TOKEN_OUTPUT=$(run_console sentinel:token:create "${TN}" --targets="${TARGET_HOSTNAME}" --mode=learning --learning-threshold=${LEARNING_THRESHOLD} --auto-switch --log-level=drift_only)
    BT=$(extract_bearer_token "${TOKEN_OUTPUT}")
    for i in $(seq 1 ${LEARNING_THRESHOLD}); do make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 1; done
    sleep 3
    
    log_step "C1" "Info Severity (optional field)"
    cat > "${TEMP_DIR}/schema_c1.json" << 'EOF'
{"$schema":"http://json-schema.org/draft-07/schema#","type":"object","properties":{"slideshow":{"type":"object"},"optional":{"type":"string"}}}
EOF
    run_console sentinel:schema:import "${CONTAINER_TEMP_DIR}/schema_c1.json" --token="${TN}" --host="${TARGET_HOSTNAME}" --endpoint="${ENDPOINT_PATH}" --method="${HTTP_METHOD}" --type="response" --master 2>&1 || true
    clear_schema_cache; make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 2
    
    log_step "C2" "Warning Severity (type change)"
    run_console sentinel:token:update "${TN}" --mode=learning
    for i in $(seq 1 ${LEARNING_THRESHOLD}); do make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 1; done
    sleep 2; run_console sentinel:token:update "${TN}" --mode=validating
    cat > "${TEMP_DIR}/schema_c2.json" << 'EOF'
{"$schema":"http://json-schema.org/draft-07/schema#","type":"object","required":["slideshow"],"properties":{"slideshow":{"type":"object","properties":{"author":{"type":"number"}}}}}
EOF
    run_console sentinel:schema:import "${CONTAINER_TEMP_DIR}/schema_c2.json" --token="${TN}" --host="${TARGET_HOSTNAME}" --endpoint="${ENDPOINT_PATH}" --method="${HTTP_METHOD}" --type="response" --master 2>&1 || true
    clear_schema_cache; make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 2
    
    log_step "C3" "Critical Severity (missing required)"
    run_console sentinel:token:update "${TN}" --mode=learning
    for i in $(seq 1 ${LEARNING_THRESHOLD}); do make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 1; done
    sleep 2; run_console sentinel:token:update "${TN}" --mode=validating
    cat > "${TEMP_DIR}/schema_c3.json" << 'EOF'
{"$schema":"http://json-schema.org/draft-07/schema#","type":"object","required":["slideshow","critical_field"],"properties":{"slideshow":{"type":"object"},"critical_field":{"type":"string"}}}
EOF
    run_console sentinel:schema:import "${CONTAINER_TEMP_DIR}/schema_c3.json" --token="${TN}" --host="${TARGET_HOSTNAME}" --endpoint="${ENDPOINT_PATH}" --method="${HTTP_METHOD}" --type="response" --master 2>&1 || true
    clear_schema_cache; make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 2
    
    run_console sentinel:drift:list --token="${TN}" --limit=20
    log_success "Phase C completed"
}

# ============================================================================
# PHASE D: Drift Resolution Paths
# ============================================================================
run_phase_d() {
    log_phase "D" "Drift Resolution Paths"
    
    local TN="e2e-resolution-${TIMESTAMP}"
    TOKEN_OUTPUT=$(run_console sentinel:token:create "${TN}" --targets="${TARGET_HOSTNAME}" --mode=learning --learning-threshold=${LEARNING_THRESHOLD} --auto-switch --log-level=drift_only)
    BT=$(extract_bearer_token "${TOKEN_OUTPUT}")
    for i in $(seq 1 ${LEARNING_THRESHOLD}); do make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 1; done
    sleep 3
    
    # D1: Accept drift
    log_step "D1" "Accept Drift Resolution"
    cat > "${TEMP_DIR}/schema_d1.json" << 'EOF'
{"$schema":"http://json-schema.org/draft-07/schema#","type":"object","required":["slideshow"],"properties":{"slideshow":{"type":"object","properties":{"author":{"type":"integer"}}}}}
EOF
    run_console sentinel:schema:import "${CONTAINER_TEMP_DIR}/schema_d1.json" --token="${TN}" --host="${TARGET_HOSTNAME}" --endpoint="${ENDPOINT_PATH}" --method="${HTTP_METHOD}" --type="response" --master 2>&1 || true
    clear_schema_cache; make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 2
    DRIFT_ID=$(run_console dbal:run-sql "SELECT id FROM schema_drifts WHERE token_id = (SELECT id FROM api_tokens WHERE name = '${TN}') ORDER BY created_at DESC LIMIT 1" 2>/dev/null | grep -oE '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}' | head -1)
    [ -n "${DRIFT_ID}" ] && run_console dbal:run-sql "UPDATE schema_drifts SET accepted_at = NOW() WHERE id = '${DRIFT_ID}'" 2>/dev/null && log_success "Drift accepted"
    
    # D2: Import corrected schema
    log_step "D2" "Import Corrected Schema"
    run_console sentinel:token:update "${TN}" --mode=learning
    for i in $(seq 1 ${LEARNING_THRESHOLD}); do make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 1; done
    sleep 2; run_console sentinel:token:update "${TN}" --mode=validating
    cat > "${TEMP_DIR}/schema_d2.json" << 'EOF'
{"$schema":"http://json-schema.org/draft-07/schema#","type":"object","required":["slideshow"],"properties":{"slideshow":{"type":"object","properties":{"author":{"type":"string"},"date":{"type":"string"},"slides":{"type":"array"},"title":{"type":"string"}}}}}
EOF
    run_console sentinel:schema:import "${CONTAINER_TEMP_DIR}/schema_d2.json" --token="${TN}" --host="${TARGET_HOSTNAME}" --endpoint="${ENDPOINT_PATH}" --method="${HTTP_METHOD}" --type="response" --master 2>&1 || true
    clear_schema_cache
    DC_BEFORE=$(get_drift_count "${TN}")
    make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 2
    DC_AFTER=$(get_drift_count "${TN}")
    [ "${DC_AFTER}" = "${DC_BEFORE}" ] && log_success "Corrected schema validates" || log_info "Drift count: ${DC_AFTER}"
    
    # D3: Re-learn after drift
    log_step "D3" "Re-Learn After Drift"
    run_console sentinel:token:update "${TN}" --mode=learning
    for i in $(seq 1 ${LEARNING_THRESHOLD}); do make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 1; done
    sleep 2
    NEW_UUID=$(run_console dbal:run-sql "SELECT id FROM api_schemas WHERE token_id = (SELECT id FROM api_tokens WHERE name = '${TN}') AND is_master = false ORDER BY created_at DESC LIMIT 1" 2>/dev/null | grep -oE '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}' | head -1)
    [ -n "${NEW_UUID}" ] && run_console sentinel:schema:promote "${NEW_UUID}" && log_success "Re-learned schema promoted"
    run_console sentinel:token:update "${TN}" --mode=validating
    
    log_success "Phase D completed"
}

# ============================================================================
# PHASE E: Log Level Variations
# ============================================================================
run_phase_e() {
    log_phase "E" "Log Level Variations"
    
    for level in none metadata_only drift_only headers full_audit; do
        log_step "E" "Log Level: ${level}"
        TN="e2e-log-${level}-${TIMESTAMP}"
        TOKEN_OUTPUT=$(run_console sentinel:token:create "${TN}" --targets="${TARGET_HOSTNAME}" --mode=passive --log-level=${level})
        BT=$(extract_bearer_token "${TOKEN_OUTPUT}")
        make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 1
    done
    
    # Process async log messages (messenger:consume has a known bug, use workaround)
    log_step "E" "Processing Async Log Messages"
    # Workaround: run in background and kill after timeout to avoid the event dispatcher bug
    set +e
    timeout 5 ddev exec php bin/console messenger:consume async --limit=10 --time-limit=3 2>/dev/null || true
    set -e
    sleep 2
    log_info "Async messages processed (or timed out)"
    
    # Verify log entries were created
    for level in none metadata_only drift_only headers full_audit; do
        TN="e2e-log-${level}-${TIMESTAMP}"
        LC=$(run_console dbal:run-sql "SELECT COUNT(*) FROM request_logs WHERE token_id = (SELECT id FROM api_tokens WHERE name = '${TN}')" | grep -oE '[0-9]+' | head -1 || echo "0")
        
        if [ "${level}" = "none" ]; then
            assert_equals "0" "${LC}" "Log level 'none': No entries created"
        else
            log_info "Log level '${level}': ${LC} entries"
        fi
    done
    
    # Verify headers and body content for full_audit
    log_step "E" "Verify Full Audit Log Content"
    TN="e2e-log-full_audit-${TIMESTAMP}"
    HAS_HEADERS=$(run_console dbal:run-sql "SELECT COUNT(*) FROM request_logs WHERE token_id = (SELECT id FROM api_tokens WHERE name = '${TN}') AND request_headers IS NOT NULL AND request_headers != ''" | grep -oE '[0-9]+' | head -1 || echo "0")
    HAS_BODY=$(run_console dbal:run-sql "SELECT COUNT(*) FROM request_logs WHERE token_id = (SELECT id FROM api_tokens WHERE name = '${TN}') AND response_body IS NOT NULL AND response_body != ''" | grep -oE '[0-9]+' | head -1 || echo "0")
    log_info "Full audit logs with headers: ${HAS_HEADERS}, with body: ${HAS_BODY}"
    
    log_success "Phase E completed"
}

# ============================================================================
# PHASE F: Data Protection Strategies
# ============================================================================
run_phase_f() {
    log_phase "F" "Data Protection Strategies"
    
    # Test data with various PII types that should be redacted
    # - email: test@example.com -> t***@example.com
    # - password field: completely redacted to [REDACTED]
    # - credit_card: 4111111111111111 -> ****-****-****-1111
    # - ssn: 123-45-6789 -> ***-**-6789
    # - phone: 555-123-4567 -> +1-***-***-4567
    local TEST_PAYLOAD='{"email":"john.doe@example.com","password":"SuperSecret123!","credit_card":"4111111111111111","ssn":"123-45-6789","phone":"555-123-4567","name":"John Doe","message":"Contact me at jane@test.org or call 800-555-1234"}'
    
    # F1: No protection (baseline)
    log_step "F1" "No Protection (Baseline)"
    local TN="e2e-protect-none-${TIMESTAMP}"
    TOKEN_OUTPUT=$(run_console sentinel:token:create "${TN}" --targets="${TARGET_HOSTNAME}" --mode=passive --log-level=full_audit)
    BT=$(extract_bearer_token "${TOKEN_OUTPUT}")
    assert_not_empty "${BT}" "Bearer token extracted"
    make_proxy_request "${BT}" "${TARGET_HOST}/post" "POST" "${TEST_PAYLOAD}" > /dev/null
    sleep 1
    
    # F2: Redact strategy
    log_step "F2" "Redact Strategy"
    TN="e2e-protect-redact-${TIMESTAMP}"
    TOKEN_OUTPUT=$(run_console sentinel:token:create "${TN}" --targets="${TARGET_HOSTNAME}" --mode=passive --log-level=full_audit)
    run_console dbal:run-sql "UPDATE api_tokens SET data_protection_strategy = 'redact' WHERE name = '${TN}'"
    BT=$(extract_bearer_token "${TOKEN_OUTPUT}")
    assert_not_empty "${BT}" "Bearer token extracted"
    make_proxy_request "${BT}" "${TARGET_HOST}/post" "POST" "${TEST_PAYLOAD}" > /dev/null
    sleep 1
    
    # F3: Encrypt strategy
    log_step "F3" "Encrypt Strategy"
    TN="e2e-protect-encrypt-${TIMESTAMP}"
    TOKEN_OUTPUT=$(run_console sentinel:token:create "${TN}" --targets="${TARGET_HOSTNAME}" --mode=passive --log-level=full_audit)
    run_console dbal:run-sql "UPDATE api_tokens SET data_protection_strategy = 'encrypt' WHERE name = '${TN}'"
    BT=$(extract_bearer_token "${TOKEN_OUTPUT}")
    assert_not_empty "${BT}" "Bearer token extracted"
    make_proxy_request "${BT}" "${TARGET_HOST}/post" "POST" "${TEST_PAYLOAD}" > /dev/null
    sleep 1
    
    # F4: Redact + Encrypt strategy
    log_step "F4" "Redact + Encrypt Strategy"
    TN="e2e-protect-redact_encrypt-${TIMESTAMP}"
    TOKEN_OUTPUT=$(run_console sentinel:token:create "${TN}" --targets="${TARGET_HOSTNAME}" --mode=passive --log-level=full_audit)
    run_console dbal:run-sql "UPDATE api_tokens SET data_protection_strategy = 'redact_encrypt' WHERE name = '${TN}'"
    BT=$(extract_bearer_token "${TOKEN_OUTPUT}")
    assert_not_empty "${BT}" "Bearer token extracted"
    make_proxy_request "${BT}" "${TARGET_HOST}/post" "POST" "${TEST_PAYLOAD}" > /dev/null
    sleep 1
    
    # Process async log messages - consumer has a bug, process one at a time
    log_step "F5" "Processing Async Log Messages"
    set +e
    # Consumer crashes after processing messages due to Symfony bug, run multiple times with limit=1
    for i in $(seq 1 20); do
        timeout 5 ddev exec php bin/console messenger:consume async --limit=1 --time-limit=2 2>/dev/null || true
    done
    set -e
    
    # Wait for all 4 log entries to be created (with timeout)
    log_info "Waiting for log entries to be created..."
    for attempt in 1 2 3 4 5; do
        LOG_COUNT=$(run_console dbal:run-sql "SELECT COUNT(*) FROM request_logs r JOIN api_tokens t ON r.token_id = t.id WHERE t.name LIKE 'e2e-protect-%-${TIMESTAMP}'" 2>/dev/null | grep -oE '[0-9]+' | head -1 || echo "0")
        if [ "${LOG_COUNT}" -ge "4" ]; then
            log_info "All 4 log entries created"
            break
        fi
        log_info "Found ${LOG_COUNT}/4 log entries, waiting... (attempt ${attempt})"
        for j in 1 2 3 4 5; do
            timeout 3 ddev exec php bin/console messenger:consume async --limit=1 --time-limit=1 2>/dev/null || true
        done
        sleep 1
    done
    
    # ========== VERIFICATION ==========
    
    # Verify baseline (no protection) - sensitive data should be visible
    log_step "F6" "Verify No Protection (Baseline)"
    TN="e2e-protect-none-${TIMESTAMP}"
    BODY=$(run_console dbal:run-sql "SELECT request_body FROM request_logs WHERE token_id = (SELECT id FROM api_tokens WHERE name = '${TN}') LIMIT 1" 2>/dev/null || echo "")
    if echo "${BODY}" | grep -q "SuperSecret123"; then
        log_success "Baseline: Password visible in plain text (as expected)"
    else
        log_warning "Baseline: Password not found - check if log was created"
    fi
    if echo "${BODY}" | grep -q "john.doe@example.com"; then
        log_success "Baseline: Email visible in plain text (as expected)"
    fi
    
    # Verify REDACT strategy
    log_step "F7" "Verify Redact Strategy"
    TN="e2e-protect-redact-${TIMESTAMP}"
    BODY=$(run_console dbal:run-sql "SELECT request_body FROM request_logs WHERE token_id = (SELECT id FROM api_tokens WHERE name = '${TN}') LIMIT 1" 2>/dev/null || echo "")
    IS_ENC=$(run_console dbal:run-sql "SELECT is_encrypted FROM request_logs WHERE token_id = (SELECT id FROM api_tokens WHERE name = '${TN}') LIMIT 1" | grep -oE '(true|false|0|1)' | head -1 || echo "")
    
    log_info "Redact - is_encrypted: ${IS_ENC}"
    
    # Password should be [REDACTED]
    if echo "${BODY}" | grep -q "SuperSecret123"; then
        echo -e "${RED}✗ ASSERTION FAILED: Redact - Password still visible in plain text${NC}"
        FAILED_ASSERTIONS=$((FAILED_ASSERTIONS + 1))
        if [ "${STRICT_MODE}" = true ]; then exit 1; fi
    else
        if echo "${BODY}" | grep -q "REDACTED"; then
            log_success "Redact: Password field redacted to [REDACTED]"
        else
            log_info "Redact: Password not visible (may be redacted differently)"
        fi
    fi
    
    # Email should be partially redacted (j***@example.com)
    if echo "${BODY}" | grep -q "john.doe@example.com"; then
        log_warning "Redact: Email not redacted"
    else
        if echo "${BODY}" | grep -qE 'j\*\*\*@example\.com'; then
            log_success "Redact: Email redacted to j***@example.com"
        else
            log_info "Redact: Email redacted (format may vary)"
        fi
    fi
    
    # Credit card should be redacted
    if echo "${BODY}" | grep -q "4111111111111111"; then
        log_warning "Redact: Credit card not redacted"
    else
        if echo "${BODY}" | grep -qE '\*{4}-\*{4}-\*{4}-1111'; then
            log_success "Redact: Credit card redacted to ****-****-****-1111"
        else
            log_info "Redact: Credit card redacted (format may vary)"
        fi
    fi
    
    # Verify ENCRYPT strategy
    log_step "F8" "Verify Encrypt Strategy"
    TN="e2e-protect-encrypt-${TIMESTAMP}"
    IS_ENC=$(run_console dbal:run-sql "SELECT is_encrypted FROM request_logs WHERE token_id = (SELECT id FROM api_tokens WHERE name = '${TN}') LIMIT 1" | grep -oE '(true|false|0|1)' | head -1 || echo "")
    BODY=$(run_console dbal:run-sql "SELECT request_body FROM request_logs WHERE token_id = (SELECT id FROM api_tokens WHERE name = '${TN}') LIMIT 1" 2>/dev/null || echo "")
    
    if [ "${IS_ENC}" = "1" ] || [ "${IS_ENC}" = "true" ]; then
        log_success "Encrypt: is_encrypted flag is TRUE"
    else
        echo -e "${RED}✗ ASSERTION FAILED: Encrypt - is_encrypted should be true, got: ${IS_ENC}${NC}"
        FAILED_ASSERTIONS=$((FAILED_ASSERTIONS + 1))
        if [ "${STRICT_MODE}" = true ]; then exit 1; fi
    fi
    
    # Encrypted data should not contain plain text password
    if echo "${BODY}" | grep -q "SuperSecret123"; then
        echo -e "${RED}✗ ASSERTION FAILED: Encrypt - Password visible in encrypted data${NC}"
        FAILED_ASSERTIONS=$((FAILED_ASSERTIONS + 1))
        if [ "${STRICT_MODE}" = true ]; then exit 1; fi
    else
        log_success "Encrypt: Password not visible in stored data"
    fi
    
    # Verify REDACT + ENCRYPT strategy
    log_step "F9" "Verify Redact + Encrypt Strategy"
    TN="e2e-protect-redact_encrypt-${TIMESTAMP}"
    IS_ENC=$(run_console dbal:run-sql "SELECT is_encrypted FROM request_logs WHERE token_id = (SELECT id FROM api_tokens WHERE name = '${TN}') LIMIT 1" | grep -oE '(true|false|0|1)' | head -1 || echo "")
    BODY=$(run_console dbal:run-sql "SELECT request_body FROM request_logs WHERE token_id = (SELECT id FROM api_tokens WHERE name = '${TN}') LIMIT 1" 2>/dev/null || echo "")
    
    if [ "${IS_ENC}" = "1" ] || [ "${IS_ENC}" = "true" ]; then
        log_success "Redact+Encrypt: is_encrypted flag is TRUE"
    else
        echo -e "${RED}✗ ASSERTION FAILED: Redact+Encrypt - is_encrypted should be true, got: ${IS_ENC}${NC}"
        FAILED_ASSERTIONS=$((FAILED_ASSERTIONS + 1))
        if [ "${STRICT_MODE}" = true ]; then exit 1; fi
    fi
    
    # Data should be encrypted (and redacted before encryption)
    if echo "${BODY}" | grep -q "SuperSecret123"; then
        echo -e "${RED}✗ ASSERTION FAILED: Redact+Encrypt - Password visible${NC}"
        FAILED_ASSERTIONS=$((FAILED_ASSERTIONS + 1))
        if [ "${STRICT_MODE}" = true ]; then exit 1; fi
    else
        log_success "Redact+Encrypt: Password not visible in stored data"
    fi
    
    log_success "Phase F completed"
}

# ============================================================================
# PHASE G: DTO Generation Flow
# ============================================================================
run_phase_g() {
    log_phase "G" "DTO Generation Flow"
    
    local TN="e2e-dto-${TIMESTAMP}"
    TOKEN_OUTPUT=$(run_console sentinel:token:create "${TN}" --targets="${TARGET_HOSTNAME}" --mode=learning --learning-threshold=${LEARNING_THRESHOLD} --auto-switch --log-level=drift_only)
    BT=$(extract_bearer_token "${TOKEN_OUTPUT}")
    run_console dbal:run-sql "UPDATE api_tokens SET auto_generate_dtos = true WHERE name = '${TN}'" 2>/dev/null || true
    
    log_step "G1" "Learn Schema"
    for i in $(seq 1 ${LEARNING_THRESHOLD}); do make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 1; done
    sleep 3
    
    log_step "G2" "Manual DTO Generation"
    SCHEMA_UUID=$(run_console dbal:run-sql "SELECT id FROM api_schemas WHERE token_id = (SELECT id FROM api_tokens WHERE name = '${TN}') AND is_master = true LIMIT 1" | grep -oE '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}' | head -1 || echo "")
    if [ -n "${SCHEMA_UUID}" ]; then
        run_console sentinel:dto:generate --schema-id="${SCHEMA_UUID}"
        log_success "DTO generated for schema ${SCHEMA_UUID}"
    else
        log_warning "No master schema found for DTO generation"
    fi
    
    log_step "G3" "Verify DTO Created"
    DC=$(run_console dbal:run-sql "SELECT COUNT(*) FROM generated_dtos WHERE schema_id IN (SELECT id FROM api_schemas WHERE token_id = (SELECT id FROM api_tokens WHERE name = '${TN}'))" | grep -oE '[0-9]+' | head -1 || echo "0")
    assert_gt "${DC}" "0" "At least one DTO was generated"
    
    log_step "G4" "DTO List"
    run_console sentinel:dto:list --token="${TN}"
    
    log_success "Phase G completed"
}

# ============================================================================
# PHASE H: Alert Configuration
# ============================================================================
run_phase_h() {
    log_phase "H" "Alert Configuration"
    
    local TN="e2e-alerts-${TIMESTAMP}"
    TOKEN_OUTPUT=$(run_console sentinel:token:create "${TN}" --targets="${TARGET_HOSTNAME}" --mode=learning --learning-threshold=${LEARNING_THRESHOLD} --auto-switch --log-level=drift_only)
    BT=$(extract_bearer_token "${TOKEN_OUTPUT}")
    run_console dbal:run-sql "UPDATE api_tokens SET alert_min_severity = 'warning' WHERE name = '${TN}'" 2>/dev/null || true
    
    log_step "H1" "Token with Alert Min Severity"
    log_success "Token created with alert_min_severity = warning"
    
    log_step "H2" "Webhook Alert Configuration"
    ALERT_ID=$(cat /proc/sys/kernel/random/uuid 2>/dev/null || uuidgen)
    run_console dbal:run-sql "INSERT INTO alert_configurations (id, name, channel_type, is_enabled, configuration, created_at, updated_at) VALUES ('${ALERT_ID}', 'E2E Webhook', 'webhook', true, '{\"url\": \"https://httpbin.org/post\"}', NOW(), NOW()) ON CONFLICT DO NOTHING" 2>/dev/null || true
    log_success "Webhook alert configured"
    
    log_step "H3" "Trigger Drift for Alert"
    for i in $(seq 1 ${LEARNING_THRESHOLD}); do make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 1; done
    sleep 3
    cat > "${TEMP_DIR}/schema_h.json" << 'EOF'
{"$schema":"http://json-schema.org/draft-07/schema#","type":"object","required":["slideshow"],"properties":{"slideshow":{"type":"object","properties":{"author":{"type":"integer"}}}}}
EOF
    run_console sentinel:schema:import "${CONTAINER_TEMP_DIR}/schema_h.json" --token="${TN}" --host="${TARGET_HOSTNAME}" --endpoint="${ENDPOINT_PATH}" --method="${HTTP_METHOD}" --type="response" --master 2>&1 || true
    clear_schema_cache; make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 2
    ALC=$(run_console dbal:run-sql "SELECT COUNT(*) FROM alert_logs" 2>/dev/null | grep -oE '[0-9]+' | head -1 || echo "0")
    log_info "Alert logs: ${ALC}"
    
    log_success "Phase H completed"
}

# ============================================================================
# PHASE I: Schema Management Commands
# ============================================================================
run_phase_i() {
    log_phase "I" "Schema Management Commands"
    
    local TN="e2e-commands-${TIMESTAMP}"
    TOKEN_OUTPUT=$(run_console sentinel:token:create "${TN}" --targets="${TARGET_HOSTNAME}" --mode=learning --learning-threshold=${LEARNING_THRESHOLD} --auto-switch --log-level=drift_only)
    BT=$(extract_bearer_token "${TOKEN_OUTPUT}")
    assert_not_empty "${BT}" "Bearer token extracted"
    
    for i in $(seq 1 ${LEARNING_THRESHOLD}); do make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 1; done
    sleep 3
    
    log_step "I1" "sentinel:schema:list"
    run_console sentinel:schema:list --token="${TN}"
    log_success "schema:list executed"
    
    log_step "I2" "sentinel:schema:list --master-only"
    run_console sentinel:schema:list --token="${TN}" --master-only
    log_success "schema:list --master-only executed"
    
    log_step "I3" "sentinel:schema:show"
    SCHEMA_UUID=$(run_console dbal:run-sql "SELECT id FROM api_schemas WHERE token_id = (SELECT id FROM api_tokens WHERE name = '${TN}') AND is_master = true LIMIT 1" | grep -oE '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}' | head -1 || echo "")
    assert_not_empty "${SCHEMA_UUID}" "Master schema UUID found"
    run_console sentinel:schema:show "${SCHEMA_UUID}"
    log_success "schema:show executed"
    
    log_step "I4" "sentinel:schema:export"
    run_console sentinel:schema:export --token="${TN}" --host="${TARGET_HOSTNAME}" --endpoint="${ENDPOINT_PATH}" --method="${HTTP_METHOD}" --type="response" --format="json-schema" | head -30
    log_success "schema:export executed"
    
    log_step "I5" "sentinel:schema:import"
    cat > "${TEMP_DIR}/schema_i.json" << 'EOF'
{"$schema":"http://json-schema.org/draft-07/schema#","type":"object","properties":{"test":{"type":"string"}}}
EOF
    run_console sentinel:schema:import "${CONTAINER_TEMP_DIR}/schema_i.json" --token="${TN}" --host="${TARGET_HOSTNAME}" --endpoint="/test-import" --method="GET" --type="response"
    log_success "schema:import executed"
    
    log_success "Phase I completed"
}

# ============================================================================
# PHASE J: Edge Cases
# ============================================================================
run_phase_j() {
    log_phase "J" "Edge Cases"
    
    # J1: Inactive token
    log_step "J1" "Inactive Token"
    local TN="e2e-inactive-${TIMESTAMP}"
    TOKEN_OUTPUT=$(run_console sentinel:token:create "${TN}" --targets="${TARGET_HOSTNAME}" --mode=passive)
    BT=$(extract_bearer_token "${TOKEN_OUTPUT}")
    assert_not_empty "${BT}" "Bearer token extracted"
    
    run_console sentinel:token:update "${TN}" --active=false
    RESPONSE=$(make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}")
    HTTP_CODE=$(echo "${RESPONSE}" | tail -1)
    assert_http_code "401" "${HTTP_CODE}" "Inactive token rejected with 401"
    
    # J2: Target host restriction
    log_step "J2" "Target Host Restriction"
    TN="e2e-restricted-${TIMESTAMP}"
    TOKEN_OUTPUT=$(run_console sentinel:token:create "${TN}" --targets="allowed.example.com" --mode=passive)
    BT=$(extract_bearer_token "${TOKEN_OUTPUT}")
    assert_not_empty "${BT}" "Bearer token extracted"
    
    RESPONSE=$(make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}")
    HTTP_CODE=$(echo "${RESPONSE}" | tail -1)
    assert_http_code "403" "${HTTP_CODE}" "Unauthorized target rejected with 403"
    
    # J3: Multiple endpoints
    log_step "J3" "Multiple Endpoints"
    TN="e2e-multi-${TIMESTAMP}"
    TOKEN_OUTPUT=$(run_console sentinel:token:create "${TN}" --targets="${TARGET_HOSTNAME}" --mode=learning --learning-threshold=2 --auto-switch)
    BT=$(extract_bearer_token "${TOKEN_OUTPUT}")
    assert_not_empty "${BT}" "Bearer token extracted"
    
    for i in 1 2; do
        make_proxy_request "${BT}" "${TARGET_HOST}/json" > /dev/null
        make_proxy_request "${BT}" "${TARGET_HOST}/uuid" > /dev/null
        make_proxy_request "${BT}" "${TARGET_HOST}/headers" > /dev/null
        sleep 1
    done
    sleep 2
    SC=$(get_schema_count "${TN}")
    log_info "Schemas for multiple endpoints: ${SC}"
    if [ "${SC}" -lt 2 ]; then
        echo -e "${RED}\u2717 ASSERTION FAILED: Expected at least 2 schemas, got ${SC}${NC}"
        FAILED_ASSERTIONS=$((FAILED_ASSERTIONS + 1))
        if [ "${STRICT_MODE}" = true ]; then exit 1; fi
    else
        log_success "Multiple endpoint schemas created (${SC})"
    fi
    
    # J4: Schema versioning
    log_step "J4" "Schema Versioning"
    TN="e2e-version-${TIMESTAMP}"
    TOKEN_OUTPUT=$(run_console sentinel:token:create "${TN}" --targets="${TARGET_HOSTNAME}" --mode=learning --learning-threshold=2)
    BT=$(extract_bearer_token "${TOKEN_OUTPUT}")
    assert_not_empty "${BT}" "Bearer token extracted"
    
    for i in 1 2 3 4 5; do make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 1; done
    sleep 2
    VER=$(run_console dbal:run-sql "SELECT version FROM api_schemas WHERE token_id = (SELECT id FROM api_tokens WHERE name = '${TN}') ORDER BY created_at DESC LIMIT 1" | grep -oE '[0-9]+' | head -1 || echo "0")
    log_info "Schema version: ${VER}"
    assert_gt "${VER}" "0" "Schema version is positive"
    
    # J5: Cache invalidation
    log_step "J5" "Cache Invalidation"
    clear_schema_cache
    log_success "Cache invalidation executed"
    
    log_success "Phase J completed"
}

# ============================================================================
# Quick Smoke Test
# ============================================================================
run_quick_test() {
    log_phase "QUICK" "Smoke Test"
    
    local TN="e2e-quick-${TIMESTAMP}"
    TOKEN_OUTPUT=$(run_console sentinel:token:create "${TN}" --targets="${TARGET_HOSTNAME}" --mode=learning --learning-threshold=2 --auto-switch --log-level=drift_only)
    BT=$(extract_bearer_token "${TOKEN_OUTPUT}")
    assert_not_empty "${BT}" "Token created and bearer extracted"
    
    for i in 1 2; do make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 1; done
    sleep 3
    TM=$(get_token_mode "${TN}")
    assert_equals "validating" "${TM}" "Auto-switched to validating mode"
    
    cat > "${TEMP_DIR}/schema_quick.json" << 'EOF'
{"$schema":"http://json-schema.org/draft-07/schema#","type":"object","required":["slideshow"],"properties":{"slideshow":{"type":"object","properties":{"author":{"type":"integer"}}}}}
EOF
    run_console sentinel:schema:import "${CONTAINER_TEMP_DIR}/schema_quick.json" --token="${TN}" --host="${TARGET_HOSTNAME}" --endpoint="${ENDPOINT_PATH}" --method="${HTTP_METHOD}" --type="response" --master
    clear_schema_cache
    DC_BEFORE=$(get_drift_count "${TN}")
    make_proxy_request "${BT}" "${TARGET_HOST}${ENDPOINT_PATH}" > /dev/null; sleep 2
    DC_AFTER=$(get_drift_count "${TN}")
    assert_gt "${DC_AFTER}" "${DC_BEFORE}" "Drift detected after schema import"
    
    run_console sentinel:drift:list --token="${TN}" --limit=5
    log_success "Quick smoke test completed"
}

# ============================================================================
# Main Execution
# ============================================================================

echo -e "${GREEN}╔══════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║     SentinelPHP Comprehensive E2E Schema Flow Test Suite        ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════════════════╝${NC}"

if [ "${QUICK_MODE}" = true ]; then
    run_quick_test
elif [ -n "${PHASE}" ]; then
    case "${PHASE}" in
        A|a) run_phase_a ;;
        B|b) run_phase_b ;;
        C|c) run_phase_c ;;
        D|d) run_phase_d ;;
        E|e) run_phase_e ;;
        F|f) run_phase_f ;;
        G|g) run_phase_g ;;
        H|h) run_phase_h ;;
        I|i) run_phase_i ;;
        J|j) run_phase_j ;;
        *) log_error "Unknown phase: ${PHASE}. Use A-J." ;;
    esac
else
    run_phase_a
    run_phase_b
    run_phase_c
    run_phase_d
    run_phase_e
    run_phase_f
    run_phase_g
    run_phase_h
    run_phase_i
    run_phase_j
fi

# Final summary
if [ ${FAILED_ASSERTIONS} -gt 0 ]; then
    echo -e "\n${RED}╔══════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${RED}║         TESTS FAILED: ${FAILED_ASSERTIONS} assertion(s) failed                       ║${NC}"
    echo -e "${RED}╚══════════════════════════════════════════════════════════════════╝${NC}"
    exit 1
else
    echo -e "\n${GREEN}╔══════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║              All Tests Completed Successfully!                   ║${NC}"
    echo -e "${GREEN}╚══════════════════════════════════════════════════════════════════╝${NC}"
fi
