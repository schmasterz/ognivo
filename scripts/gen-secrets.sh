#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

get_var_value() {
    local file="$1"
    local var="$2"
    if grep -q -E "^${var}=" "${file}" 2>/dev/null; then
        local line
        line="$(grep -E "^${var}=" "${file}" | head -n 1)"
        local val="${line#*=}"
        # Strip optional surrounding quotes and whitespace
        val="$(echo "${val}" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
        val="${val%\"}"
        val="${val#\"}"
        val="${val%\'}"
        val="${val#\'}"
        echo "${val}"
    else
        echo "__UNDEFINED__"
    fi
}

set_or_append_var() {
    local file="$1"
    local var="$2"
    local new_val="$3"
    local is_secret="${4:-0}" # 1: replace if missing, empty, or CHANGE_ME; 0: replace if missing or empty

    local current_val
    current_val="$(get_var_value "${file}" "${var}")"

    if [ "${current_val}" != "__UNDEFINED__" ]; then
        local needs_update=0
        if [ -z "${current_val}" ]; then
            needs_update=1
        elif [ "${is_secret}" -eq 1 ] && [ "${current_val}" = "CHANGE_ME" ]; then
            needs_update=1
        fi

        if [ "${needs_update}" -eq 1 ]; then
            local tmp_file="${file}.tmp.$$"
            while IFS= read -r line || [ -n "$line" ]; do
                case "$line" in
                    "${var}="*)
                        echo "${var}=${new_val}" ;;
                    *)
                        echo "$line" ;;
                esac
            done < "${file}" > "${tmp_file}"
            mv "${tmp_file}" "${file}"
            echo "[secrets] Generated ${var} in ${file}."
        else
            echo "[secrets] Preserved existing ${var} in ${file}."
        fi
    else
        echo "${var}=${new_val}" >> "${file}"
        echo "[secrets] Added ${var} to ${file}."
    fi
}

generate_env() {
    local target="$1"
    local template="$2"
    local default_strict_grants="$3"

    if [ ! -f "${template}" ]; then
        echo "[secrets] ERROR: Template '${template}' not found." >&2
        return 1
    fi

    if [ ! -f "${target}" ]; then
        echo "[secrets] Creating '${target}' from template '${template}'..."
        cp "${template}" "${target}"
    else
        echo "[secrets] Checking '${target}' for missing or empty secrets..."
    fi

    # 1. Base Application Secrets
    local app_key="base64:$(openssl rand -base64 32)"
    local db_password="$(openssl rand -hex 16)"
    local db_root_password="$(openssl rand -hex 16)"
    local reverb_app_secret="$(openssl rand -hex 16)"

    set_or_append_var "${target}" "APP_KEY" "${app_key}" 1
    set_or_append_var "${target}" "DB_PASSWORD" "${db_password}" 1
    set_or_append_var "${target}" "DB_ROOT_PASSWORD" "${db_root_password}" 1

    local reverb_app_key
    reverb_app_key="$(get_var_value "${target}" "REVERB_APP_KEY")"
    if [ "${reverb_app_key}" = "__UNDEFINED__" ] || [ -z "${reverb_app_key}" ] || [ "${reverb_app_key}" = "CHANGE_ME" ]; then
        reverb_app_key="$(openssl rand -hex 10)"
    fi
    set_or_append_var "${target}" "REVERB_APP_KEY" "${reverb_app_key}" 1
    set_or_append_var "${target}" "REVERB_APP_SECRET" "${reverb_app_secret}" 1
    set_or_append_var "${target}" "NEXT_PUBLIC_REVERB_APP_KEY" "${reverb_app_key}" 1

    # 2. Audit Logging & Strict Grants Configuration
    if ! grep -q -E "^AUDIT_" "${target}"; then
        echo "" >> "${target}"
        echo "# Audit Logging & Tamper Resistance" >> "${target}"
    fi

    set_or_append_var "${target}" "AUDIT_DB_USERNAME" "audit_writer" 0
    set_or_append_var "${target}" "AUDIT_DB_PASSWORD" "$(openssl rand -hex 16)" 1

    set_or_append_var "${target}" "AUDIT_MAINT_DB_USERNAME" "audit_maintenance" 0
    set_or_append_var "${target}" "AUDIT_MAINT_DB_PASSWORD" "$(openssl rand -hex 16)" 1

    set_or_append_var "${target}" "DB_MIGRATION_USERNAME" "migration_user" 0
    set_or_append_var "${target}" "DB_MIGRATION_PASSWORD" "$(openssl rand -hex 16)" 1

    set_or_append_var "${target}" "AUDIT_STRICT_GRANTS" "${default_strict_grants}" 0

    set_or_append_var "${target}" "AUDIT_HMAC_KEY_ID" "v1" 0
    # AUDIT_HMAC_KEY must be at least 32 random bytes (64 hex characters)
    set_or_append_var "${target}" "AUDIT_HMAC_KEY" "$(openssl rand -hex 32)" 1

    # AUDIT_PSEUDONYM_SALT for pseudonymising deleted users
    set_or_append_var "${target}" "AUDIT_PSEUDONYM_SALT" "$(openssl rand -hex 32)" 1

    # Audit tunables
    set_or_append_var "${target}" "AUDIT_RETENTION_DAYS" "400" 0
    set_or_append_var "${target}" "AUDIT_SEAL_DELAY_SECONDS" "120" 0
    set_or_append_var "${target}" "AUDIT_ATTEMPTED_EMAIL_DAYS" "30" 0
    set_or_append_var "${target}" "AUDIT_LOG_STDERR" "false" 0

    chmod 600 "${target}" 2>/dev/null || true
    echo "[secrets] Successfully processed '${target}'."
}

cd "${ROOT_DIR}"
generate_env ".env.production" ".env.production.example" "true"
generate_env ".env.development" ".env.development.example" "false"

# Create local convenience symlink backend/.env -> ../.env.development for host CLI and IDE tooling
if [ ! -L "backend/.env" ]; then
    ln -sf ../.env.development backend/.env
    echo "[secrets] Linked backend/.env -> ../.env.development for host tooling."
fi

echo "[secrets] Done."
