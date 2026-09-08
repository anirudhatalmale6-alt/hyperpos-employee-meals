#!/bin/bash
# ---------------------------------------------------------------------------
# Spec section 7: once today's subsidised meal has been collected, no second
# subsidy can be issued - even if several tills scan the same card at exactly
# the same instant.
#
# Runs the SHIPPED EntitlementEngine, not a stand-in query. Passes only if
# exactly one till is allowed and exactly one ledger line exists.
#
#   run-double-claim.sh <mysql-socket> [tills] [employee-id]
# ---------------------------------------------------------------------------
set -u

SOCK="${1:?usage: run-double-claim.sh /path/to/mysql.sock [tills] [employee-id]}"
TILLS="${2:-12}"
EMPLOYEE="${3:-1}"
BASKET=63.00

HERE="$(cd "$(dirname "$0")" && pwd)"
MYSQL=(mysql --socket="$SOCK" -u root hyperpos_dev -N -B)

echo "=== clearing today for employee ${EMPLOYEE} ==="
"${MYSQL[@]}" -e "DELETE FROM em_ledger_entries WHERE em_employee_id=${EMPLOYEE};
                  DELETE FROM em_entitlements   WHERE em_employee_id=${EMPLOYEE};"

"${MYSQL[@]}" -e "SELECT CONCAT('employee: ', employee_no, ' ', first_name, ' ', last_name,
                                '  card ', COALESCE(card_number,'-'))
                    FROM em_employees WHERE id=${EMPLOYEE};"
"${MYSQL[@]}" -e "SELECT CONCAT('plan: ', p.name, '  mode ', p.mode, '  R ', p.daily_value, ' a day')
                    FROM em_employees e JOIN em_subsidy_plans p ON p.id=e.em_subsidy_plan_id
                   WHERE e.id=${EMPLOYEE};"
echo
echo "=== ${TILLS} tills scanning that card at the same instant, basket R ${BASKET} ==="

START=$(php -r 'echo (int) ((microtime(true) + 2.0) * 1e6);')
PIDS=()
for i in $(seq 1 "$TILLS"); do
    php "$HERE/claim_once.php" "$i" "$EMPLOYEE" "$BASKET" "$START" &
    PIDS+=($!)
done
for p in "${PIDS[@]}"; do wait "$p"; done

echo
echo "=== result ==="
ROWS=$("${MYSQL[@]}"   -e "SELECT COUNT(*) FROM em_entitlements WHERE em_employee_id=${EMPLOYEE};")
DONE=$("${MYSQL[@]}"   -e "SELECT COUNT(*) FROM em_entitlements WHERE em_employee_id=${EMPLOYEE} AND status='consumed';")
LEDGER=$("${MYSQL[@]}" -e "SELECT COUNT(*) FROM em_ledger_entries WHERE em_employee_id=${EMPLOYEE};")
SUB=$("${MYSQL[@]}"    -e "SELECT COALESCE(SUM(subsidy_amount),0)  FROM em_ledger_entries WHERE em_employee_id=${EMPLOYEE};")
OWN=$("${MYSQL[@]}"    -e "SELECT COALESCE(SUM(employee_amount),0) FROM em_ledger_entries WHERE em_employee_id=${EMPLOYEE};")

printf 'entitlement rows today : %s   (must be 1)\n' "$ROWS"
printf 'subsidies allowed      : %s   (must be 1)\n' "$DONE"
printf 'ledger lines           : %s   (must be 1)\n' "$LEDGER"
printf 'company paid           : R %s\n' "$SUB"
printf 'employee paid          : R %s\n' "$OWN"
echo

if [ "$ROWS" = "1" ] && [ "$DONE" = "1" ] && [ "$LEDGER" = "1" ]; then
    echo "PASS - one meal, one subsidy, one ledger line."
    exit 0
fi
echo "FAIL - the double claim block did not hold."
exit 1
