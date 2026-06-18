# Moodle Database Schema Reference

This document describes the Moodle database structure for the local_hermesagent plugin.

## Access

Use the safe query tool:

```bash
# On the pod (recommended)
kubectl exec -n edb phpfpm-0 -- php /var/www/html/public/local/hermesagent/classes/bridge/moodle_query.php "SELECT query..."

# Or from the host
classes/bridge/moodle_db_query.sh "SELECT query..."
```

## Security

- Only SELECT, SHOW, DESCRIBE queries allowed
- Results limited to 100 rows
- Sensitive columns (password, auth, etc.) are redacted
- All queries are logged

## Key Tables

### `mdl_course` - Courses in the LMS

| Column | Description |
|--------|-------------|
| `id` | primary key |
| `fullname` | Course display name |
| `shortname` | Course code (e.g., 'CS1302') |
| `category` | Course category ID |
| `idnumber` | Institutional course ID |
| `startdate` | Course start timestamp |
| `enddate` | Course end timestamp |
| `format` | Course format (topics, weeks, etc.) |
| `visible` | 1=visible, 0=hidden |
| `enrolmentkey` | Self-enrolment password (if any) |

**Example:**
```
SELECT id, fullname, shortname, format, visible FROM mdl_course WHERE shortname = 'CS1302'
```

### `mdl_user` - Users (students, teachers, admins)

| Column | Description |
|--------|-------------|
| `id` | primary key |
| `username` | Login username |
| `firstname` | First name |
| `lastname` | Last name |
| `email` | Email address |
| `auth` | Authentication method (manual, ldap, etc.) |
| `confirmed` | 1=confirmed account |
| `mnethostid` | MNet host (1=local) |
| `lang` | Language preference |
| `timezone` | Timezone setting |

**Example:**
```
SELECT id, firstname, lastname, email, auth FROM mdl_user WHERE confirmed = 1
```

### `mdl_enrol` - Enrolment instances (methods available for each course)

| Column | Description |
|--------|-------------|
| `id` | primary key |
| `courseid` | FK to mdl_course |
| `enrol` | Enrolment plugin (manual, self, ldap, meta, etc.) |
| `status` | 0=enabled, 1=disabled |
| `customint1` | Plugin-specific (e.g., roleid for manual) |

### `mdl_user_enrolments` - User-to-course enrolments

| Column | Description |
|--------|-------------|
| `id` | primary key |
| `enrolid` | FK to mdl_enrol |
| `userid` | FK to mdl_user |
| `status` | 0=active, 1=suspended |
| `timestart` | Enrolment start time |
| `timeend` | Enrolment end time |
| `mailto` | Notify email address |

**Example:**
```
SELECT ue.userid, u.firstname, u.lastname, ue.status, e.enrol FROM mdl_user_enrolments ue JOIN mdl_user u ON u.id = ue.userid JOIN mdl_enrol e ON e.id = ue.enrolid JOIN mdl_course c ON c.id = e.courseid WHERE c.shortname = 'CS1302'
```

### `mdl_role` - Roles (Student, Teacher, Admin, etc.)

| Column | Description |
|--------|-------------|
| `id` | primary key (1=Student, 3=Teacher, 4=Manager, 5=Admin) |
| `name` | Role identifier string |
| `shortname` | Short name |
| `archetype` | Built-in archetype |
| `description` | Role description |

### `mdl_role_assignments` - Role assignments in contexts

| Column | Description |
|--------|-------------|
| `id` | primary key |
| `userid` | FK to mdl_user |
| `roleid` | FK to mdl_role |
| `contextid` | FK to mdl_context |
| `timemodified` | Assignment timestamp |

### `mdl_context` - Security contexts (system, course, module, user)

| Column | Description |
|--------|-------------|
| `id` | primary key (1=system) |
| `contextlevel` | 10=system, 40=course, 50=module, 30=user |
| `instanceid` | FK to the entity (course id, module id, etc.) |

### `mdl_course_modules` - Course modules (activities/resources within courses)

| Column | Description |
|--------|-------------|
| `id` | primary key |
| `course` | FK to mdl_course |
| `module` | FK to mdl_modules (type) |
| `instance` | Specific activity instance ID |
| `visible` | 1=visible, 0=hidden |
| `completion` | Completion tracking |

**Example:**
```
SELECT cm.id, cm.visible, m.name as activity_type FROM mdl_course_modules cm JOIN mdl_modules m ON m.id = cm.module WHERE cm.course = (SELECT id FROM mdl_course WHERE shortname = 'CS1302')
```

### `mdl_modules` - Module types (assign, quiz, forum, etc.)

| Column | Description |
|--------|-------------|
| `id` | primary key |
| `name` | Module type name (assign, quiz, forum, resource, url, book, etc.) |

### `mdl_grade_grades` - Grade grades (student grades)

| Column | Description |
|--------|-------------|
| `id` | primary key |
| `itemid` | FK to mdl_grade_items |
| `userid` | FK to mdl_user |
| `rawgrade` | Raw grade value |
| `grademax` | Maximum possible grade |
| `finalgrade` | Final calculated grade |
| `grade` | Displayed grade |

### `mdl_groups` - Course groups

| Column | Description |
|--------|-------------|
| `id` | primary key |
| `courseid` | FK to mdl_course |
| `name` | Group name |

### `mdl_groups_members` - Group membership

| Column | Description |
|--------|-------------|
| `id` | primary key |
| `groupid` | FK to mdl_groups |
| `userid` | FK to mdl_user |

### `mdl_config` - Site-wide configuration

| Column | Description |
|--------|-------------|
| `name` | Config setting name |
| `value` | Config value |

**Example:**
```
SELECT name, value FROM mdl_config WHERE name IN ('sitename', 'wwwroot', 'version')
```

### `mdl_task_log` - Scheduled/adhoc task execution log

| Column | Description |
|--------|-------------|
| `taskclass` | Task class name |
| `starttime` | Task start time |
| `endtime` | Task end time |
| `error` | Error message (if any) |
| `output` | Task output |

