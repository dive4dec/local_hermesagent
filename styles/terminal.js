(function() {
    var configEl = document.getElementById('hermes-terminal-container');
    if (!configEl) return;

    var sesskey = configEl.dataset.sesskey || '';
    var wwwroot = configEl.dataset.wwwroot || window.location.origin;
    var hermesInstalled = configEl.dataset.hermesinstalled === 'true';

    var outputEl = document.getElementById('hermes-terminal-output');
    var inputEl = document.getElementById('hermes-terminal-input');

    if (!outputEl || !inputEl) return;

    // Force enable input at startup
    inputEl.disabled = false;
    inputEl.focus();

    var history = [];
    var historyIndex = -1;
    var activeCommand = null;

    function append(text, isError) {
        var div = document.createElement('div');
        div.className = 'terminal-line' + (isError ? ' terminal-error' : '');
        div.textContent = text;
        outputEl.appendChild(div);
        outputEl.scrollTop = outputEl.scrollHeight;
    }

    function escapeHtml(t) {
        var d = document.createElement('div');
        d.textContent = t;
        return d.innerHTML;
    }

    // Poll a command until it finishes
    function pollCommand(cmdId) {
        var offset = activeCommand.offset;
        
        function doPoll() {
            var url = wwwroot + '/local/hermesagent/exec.php?poll=' + cmdId + '&offset=' + offset + '&sesskey=' + sesskey;
            fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                if (data.error) {
                    append('  [Session expired]', true);
                    append('');
                    activeCommand = null;
                    inputEl.disabled = false;
                    inputEl.focus();
                    return;
                }

                if (data.output && data.output.trim()) {
                    var lines = data.output.split('\n');
                    for (var i = 0; i < lines.length; i++) {
                        if (lines[i]) {
                            append('  ' + lines[i]);
                        }
                    }
                }

                offset = data.offset;
                activeCommand.offset = offset;

                if (!data.running) {
                    if (data.exit !== null && data.exit !== undefined && data.exit !== 0) {
                        append('  [Exit code: ' + data.exit + ']', true);
                    }
                    append('');
                    activeCommand = null;
                    inputEl.disabled = false;
                    inputEl.focus();
                    checkHermesStatus();
                } else {
                    // Still running, poll again
                    setTimeout(doPoll, 500);
                }
            }).catch(function(e) {
                // Network error, keep polling
                setTimeout(doPoll, 1000);
            });
        }

        setTimeout(doPoll, 500);
    }

    // Send a command to the server
    function sendCmd(cmd) {
        append('  $ ' + escapeHtml(cmd));
        inputEl.disabled = true;
        inputEl.value = '';

        var url = wwwroot + '/local/hermesagent/exec.php';
        var body = 'command=' + encodeURIComponent(cmd) + '&sesskey=' + sesskey;

        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        }).then(function(r) { return r.json(); }).then(function(data) {
            if (data.error) {
                append('  ' + data.error, true);
                append('');
                inputEl.disabled = false;
                inputEl.focus();
                return;
            }

            if (data.running && data.id) {
                activeCommand = {id: data.id, offset: data.offset || 0};
                pollCommand(data.id);
            } else {
                // Immediate response
                if (data.output && data.output.trim()) {
                    append('  ' + data.output.trim());
                }
                append('');
                inputEl.disabled = false;
                inputEl.focus();
                checkHermesStatus();
            }
        }).catch(function(e) {
            append('  [Network error]', true);
            append('');
            inputEl.disabled = false;
            inputEl.focus();
        });
    }

    // Check if Hermes is installed
    function checkHermesStatus() {
        var url = wwwroot + '/local/hermesagent/exec.php?check=1&sesskey=' + sesskey;
        fetch(url).then(function(r) { return r.json(); }).then(function(data) {
            if (data.installed) {
                var btn = document.getElementById('btn-bootstrap');
                if (btn && btn.disabled) {
                    btn.disabled = false;
                    btn.textContent = 'Re-run Bootstrap';
                }
            }
        }).catch(function() {});
    }

    // Welcome
    append('Hermes Terminal');
    append('HERMES_HOME=${HERMES_HOME:-/var/www/moodledata/.hermes}');
    append("Type 'hermes --help' to get started.");
    append('');

    // Input handler
    inputEl.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            var cmd = inputEl.value.trim();
            if (cmd) {
                history.push(cmd);
                historyIndex = history.length;
                sendCmd(cmd);
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (historyIndex > 0) {
                historyIndex--;
                inputEl.value = history[historyIndex];
            }
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (historyIndex < history.length - 1) {
                historyIndex++;
                inputEl.value = history[historyIndex];
            } else {
                historyIndex = history.length;
                inputEl.value = '';
            }
        } else if (e.key === 'Escape') {
            inputEl.value = '';
        }
    });

    // Click to focus
    configEl.addEventListener('click', function() {
        inputEl.focus();
    });

    // Bootstrap button
    if (!hermesInstalled) {
        var btn = document.getElementById('btn-bootstrap');
        if (btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                if (!confirm('Download standalone Python (~50MB) and install Hermes Agent? This may take several minutes.')) return;
                btn.disabled = true;
                btn.textContent = 'Installing...';
                sendCmd('sh /var/www/html/public/local/hermesagent/scripts/bootstrap.sh');
            });
        }
    }
})();
