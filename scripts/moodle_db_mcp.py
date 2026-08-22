#!/usr/bin/env python3
"""MCP server for safe read-only Moodle database queries.

DB credentials are read from Moodle's config.php at runtime — never hardcoded.
This makes the script work on any deployment (edb, cs1302eq-26a, etc.)
without per-instance configuration.

Ported to the `mcp` Python SDK 2.0.0 API (mcp.server.MCPServer). The legacy
1.x `Server` + `@list_tools()` / `@call_tool()` decorators were removed in
2.0.0; each tool is now a plain (async) function registered with
`@app.tool(name=..., description=...)` whose type-annotated parameters
become the JSON input schema. Served over stdio via `run_stdio_async()`.
"""

import json
import subprocess
import sys

# Path to Moodle config.php (contains $CFG->dbhost, dbuser, dbpass, etc.)
MOODLE_CONFIG = '/var/www/html/config.php'

# Sensitive columns to redact
SENSITIVE = {'password', 'password_hash', 'mnethostid', 'auth', 'lastip', 'emailstop', 'idnumber', 'passwordreset'}

# PHP header that loads DB config from Moodle's config.php
# define('CLI_SCRIPT', true) is required for CLI access to config.php
PHP_HEADER = f"""define('CLI_SCRIPT', true);
require('{MOODLE_CONFIG}');
"""

def safe_query(sql):
    sql = sql.strip().rstrip(';')
    # Block dangerous keywords
    upper = sql.upper()
    for kw in ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER', 'TRUNCATE', 'REPLACE', 'GRANT', 'REVOKE', 'UNION SELECT']:
        if kw in upper:
            return {'error': f'Query rejected: dangerous keyword ({kw})'}
    # Only allow SELECT/SHOW/DESCRIBE
    first = upper.split()[0] if upper.split() else ''
    if first not in ('SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN', 'DESC'):
        return {'error': 'Only SELECT/SHOW/DESCRIBE queries allowed'}
    # Auto-limit
    if 'LIMIT' not in upper:
        sql += ' LIMIT 100'
    return {'query': sql}

def run_php(php_code):
    """Run a PHP snippet with config.php loaded. Return (stdout, stderr, returncode)."""
    full_code = PHP_HEADER + php_code
    r = subprocess.run(['php', '-r', full_code], capture_output=True, text=True, timeout=30)
    return r.stdout, r.stderr, r.returncode

def _php_result_to_text(stdout, stderr, rc):
    """Normalise a run_php() outcome into a JSON/text string for a tool response."""
    if rc != 0:
        err_msg = stderr.strip()[:200] if stderr.strip() else stdout.strip()[:200]
        if not err_msg:
            err_msg = f'PHP exited with code {rc} (no output)'
        return json.dumps({'error': err_msg})
    return stdout.strip()

def run_query(sql):
    check = safe_query(sql)
    if 'error' in check:
        return check

    php = f"""
    $link = new mysqli($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname);
    if ($link->connect_error) {{ echo json_encode(['error' => 'DB connect failed: ' . $link->connect_error]); exit(1); }}
    $result = $link->query({json.dumps(sql)});
    if (!$result) {{ echo json_encode(['error' => 'Query failed: ' . $link->error]); exit(1); }}
    $cols = [];
    while ($f = $result->fetch_field()) $cols[] = $f->name;
    $sensitive = {json.dumps(list(SENSITIVE))};
    $rows = [];
    while ($row = $result->fetch_assoc()) {{
        foreach ($row as $k => &$v) if (in_array(strtolower($k), $sensitive)) $v = '[REDACTED]';
        $rows[] = $row;
    }}
    echo json_encode(['columns' => $cols, 'rows' => $rows, 'count' => count($rows)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $link->close();
    """

    stdout, stderr, rc = run_php(php)
    if rc != 0:
        # Include both stderr and stdout in error — stdout may have partial JSON
        err_msg = stderr.strip()[:200] if stderr.strip() else stdout.strip()[:200]
        if not err_msg:
            err_msg = f'PHP exited with code {rc} (no output)'
        return {'error': err_msg}
    try:
        return json.loads(stdout)
    except json.JSONDecodeError as e:
        return {'error': f'Invalid JSON response: {e}', 'raw_output': stdout[:200]}

