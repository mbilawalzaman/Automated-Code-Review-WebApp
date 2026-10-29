"""Phase 23 policy analyzer unit tests."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from analyzers.migration_analyzer import analyze_migrations
from analyzers.api_contract_analyzer import analyze_api_contract
from analyzers.impact_analyzer import build_impact_analysis, analyze_sensitive_changes
from deduplicator import FindingDeduplicator
from models.finding import NormalizedFinding


DESTRUCTIVE_DIFF = """
diff --git a/database/migrations/2026_07_31_000000_drop_legacy_token_from_users_table.php b/database/migrations/2026_07_31_000000_drop_legacy_token_from_users_table.php
--- a/database/migrations/2026_07_31_000000_drop_legacy_token_from_users_table.php
+++ b/database/migrations/2026_07_31_000000_drop_legacy_token_from_users_table.php
@@ -0,0 +1,12 @@
+<?php
+use Illuminate\\Database\\Migrations\\Migration;
+use Illuminate\\Database\\Schema\\Blueprint;
+use Illuminate\\Support\\Facades\\Schema;
+return new class extends Migration {
+    public function up(): void {
+        Schema::table('users', function (Blueprint $table) {
+            $table->dropColumn('legacy_token');
+        });
+    }
+};
"""

API_BREAKING_DIFF = """
diff --git a/routes/api.php b/routes/api.php
--- a/routes/api.php
+++ b/routes/api.php
@@ -10,7 +10,6 @@
-Route::get('/v1/widgets', [WidgetController::class, 'index']);
 Route::post('/v1/widgets', [WidgetController::class, 'store']);
diff --git a/app/Http/Controllers/WidgetController.php b/app/Http/Controllers/WidgetController.php
--- a/app/Http/Controllers/WidgetController.php
+++ b/app/Http/Controllers/WidgetController.php
@@ -5,8 +5,3 @@
-    public function index()
-    {
-        return response()->json([]);
-    }
"""

AUTH_DIFF = """
diff --git a/app/Http/Controllers/Auth/LoginController.php b/app/Http/Controllers/Auth/LoginController.php
--- a/app/Http/Controllers/Auth/LoginController.php
+++ b/app/Http/Controllers/Auth/LoginController.php
@@ -1,3 +1,4 @@
+// tweak auth
"""


def test_migration_analyzer_flags_drop_column():
    findings = analyze_migrations(DESTRUCTIVE_DIFF)
    assert len(findings) == 1
    assert findings[0].rule_id == "CHG-001"
    assert findings[0].is_blocking() is True
    assert findings[0].raw_output["change_class"] == "destructive_migration"


def test_api_contract_analyzer_flags_removed_route():
    findings = analyze_api_contract(API_BREAKING_DIFF)
    assert len(findings) == 1
    assert findings[0].rule_id == "CHG-003"
    assert findings[0].is_blocking() is True
    assert findings[0].raw_output["change_class"] == "api_contract"


def test_impact_analyzer_detects_auth_change():
    impact = build_impact_analysis(AUTH_DIFF)
    assert "auth" in impact["change_classes"]
    findings = analyze_sensitive_changes(AUTH_DIFF, impact)
    assert any(f.rule_id == "CHG-004" for f in findings)


def test_deduplicator_prefers_fingerprint():
    a = NormalizedFinding(
        title="Same finding",
        severity="high",
        file_path="app/Foo.php",
        line_start=10,
        rule_id="RULE-1",
        tool_name="semgrep",
    )
    b = NormalizedFinding(
        title="Same finding",
        severity="high",
        file_path="app/Foo.php",
        line_start=10,
        rule_id="RULE-1",
        tool_name="ollama",
    )
    unique = FindingDeduplicator.deduplicate([a, b])
    assert len(unique) == 1
    assert unique[0].fingerprint() == a.fingerprint()
