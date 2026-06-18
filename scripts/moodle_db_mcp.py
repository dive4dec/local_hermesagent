#!/usr/bin/env python3
"""MCP server for safe read-only Moodle database queries."""

import json
import subprocess
import sys

# DB config
DB_HOST = 'mariadb'
DB_NAME = 'moodle'
DB_USER = 'moodleuser'
DB_PASS = 'NJqnxkPqohs4kCyni8RVyg=='

# Sensitive columns to redact
SENSITIVE = {'password', 'password_hash', 'mnethostid', 'auth', 'lastip', 'emailstop', 'idnumber', 'passwordreset'}

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

def run_query(sql):
    check = safe_query(sql)
    if 'error' in check:
        return check
    
    php = f"""
    $link = new mysqli('{DB_HOST}', '{DB_USER}', '{DB_PASS}', '{DB_NAME}');
    if ($link->connect_error) {{ echo json_encode(['error' => $link->connect_error]); exit(1); }}
    $result = $link->query({json.dumps(sql)});
    if (!$result) {{ echo json_encode(['error' => $link->error]); exit(1); }}
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
    
    r = subprocess.run(['php', '-r', php], capture_output=True, text=True, timeout=30)
    if r.returncode != 0:
        return {'error': r.stderr.strip()[:200]}
    return json.loads(r.stdout)

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
    from mcp.server import Server
    import asyncio
    
    app = Server('moodle-db')
    
    @app.list_tools()
    async def list_tools():
        from mcp.types import Tool
        return [
            Tool(
                name='query',
                description='Run a safe read-only SQL query against the Moodle database. '
                           'Only SELECT, SHOW, DESCRIBE allowed. Results limited to 100 rows. '
                           'Sensitive columns redacted. '
                           'Key tables: mdl_course, mdl_user, mdl_user_enrolments, mdl_enrol, '
                           'mdl_role, mdl_role_assignments, mdl_course_modules, mdl_grade_grades, '
                           'mdl_groups, mdl_groups_members, mdl_config, mdl_local_hermesagent_*. '
                           'Example: SELECT COUNT(*) FROM mdl_course',
                inputSchema={
                    'type': 'object',
                    'properties': {
                        'query': {
                            'type': 'string',
                            'description': 'SQL query (SELECT only). Example: SELECT id, fullname, shortname FROM mdl_course WHERE shortname = "CS1302"'
                        }
                    },
                    'required': ['query']
                }
            ),
            Tool(
                name='list_tables',
                description='List all Moodle tables with row counts and sizes',
                inputSchema={'type': 'object', 'properties': {}, 'required': []}
            ),
            Tool(
                name='describe_table',
                description='Show the structure (columns, types) of a specific table',
                inputSchema={
                    'type': 'object',
                    'properties': {
                        'table': {
                            'type': 'string',
                            'description': 'Table name (e.g. mdl_course)'
                        }
                    },
                    'required': ['table']
                }
            ),
            Tool(
                name='schema_hints',
                description='Show key table descriptions to help construct queries. '
                           'Useful when you need to know what tables exist and what they contain.',
                inputSchema={'type': 'object', 'properties': {}, 'required': []}
            )
        ]
    
    @app.call_tool()
    async def call_tool(name, arguments):
        # Hermes ACP prefixes tool names with 'mcp_<server>_'
        # Strip the prefix if present
        if name.startswith('mcp_moodle_db_'):
            name = name[len('mcp_moodle_db_'):]
        
        if name == 'query':
            result = run_query(arguments['query'])
            return [{'type': 'text', 'text': json.dumps(result, indent=2)}]
        
        elif name == 'list_tables':
            r = subprocess.run(['php', '-r', f'''
            $link = new mysqli('{DB_HOST}', '{DB_USER}', '{DB_PASS}', '{DB_NAME}');
            if ($link->connect_error) die($link->connect_error);
            $r = $link->query("SELECT TABLE_NAME, TABLE_ROWS, ROUND(DATA_LENGTH/1024/1024,1) as size_mb FROM information_schema.TABLES WHERE TABLE_SCHEMA='{DB_NAME}' ORDER BY TABLE_NAME");
            $rows = [];
            while ($row = $r->fetch_assoc()) $rows[] = $row;
            echo json_encode($rows, JSON_PRETTY_PRINT);
            $link->close();
            '''], capture_output=True, text=True, timeout=30)
            return [{'type': 'text', 'text': r.stdout.strip()}]
        
        elif name == 'describe_table':
            table = arguments['table'].strip()
            if not table.replace('_', '').isalnum():
                return [{'type': 'text', 'text': 'Invalid table name'}]
            r = subprocess.run(['php', '-r', f'''
            $link = new mysqli('{DB_HOST}', '{DB_USER}', '{DB_PASS}', '{DB_NAME}');
            if ($link->connect_error) die($link->connect_error);
            $r = $link->query("SHOW COLUMNS FROM `{table}`");
            $cols = [];
            while ($row = $r->fetch_assoc()) $cols[] = $row;
            echo json_encode($cols, JSON_PRETTY_PRINT);
            $link->close();
            '''], capture_output=True, text=True, timeout=30)
            return [{'type': 'text', 'text': r.stdout.strip()}]
        
        elif name == 'schema_hints':
            return [{'type': 'text', 'text': json.dumps(SCHEMA_HINTS, indent=2)}]
        
        return [{'type': 'text', 'text': f'Unknown tool: {name}'}]
    
    async def main():
        from mcp.server.stdio import stdio_server
        async with stdio_server() as (read, write):
            await app.run(read, write, app.create_initialization_options())
    
    asyncio.run(main())