# Schema info for context
SCHEMA_HINTS = {
    'mdl_course': 'Courses (id, fullname, shortname, format, visible)',
    'mdl_user': 'Users (id, firstname, lastname, email, auth)',
    'mdl_user_enrolments': 'Enrolments (userid, enrolid, status)',
    'mdl_enrol': 'Enrolment methods (courseid, enrol, status)',
    'mdl_role': 'Roles (id, name, shortname, archetype)',
    'mdl_role_assignments': 'Role assignments (userid, roleid, contextid)',
    'mdl_course_modules': 'Course activities (course, module, instance, visible)',
    'mdl_grade_grades': 'Student grades (userid, itemid, rawgrade, finalgrade)',
    'mdl_groups': 'Groups (id, courseid, name)',
    'mdl_groups_members': 'Group members (groupid, userid)',
    'mdl_config': 'Site config (name, value)',
    'mdl_local_hermesagent_conversations': 'Hermes conversations',
    'mdl_local_hermesagent_messages': 'Hermes messages',
}

if __name__ == '__main__':
    import asyncio
    from mcp.server import MCPServer

    app = MCPServer('moodle-db')

    @app.tool(
        name='query',
        description='Run a safe read-only SQL query against the Moodle database. '
                    'Only SELECT, SHOW, DESCRIBE allowed. Results limited to 100 rows. '
                    'Sensitive columns redacted. '
                    'Key tables: mdl_course, mdl_user, mdl_user_enrolments, mdl_enrol, '
                    'mdl_role, mdl_role_assignments, mdl_course_modules, mdl_grade_grades, '
                    'mdl_groups, mdl_groups_members, mdl_config, mdl_local_hermesagent_*. '
                    'Example: SELECT COUNT(*) FROM mdl_course',
    )
    async def query(query: str) -> str:
        """SQL query (SELECT only). Example: SELECT id, fullname, shortname FROM mdl_course WHERE shortname = "CS1302" """
        result = run_query(query)
        return json.dumps(result, indent=2)

    @app.tool(
        name='list_tables',
        description='List all Moodle tables with row counts and sizes',
    )
    async def list_tables() -> str:
        stdout, stderr, rc = run_php("""
        $link = new mysqli($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname);
        if ($link->connect_error) { echo json_encode(['error' => 'DB connect failed: ' . $link->connect_error]); exit(1); }
        $r = $link->query("SELECT TABLE_NAME, TABLE_ROWS, ROUND(DATA_LENGTH/1024/1024,1) as size_mb FROM information_schema.TABLES WHERE TABLE_SCHEMA='" . $CFG->dbname . "' ORDER BY TABLE_NAME");
        if (!$r) { echo json_encode(['error' => $link->error]); exit(1); }
        $rows = [];
        while ($row = $r->fetch_assoc()) $rows[] = $row;
        echo json_encode($rows, JSON_PRETTY_PRINT);
        $link->close();
        """)
        return _php_result_to_text(stdout, stderr, rc)

    @app.tool(
        name='describe_table',
        description='Show the structure (columns, types) of a specific table',
    )
    async def describe_table(table: str) -> str:
        """Table name (e.g. mdl_course) """
        table = table.strip()
        if not table.replace('_', '').isalnum():
            return json.dumps({'error': 'Invalid table name'})
        stdout, stderr, rc = run_php(f"""
        $link = new mysqli($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname);
        if ($link->connect_error) {{ echo json_encode(['error' => 'DB connect failed: ' . $link->connect_error]); exit(1); }}
        $r = $link->query("SHOW COLUMNS FROM `{table}`");
        if (!$r) {{ echo json_encode(['error' => $link->error]); exit(1); }}
        $cols = [];
        while ($row = $r->fetch_assoc()) $cols[] = $row;
        echo json_encode($cols, JSON_PRETTY_PRINT);
        $link->close();
        """)
        return _php_result_to_text(stdout, stderr, rc)

    @app.tool(
        name='schema_hints',
        description='Show key table descriptions to help construct queries. '
                    'Useful when you need to know what tables exist and what they contain.',
    )
    async def schema_hints() -> str:
        return json.dumps(SCHEMA_HINTS, indent=2)

    asyncio.run(app.run_stdio_async())
