<?php
/**
 * CLI: collapse duplicated assistant rows produced by the pre-v0.5.12 stream
 * persistence bug ("tool call spans multiple chat boxes").
 *
 * ROOT CAUSE
 * ----------
 * Before v0.5.12, api.php discarded the return value of
 * _hermesagent_persist_assistant() on every SSE event, so each tool_call /
 * text_chunk event INSERTed a NEW assistant row instead of upserting one.
 * A single turn therefore left a RUN of consecutive assistant rows:
 *
 *   user            "List all courses"
 *   assistant 74    content=""                 tools=0   <- empty placeholder
 *   assistant 75    content="..." (1112b)       tools=0   <- the real answer
 *
 * and, for tool-heavy turns (reasoning streamed alongside tool calls):
 *
 *   assistant 823   content="..." (330b)       tools=1
 *   assistant 824   content="..." (330b)       tools=1    <- identical double
 *   assistant 825   content="..." (413b)       tools=2
 *   assistant 826   content="..." (413b)       tools=2    <- identical double
 *
 * On reload each DB row becomes its own chat bubble, so one turn rendered as
 * many bubbles (the reported symptom).
 *
 * RULE
 * ----
 * A turn is one user message followed by one assistant response. Therefore a
 * run of >=2 CONSECUTIVE assistant rows (same conversation, ordered by id) is
 * an artifact: we collapse each such run to ONE row, keeping the most
 * complete state and deleting the rest.
 *
 * "Most complete" = highest tool-call count; tie -> longest content; tie ->
 * latest timemodified; tie -> highest id (last written). Deletions are logged
 * to $CFG->tempdir/hermesagent_msg_cleanup_<ts>.json for restore-by-hand.
 *
 * SAFETY
 * ------
 * - User rows are never touched.
 * - We never pick an EMPTY row to keep if a non-empty row exists in the run
 *   (the keeper is chosen by the ranking above, so a non-empty row always
 *   outranks an empty one on content length).
 * - tool_log.messageid (FK -> messages.id) is re-pointed from any deleted
 *   row to the run's keeper before deletion, so no dangling FK.
 * - Idempotent: a clean pass finds no runs of >=2 and deletes nothing.
 *
 * USAGE (as www-data inside the Moodle pod)
 * -----------------------------------------
 *   php admin/cli/cleanup_duplicate_toolcalls.php --dry-run   # report only
 *   php admin/cli/cleanup_duplicate_toolcalls.php             # apply
 *
 * @package    local_hermesagent
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// CLI-only. Block direct web access (this file is under the webroot).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('CLI script only.');
}
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

// NOTE on cli_get_params: the VALUE in the options array doubles as the
// DEFAULT when the flag is absent (see the "apply defaults" loop in clilib).
// So a boolean flag must use `false` as its value — a help string would become
// a truthy default and flip the flag "on" even when not passed.
$options = [
    'help' => false,
    'dry-run' => false,
];
[$opts, $unrecognized] = cli_get_params($options, ['h' => 'help']);
if ($unrecognized) {
    cli_error(true, 'Unrecognised arguments: ' . implode(',', $unrecognized));
}
if ($opts['help']) {
    fwrite(STDOUT, "cleanup_duplicate_toolcalls.php\n"
        . "Collapse duplicated assistant rows from the pre-v0.5.12 stream bug.\n\n"
        . "Options:\n"
        . "  -h, --help     Print this help.\n"
        . "  --dry-run      Report runs to collapse without deleting anything.\n\n"
        . "Default: apply (delete). Use --dry-run first to preview.\n");
    exit(0);
}

function _tools_count($toolcalls_json): int {
    if (empty($toolcalls_json)) {
        return 0;
    }
    $arr = json_decode($toolcalls_json, true);
    return is_array($arr) ? count($arr) : 0;
}

$dryrun = !empty($opts['dry-run']);
$mtime = date('Ymd_His');

echo "local_hermesagent: assistant-row dedupe" . ($dryrun ? " (DRY RUN)" : "") . "\n";

$all = $DB->get_records('local_hermesagent_messages', null, 'id ASC');
$byconv = [];
foreach ($all as $r) {
    $byconv[$r->conversationid][] = $r;
}

/**
 * Given a run of assistant rows, return [keeper, deletable[]].
 * Keeper = most complete by (tools desc, content len desc, timemodified desc, id desc).
 */
