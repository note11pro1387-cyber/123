#!/usr/bin/env python3
"""Static Wave 2 checks; does not claim to validate SQL against a running engine."""
from pathlib import Path
import re
import sys

root = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[1]
failures = []
checks = 0

def check(condition: bool, label: str) -> None:
    global checks
    checks += 1
    if not condition:
        failures.append(label)

migration_dir = root / "database" / "migrations"
files = sorted(migration_dir.glob("*.sql"))
check(len(files) == 18, "expected 18 migrations including pending-delivery Challenge state 018")
tables = []
for expected_index, path in enumerate(files, 1):
    match = re.match(r"^(\d{3,})_([a-z0-9_]+)\.sql$", path.name)
    check(match is not None, f"valid migration filename: {path.name}")
    if match:
        check(int(match.group(1)) == expected_index, f"contiguous migration version: {path.name}")
    sql = path.read_text(encoding="utf-8")
    if path.name == "018_add_pending_delivery_challenge_state.sql":
        check(re.match(r"\s*ALTER\s+TABLE\s+verification_challenges\s+MODIFY\s+COLUMN\s+state\s+ENUM", sql, re.I) is not None, "migration 018 is a targeted additive enum alteration")
        check("'pending_delivery'" in sql and "'active'" in sql and "'verified_reset'" in sql, "migration 018 preserves existing challenge states")
    else:
        root_match = re.match(r"\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z_]+)`?", sql, re.I)
        check(root_match is not None, f"single CREATE TABLE statement: {path.name}")
        if root_match:
            tables.append(root_match.group(1).lower())
        check("ENGINE=InnoDB" in sql.replace(" ", ""), f"InnoDB engine explicitly declared: {path.name}")
    check(sql.count(";") == 1 and sql.rstrip().endswith(";"), f"no unexpected extra SQL statements: {path.name}")

catalog_path = root / "app" / "support" / "SchemaCatalog.php"
catalog_text = catalog_path.read_text(encoding="utf-8")
catalog = re.findall(r"'([a-z_]+)'", catalog_text.split("public static function tables")[1].split("public static function dataTablesInRestoreOrder")[0])
check(tables == catalog, "migration sequence equals SchemaCatalog table order")
check("PDO::ATTR_EMULATE_PREPARES => false" in (root / "app" / "core" / "Database.php").read_text(encoding="utf-8"), "native PDO prepares requested")
check("GET_LOCK" in (root / "app" / "core" / "MigrationRunner.php").read_text(encoding="utf-8"), "migration concurrency lock present")
check("hash_hmac('sha256'" in (root / "app" / "repositories" / "RateLimitBucketRepository.php").read_text(encoding="utf-8"), "rate-limit identities use keyed HMAC")
check("from_status ENUM" in (root / "database" / "migrations" / "009_create_project_events.sql").read_text(encoding="utf-8"), "project event status columns use controlled enums")
challenge_sql = (root / "database" / "migrations" / "005_create_verification_challenges.sql").read_text(encoding="utf-8")
check("fk_challenges_user_channel_owner" in challenge_sql and "user_channel_id IS NOT NULL" in challenge_sql, "password-reset challenge is database-bound to its channel owner")
check("NOT_IMPORTED_RAW_OR_LEGACY_OTP" in (root / "app" / "support" / "LegacyJsonSourceInspector.php").read_text(encoding="utf-8"), "legacy OTP source is not imported")
check("assertOutsideProjectRoot" in (root / "app" / "support" / "BackupManager.php").read_text(encoding="utf-8"), "backup path is confined outside project root")
for rel in ["app/.htaccess", "database/.htaccess", "bin/.htaccess", "tests/.htaccess", "docs/.htaccess"]:
    check((root / rel).is_file() and "Require all denied" in (root / rel).read_text(encoding="utf-8"), f"direct HTTP deny exists: {rel}")

if failures:
    print(f"FAIL: {len(failures)} of {checks} Wave 2 static checks")
    for failure in failures:
        print(f" - {failure}")
    sys.exit(1)
print(f"PASS: {checks} Wave 2 static checks. SQL runtime and database integration are not implied.")
