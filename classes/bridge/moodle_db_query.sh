#!/bin/bash
# Moodle DB query wrapper for Hermes Agent
# Usage: moodle_db_query.sh "SELECT * FROM mdl_course WHERE shortname = 'CS1302'"

set -euo pipefail

# Check if query is provided
if [ $# -eq 0 ]; then
    echo "Usage: $0 \"SELECT query...\""
    exit 1
fi

QUERY="$1"

# Run the query on the pod
kubectl exec -n edb phpfpm-0 -- php -r "
\$link = new mysqli('mariadb', 'moodleuser', 'NJqnxkPqohs4kCyni8RVyg==', 'moodle');
if (\$link->connect_error) die(json_encode(['error' => \$link->connect_error]));

\$query = trim(\$argv[1]);
\$query = rtrim(\$query, ';');

\$dangerous = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER', 'TRUNCATE', 'REPLACE', 'GRANT', 'REVOKE'];
\$upper = strtoupper(\$query);
foreach (\$dangerous as \$kw) {
    if (strpos(\$upper, \$kw) !== false) {
        echo json_encode(['error' => 'Query rejected: ' . \$kw]);
        exit(1);
    }
}

\$allowed = ['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN', 'DESC'];
\$first = strtok(\$upper, ' \\t');
if (!in_array(\$first, \$allowed)) {
    echo json_encode(['error' => 'Only SELECT/SHOW/DESCRIBE allowed']);
    exit(1);
}

if (stripos(\$query, 'LIMIT') === false) \$query .= ' LIMIT 100';

\$result = \$link->query(\$query);
if (!\$result) {
    echo json_encode(['error' => \$link->error]);
    exit(1);
}

\$columns = [];
while (\$field = \$result->fetch_field()) \$columns[] = \$field->name;

\$sensitive = ['password', 'password_hash', 'mnethostid', 'auth', 'lastip', 'emailstop'];

\$rows = [];
while (\$row = \$result->fetch_assoc()) {
    foreach (\$row as \$k => &\$v) {
        if (in_array(strtolower(\$k), \$sensitive)) \$v = '[REDACTED]';
    }
    \$rows[] = \$row;
}

echo json_encode(['columns' => \$columns, 'rows' => \$rows, 'count' => count(\$rows)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
\$link->close();
" -- "$QUERY"
