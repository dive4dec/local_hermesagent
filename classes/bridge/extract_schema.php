<?php
$link = new mysqli('mariadb', 'moodleuser', 'NJqnxkPqohs4kCyni8RVyg==', 'moodle');
if ($link->connect_error) die(json_encode(['error' => $link->connect_error]));

$key_tables = [
    'course', 'user', 'enrol', 'user_enrolments', 'role',
    'course_modules', 'course_sections', 'context',
    'groups', 'groups_members', 'groupings', 'groupings_groups',
    'grade_grades', 'grade_items', 'grade_categories',
    'quiz', 'quiz_attempts', 'quiz_slots',
    'question', 'question_versions', 'question_bank_entries',
    'role_assignments', 'role_capabilities', 'capabilities',
    'local_hermesagent_conversations', 'local_hermesagent_messages',
    'local_hermesagent_settings', 'local_hermesagent_skills',
    'config', 'config_plugins', 'modules', 'block_instances',
    'task_log', 'logstore_standard_log',
];

$schema = [];
foreach ($key_tables as $table) {
    $result = $link->query("SHOW COLUMNS FROM `{$table}`");
    if (!$result) {
        $schema[$table] = ['error' => 'table not found'];
        continue;
    }
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row;
    }
    $count_result = $link->query("SELECT COUNT(*) as cnt FROM `{$table}`");
    $count = $count_result ? $count_result->fetch_assoc()['cnt'] : 0;
    $schema[$table] = [
        'columns' => $columns,
        'row_count' => $count,
    ];
}

header('Content-Type: application/json');
echo json_encode($schema, JSON_PRETTY_PRINT);
$link->close();