function _pick_keeper(array $run) {
    $keeper = $run[0];
    foreach ($run as $r) {
        $keepTools = _tools_count($keeper->tool_calls);
        $rTools = _tools_count($r->tool_calls);
        $keepLen = strlen((string)$keeper->content);
        $rLen = strlen((string)$r->content);
        $keepTm = (int)($keeper->timemodified ?? 0);
        $rTm = (int)($r->timemodified ?? 0);
        if ($rTools > $keepTools
            || ($rTools === $keepTools && $rLen > $keepLen)
            || ($rTools === $keepTools && $rLen === $keepLen && $rTm > $keepTm)
            || ($rTools === $keepTools && $rLen === $keepLen && $rTm === $keepTm && $r->id > $keeper->id)) {
            $keeper = $r;
        }
    }
    $delete = [];
    foreach ($run as $r) {
        if ($r->id !== $keeper->id) {
            $delete[] = $r;
        }
    }
    return [$keeper, $delete];
}

$runs = 0;
$plans = []; // [keeper_id, [delete_rows]]
foreach ($byconv as $conv => $rows) {
    $n = count($rows);
    $i = 0;
    while ($i < $n) {
        if ($rows[$i]->role === 'assistant') {
            $j = $i;
            while ($j + 1 < $n && $rows[$j + 1]->role === 'assistant') {
                $j++;
            }
            $runlen = $j - $i + 1;
            if ($runlen >= 2) {
                [$keeper, $delete] = _pick_keeper(array_slice($rows, $i, $runlen));
                $runs++;
                $plans[] = ['conv' => (int)$conv, 'keeper' => $keeper, 'delete' => $delete];
            }
            $i = $j + 1;
        } else {
            $i++;
        }
    }
}

$totalDel = array_sum(array_map(fn($p) => count($p['delete']), $plans));
echo "conversations: " . count($byconv) . ", message rows: " . count($all)
    . ", assistant runs to collapse: $runs, rows to delete: $totalDel\n\n";

if ($totalDel === 0) {
    echo "Nothing to clean up.\n";
    exit(0);
}

foreach (array_slice($plans, 0, 60) as $p) {
    $k = $p['keeper'];
    $dtls = [];
    foreach ($p['delete'] as $d) {
        $dtls[] = 'id' . $d->id . ' (' . _tools_count($d->tool_calls) . ' tools, '
            . strlen((string)$d->content) . ' b)';
    }
    echo "  conv {$p['conv']}: KEEP id{$k->id} (tools=" . _tools_count($k->tool_calls)
        . ", " . strlen((string)$k->content) . " b)  DELETE: " . implode(', ', $dtls) . "\n";
}
if (count($plans) > 60) {
    echo "  ... and " . (count($plans) - 60) . " more runs\n";
}

if ($dryrun) {
    echo "\nDRY RUN: nothing deleted. Re-run without --dry-run to apply.\n";
    exit(0);
}

// Backup + apply.
$backup = [];
$deleted = 0;
$repointed = 0;
foreach ($plans as $p) {
    $keeperId = (int)$p['keeper']->id;
    foreach ($p['delete'] as $d) {
        $backup[] = [
            'conversationid' => (int)$p['conv'],
            'delete_id' => (int)$d->id,
            'keeper_id' => $keeperId,
            'role' => $d->role,
            'content' => $d->content,
            'tool_calls' => $d->tool_calls,
            'timemodified' => (int)($d->timemodified ?? 0),
        ];
        // Re-point tool_log FK (defensive; table is typically empty).
        $tl = $DB->get_records('local_hermesagent_tool_log', ['messageid' => (int)$d->id]);
        foreach ($tl as $logrec) {
            $logrec->messageid = $keeperId;
            $DB->update_record('local_hermesagent_tool_log', $logrec);
            $repointed++;
        }
        if ($DB->delete_records('local_hermesagent_messages', ['id' => (int)$d->id])) {
            $deleted++;
        }
    }
}

$backup_file = $CFG->tempdir . '/hermesagent_msg_cleanup_' . $mtime . '.json';
file_put_contents($backup_file, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "\nDeleted $deleted rows (re-pointed $repointed tool_log rows).\n";
echo "Backup (for restore): $backup_file\n";
echo "Verify: re-run with --dry-run (should report 0 runs / 0 deletes).\n";
exit(0);
