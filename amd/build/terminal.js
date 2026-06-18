/**
 * Terminal AMD module - HTTP polling terminal + bootstrap handler
 */

define(['jquery'], function($) {

    let outputEl = null;
    let inputEl = null;
    let history = [];
    let historyIndex = -1;

    function init(args) {
        var sesskey = args[0] ? args[0].sesskey : '';

        outputEl = $('#hermes-terminal-output');
        inputEl = $('#hermes-terminal-input');

        if (!outputEl.length || !inputEl.length) {
            return;
        }

        appendOutput('Hermes Terminal — connected');
        appendOutput('HERMES_HOME=/var/www/moodledata/.hermes');
        appendOutput("Type 'hermes --help' to get started.\n");

        inputEl.on('keydown', function(e) {
            var code = e.keyCode || e.which;
            if (code === 13) {
                var cmd = $(this).val().trim();
                if (cmd) {
                    history.push(cmd);
                    historyIndex = history.length;
                    sendCommand(cmd, sesskey);
                }
                $(this).val('');
            } else if (code === 38) {
                e.preventDefault();
                if (historyIndex > 0) {
                    historyIndex--;
                    $(this).val(history[historyIndex]);
                }
            } else if (code === 40) {
                e.preventDefault();
                if (historyIndex < history.length - 1) {
                    historyIndex++;
                    $(this).val(history[historyIndex]);
                } else {
                    historyIndex = history.length;
                    $(this).val('');
                }
            } else if (code === 27) {
                $(this).val('');
            }
        });

        $('#hermes-terminal-container').on('click', function() {
            inputEl.focus();
        });

        setTimeout(function() { inputEl.focus(); }, 100);
    }

    function initBootstrap(args) {
        var sesskey = args[0] ? args[0].sesskey : '';
        $('#btn-bootstrap').on('click', function(e) {
            e.preventDefault();
            if (!confirm('This will download standalone Python (~50MB) and install Hermes Agent. This may take a few minutes. Continue?')) {
                return;
            }
            $(this).prop('disabled', true).text('Installing...');
            sendCommand('sh /var/www/html/public/local/hermesagent/scripts/bootstrap.sh', sesskey, true);
        });
    }

    function sendCommand(cmd, sesskey, isBootstrap) {
        appendOutput('  $ ' + escapeHtml(cmd), false);
        var loadingId = 'loading-' + Date.now();
        outputEl.append('<div id="' + loadingId + '">running...</div>');
        scrollBottom();

        $.ajax({
            url: CFG.wwwroot + '/local/hermesagent/terminal.php?sesskey=' + sesskey,
            method: 'POST',
            data: {command: cmd},
            timeout: isBootstrap ? 300000 : 60000,
            dataType: 'json',
            success: function(data) {
                $('#' + loadingId).remove();
                if (data.error) {
                    appendOutput('  ' + data.error, true);
                } else if (data.output) {
                    var out = data.output.trim();
                    if (out) {
                        appendOutput('  ' + out);
                    }
                }
                if (isBootstrap && !data.error) {
                    // Refresh page after bootstrap
                    appendOutput('\nBootstrap complete! Refreshing page...');
                    setTimeout(function() { location.reload(); }, 2000);
                }
                appendOutput('');
                if (inputEl) inputEl.focus();
            },
            error: function(xhr, status, error) {
                $('#' + loadingId).remove();
                appendOutput('  [Error] ' + status + ': ' + error, true);
                appendOutput('');
                if (inputEl) inputEl.focus();
                if (isBootstrap) {
                    $('#btn-bootstrap').prop('disabled', false).text('Bootstrap Hermes');
                }
            }
        });
    }

    function appendOutput(text, isError) {
        var html = escapeHtml(text);
        html = html.replace(/\x1b\[[0-9;]*m/g, '');
        var cls = isError ? ' terminal-error' : '';
        outputEl.append('<span class="terminal-line' + cls + '">' + html + '</span>');
        scrollBottom();
    }

    function escapeHtml(text) {
        return $('<div/>').text(text).html();
    }

    function scrollBottom() {
        var el = outputEl[0];
        if (el) {
            el.scrollTop = el.scrollHeight;
        }
    }

    return {
        init: init,
        initBootstrap: initBootstrap
    };
});
