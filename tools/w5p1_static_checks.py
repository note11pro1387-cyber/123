#!/usr/bin/env python3
"""Focused Wave 5/P1 source-contract checks. These are static checks, not DB integration."""
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
def read(path): return (ROOT / path).read_text(encoding='utf-8')
def require(condition, message):
    if not condition:
        print(f'FAIL: {message}')
        sys.exit(1)
    print(f'PASS: {message}')

api = read('api.php')
controller = read('app/http/controllers/ProjectController.php')
dto = read('app/dto/ProjectCreationDTO.php')
service = read('app/services/ProjectCreationService.php')
projects = read('app/repositories/ProjectRepository.php')
users = read('app/repositories/UserRepository.php')
js = read('js/neo.js')
legacy = read('app/http/LegacyActionAuthorization.php')

route = "if (in_array($action, ['saveNeo', 'getActiveProjectsCount'], true)) {"
require(route in api and api.index(route) < api.index('LegacyActionAuthorization::enforce'), 'project routes dispatch before legacy action authorization')
require("'saveNeo' => ['code' => 'PROJECT_API_UPGRADE_REQUIRED'" not in api, 'old saveNeo fail-closed gate removed only after active controller dispatch')
require("'getActiveProjectsCount' => ['code' => 'SESSION_FOUNDATION_REQUIRED'" not in api, 'capacity preflight no longer routes to a stale fail-closed gate')
require("case 'saveNeo': handleSaveNeo();" not in api and "case 'getActiveProjectsCount': handleGetActiveProjectsCount();" not in api, 'legacy JSON-backed project handlers are no longer dispatchable')
require("'saveNeo'" not in legacy and "'getActiveProjectsCount'" not in legacy, 'new project routes own their user auth and CSRF checks')
require("SessionMiddleware::start();\n            Csrf::assertValid();\n            $input = ApiRequest::jsonBody(16384);" in controller, 'create route applies session/CSRF and strict JSON boundary')
create_branch = controller[controller.index('SessionMiddleware::start();'):controller.index("SessionMiddleware::requirePrincipal('user')", controller.index('SessionMiddleware::start();'))]
require('ProjectCreationDTO::fromArray($input)' in create_branch and create_branch.index('ProjectCreationDTO::fromArray($input)') < create_branch.index("$_SERVER['HTTP_IDEMPOTENCY_KEY']"), 'DTO validation and key parsing precede principal registry/database access in create handler')
require("hash_equals($ownerPublicId, (string) ($principal['user']['public_id'] ?? ''))" in controller and "Database::connection()" in controller, 'owner identity is bound to DB-backed authenticated principal')
require('lockActiveForProjectCreation' in service and 'countActiveForOwner' in service and 'ACTIVE_PROJECT_LIMIT = 3' in service, 'active limit is server-enforced under an owner-row lock')
require('beginTransaction()' in service and 'commit()' in service and 'rollBack()' in service, 'canonical path has explicit transaction/rollback boundaries')
require("$milestones->createDefaults($projectId)" in service and "$events->append(" in service and "$audit->append(" in service and "$outbox->enqueue(" in service, 'project, milestones, event, audit and outbox are orchestrated by one transaction')
require("$idempotency->reserve(" in service and "$idempotency->complete(" in service and "IDEMPOTENCY_KEY_REUSED" in service and "IDEMPOTENCY_IN_PROGRESS" in service, 'idempotency reservation, completion and conflicting-retry guards exist')
require('getTrackingCode' not in dto and "'trackingCode' =>" not in dto and "'userId' =>" not in dto, 'DTO does not accept or create owner/status/tracking receipt fields')
require('Idempotency-Key' in js and 'createSubmissionKey' in js and 'submissionKey: this.submissionKey' in js, 'client creates and persists secure retry key')
require('trackingCode: generateTrackingCode()' not in js and 'createdAt: null' not in js and 'userId: null' not in js, 'client state no longer mints canonical tracking, time or owner')
require('normalizeCanonicalProjectReceipt(result?.data)' in js and 'window.lastSubmittedProject = canonicalReceipt' in js and 'window.lastSubmittedProject = { ...payload, ...resultData }' not in js and 'data.createdAt' in js, 'receipt is accepted only from the validated canonical server response; client payload is never merged')
require('custom-color-badge">${esc(c)}' in js and 'data-remove-color="${esc(c)}"' in js, 'custom colors are escaped in HTML and attribute contexts')
require('SELECT id FROM users WHERE id = ? AND account_status = \'active\' LIMIT 1 FOR UPDATE' in users, 'user creation lock validates active account with row lock')
require("archived_at IS NULL AND status NOT IN ('completed','rejected','cancelled')" in projects, 'active count follows non-archived state contract')
print('W5-P1 STATIC CHECKS: PASS (19 assertions; static evidence only, not integration).')
