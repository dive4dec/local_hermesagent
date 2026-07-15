/**
 * Hermes Agent chat client
 *
 * @module     local_hermesagent/chat
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'filter_mathjaxloader/loader'], function($, ajax, mathjaxLoader) {
    'use strict';

    var config = {};
    var isStreaming = false;
    var eventSourceRef = null;
    var msgCounter = 0;
    var shouldAutoScroll = true;
    var markedInstance = null;
    var markedPromise = null;
    var mathjaxConfigured = false;
    var pendingQuote = null; // {text, role} — set by reply button

    // Math delimiter placeholders (unicode private-use area)
    var BS = String.fromCharCode(92); // backslash
    var MATH_OPEN = String.fromCharCode(57344);
    var MATH_CLOSE = String.fromCharCode(57345);

    // ---------------------------------------------------------------------------
    // Initialization
    // ---------------------------------------------------------------------------

    /**
     * Initialize the chat module.
     * Reads config from #hermes-config data attribute, sets up event listeners,
     * and loads conversation history.
     */
    var init = function() {
        $(document).ready(function() {
            var $configEl = $('#hermes-config');
            if ($configEl.length) {
                try {
                    var rawConfig = $configEl[0].getAttribute('data-config');
                    var decoded = $('<textarea>').html(rawConfig).text();
                    config = $.parseJSON(decoded);
                } catch (e) {
                    console.error('[Hermes] Failed to parse config:', e);
                }
            }
            setupEventListeners();
            loadHistory();
        });
    };

    // ---------------------------------------------------------------------------
    // Event listeners
    // ---------------------------------------------------------------------------

    var setupEventListeners = function() {
        $('#hermes-send-btn').on('click', sendMessage);

        $('#hermes-message-input').on('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        // Sidebar toggle for mobile
        $('.hermes-sidebar-header').on('click', function(e) {
            // Don't toggle when clicking bulk action buttons
            if ($(e.target).closest('.hermes-bulk-actions').length) return;
            $('.hermes-sidebar').toggleClass('hermes-sidebar-open');
        });

        // Conversation list clicks (delegated)
        $(document).on('click', '.hermes-conv-item', function(e) {
            // Don't navigate when in bulk mode — let the bulk handler manage selection
            if ($(this).hasClass('hermes-bulk-mode')) return;
            // Don't navigate when clicking action buttons or checkboxes
            if ($(e.target).closest('.hermes-conv-rename, .hermes-conv-duplicate, .hermes-conv-checkbox').length) return;
            var convId = $(this).data('conv-id');
            window.location.href = M.cfg.wwwroot + '/local/hermesagent/chat.php?conversationid=' + convId;
        });

        $('#hermes-new-conv').on('click', function(e) {
            e.preventDefault();
            window.location.href = M.cfg.wwwroot + '/local/hermesagent/chat.php?action=new';
        });

        // Tool permission — buttons carry data-outcome
        $(document).on('click', '.hermes-perm-approve, .hermes-perm-approve-session, .hermes-perm-approve-always, .hermes-perm-reject', function() {
            var $c = $(this).closest('.hermes-perm-actions');
            var outcome = $(this).data('outcome') || 'allow_once';
            handlePermissionResponse($c.data('perm-id'), outcome, $c);
        });

        // Rename conversation
        $(document).on('click', '.hermes-conv-rename', function(e) {
            e.stopPropagation();
            var $btn = $(this);
            var convId = $btn.data('conv-id');
            var currentName = $btn.data('conv-name');
            var newName = prompt('Rename conversation:', currentName);
            if (newName && $.trim(newName) && $.trim(newName) !== currentName) {
                newName = $.trim(newName);
                ajax.call([{
                    methodname: 'local_hermesagent_rename_conversation',
                    args: { conversationid: convId, name: newName }
                }])[0].then(function() {
                    var $li = $btn.closest('.hermes-conv-item');
                    $li.find('.hermes-conv-name').text(newName);
                    $btn.data('conv-name', newName);
                }).catch(function(ex) {
                    console.error('[Hermes] rename failed:', ex);
                });
            }
        });

        // Duplicate single conversation
        $(document).on('click', '.hermes-conv-duplicate', function(e) {
            e.stopPropagation();
            var convId = $(this).data('conv-id');
            $(this).prop('disabled', true);
            ajax.call([{
                methodname: 'local_hermesagent_duplicate_conversation',
                args: { conversationid: convId }
            }])[0].then(function(res) {
                window.location.href = M.cfg.wwwroot + '/local/hermesagent/chat.php?conversationid=' + res.conversationid;
            }).catch(function(ex) {
                console.error('[Hermes] duplicate failed:', ex);
                alert('Duplicate failed: ' + (ex.message || ex));
            });
        });

        // --- Conversation search ---
        var $searchInput = $('#hermes-conv-search');
        var $searchClear = $('#hermes-search-clear');

        function runSearch() {
            var query = $searchInput.val().toLowerCase().trim();
            $searchClear.toggle(!!query);
            $('.hermes-conv-item').each(function() {
                var name = $(this).data('conv-name') || '';
                if (!query || name.indexOf(query) !== -1) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
            if (query && $('.hermes-conv-item:visible').length === 0) {
                if (!$('#hermes-search-empty').length) {
                    $('.hermes-conversation-list').append(
                        '<div id="hermes-search-empty" class="hermes-search-empty">No conversations found</div>'
                    );
                }
            } else {
                $('#hermes-search-empty').remove();
            }
        }

        $searchInput.on('input', runSearch);

        // Clear button
        $searchClear.on('click', function() {
            $searchInput.val('').focus();
            runSearch();
        });

        // Escape clears search
        $searchInput.on('keydown', function(e) {
            if (e.key === 'Escape') {
                $searchInput.val('');
                runSearch();
                this.blur();
            }
        });

        // --- Bulk selection mode with shift-click range selection ---

        // Long-press or right-click on a conv item enters bulk mode
        var pressTimer;
        var lastCheckedItem = null; // For shift-click range selection

        $('.hermes-conversation-list').on('contextmenu', '.hermes-conv-item', function(e) {
            e.preventDefault();
            enterBulkMode();
            $(this).find('.hermes-conv-checkbox').prop('checked', true);
            lastCheckedItem = this;
            updateBulkBar();
        });

        // Long-press for mobile
        $('.hermes-conversation-list').on('touchstart', '.hermes-conv-item', function(e) {
            var $item = $(this);
            pressTimer = setTimeout(function() {
                enterBulkMode();
                $item.find('.hermes-conv-checkbox').prop('checked', true);
                lastCheckedItem = $item[0];
                updateBulkBar();
                // Trigger haptic feedback if available
                if (navigator.vibrate) navigator.vibrate(50);
            }, 600);
        });
        $('.hermes-conversation-list').on('touchend touchmove', '.hermes-conv-item', function() {
            clearTimeout(pressTimer);
        });

        function enterBulkMode() {
            $('.hermes-conv-checkbox').show();
            $('.hermes-conv-item').addClass('hermes-bulk-mode');
        }

        function exitBulkMode() {
            $('.hermes-conv-checkbox').hide().prop('checked', false);
            $('.hermes-conv-item').removeClass('hermes-bulk-mode');
            $('.hermes-bulk-actions').hide();
            lastCheckedItem = null;
        }

        // Checkbox toggle
        $(document).on('click', '.hermes-conv-checkbox', function(e) {
            e.stopPropagation();
            updateBulkBar();
        });

        // In bulk mode, clicking an item toggles its checkbox (with shift-click range select)
        $(document).on('click', '.hermes-conv-item.hermes-bulk-mode', function(e) {
            if ($(e.target).closest('.hermes-conv-rename, .hermes-conv-duplicate').length) return;
            e.stopPropagation();

            var $items = $('.hermes-conv-item.hermes-bulk-mode:visible');
            var $current = $(this);

            if (e.shiftKey && lastCheckedItem) {
                // Range select from lastCheckedItem to this item
                var fromIdx = $items.index(lastCheckedItem);
                var toIdx = $items.index(this);
                if (fromIdx === -1 || toIdx === -1) {
                    // Fallback: just toggle this item
                    toggleItem($current);
                } else {
                    var start = Math.min(fromIdx, toIdx);
                    var end = Math.max(fromIdx, toIdx);
                    // Check all items in range
                    $items.slice(start, end + 1).each(function() {
                        $(this).find('.hermes-conv-checkbox').prop('checked', true);
                    });
                }
            } else {
                toggleItem($current);
            }

            lastCheckedItem = this;
            updateBulkBar();
        });

        function toggleItem($item) {
            var $cb = $item.find('.hermes-conv-checkbox');
            $cb.prop('checked', !$cb.prop('checked'));
        }

        function updateBulkBar() {
            var checked = $('.hermes-conv-checkbox:checked');
            if (checked.length > 0) {
                $('.hermes-bulk-actions').show();
                $('#hermes-bulk-delete, #hermes-bulk-duplicate').prop('disabled', false);
            } else {
                $('.hermes-bulk-actions').show(); // keep visible in bulk mode even with 0 checked
                $('#hermes-bulk-delete, #hermes-bulk-duplicate').prop('disabled', true);
            }
        }

        // Bulk delete
        $('#hermes-bulk-delete').on('click', function() {
            var ids = $('.hermes-conv-checkbox:checked').map(function() {
                return parseInt($(this).data('conv-id'));
            }).get();
            if (!ids.length) return;
            if (!confirm('Delete ' + ids.length + ' conversation(s)? This cannot be undone.')) return;
            ajax.call([{
                methodname: 'local_hermesagent_bulk_delete_conversations',
                args: { conversationids: ids }
            }])[0].then(function(res) {
                // Remove deleted items from DOM; reload if current conv was deleted
                var currentId = config.conversationid;
                var reloadNeeded = ids.indexOf(currentId) !== -1;
                ids.forEach(function(id) {
                    $('.hermes-conv-item[data-conv-id="' + id + '"]').remove();
                });
                exitBulkMode();
                if (reloadNeeded) {
                    window.location.href = M.cfg.wwwroot + '/local/hermesagent/chat.php';
                }
            }).catch(function(ex) {
                console.error('[Hermes] bulk delete failed:', ex);
                alert('Delete failed: ' + (ex.message || ex));
            });
        });

        // Bulk duplicate (duplicates each selected conversation)
        $('#hermes-bulk-duplicate').on('click', function() {
            var ids = $('.hermes-conv-checkbox:checked').map(function() {
                return parseInt($(this).data('conv-id'));
            }).get();
            if (!ids.length) return;
            var done = 0;
            ids.forEach(function(id) {
                ajax.call([{
                    methodname: 'local_hermesagent_duplicate_conversation',
                    args: { conversationid: id }
                }])[0].then(function() {
                    done++;
                    if (done === ids.length) {
                        exitBulkMode();
                        window.location.reload();
                    }
                }).catch(function(ex) {
                    console.error('[Hermes] duplicate failed for conv ' + id + ':', ex);
                    done++;
                    if (done === ids.length) {
                        exitBulkMode();
                        window.location.reload();
                    }
                });
            });
        });

        // Cancel bulk mode
        $('#hermes-bulk-cancel').on('click', function(e) {
            e.stopPropagation();
            exitBulkMode();
        });

        // Per-message copy button (delegated)
        $(document).on('click', '.hermes-copy-btn', function() {
            var text = $(this).data('raw-text');
            copyToClipboard(text, this);
        });

        // Per-message reply/quote button (delegated)
        $(document).on('click', '.hermes-reply-btn', function() {
            var text = $(this).data('raw-text');
            var role = $(this).data('role') || 'user';
            var label = role === 'assistant' ? 'Quoting Hermes:' : 'Quoting me:';

            // Store the quote
            pendingQuote = { text: text, role: role };

            // Show the visual quote preview bar
            $('#hermes-quote-label').text(label);
            $('#hermes-quote-text').text(text.length > 200 ? text.substring(0, 200) + '…' : text);
            $('#hermes-quote-preview').show();

            // Focus the input
            $('#hermes-message-input').focus();

            // Visual feedback on the button
            var orig = $(this).text();
            $(this).text('✓');
            var $btn = $(this);
            setTimeout(function() { $btn.text(orig); }, 1500);
        });

        // Cancel quote
        $('#hermes-quote-cancel').on('click', function() {
            pendingQuote = null;
            $('#hermes-quote-preview').hide();
        });

        // --- Edit message ---
        $(document).on('click', '.hermes-edit-btn', function() {
            var $bubble = $(this).closest('.hermes-bubble');
            var $content = $bubble.find('.hermes-content');
            var rawText = $(this).data('raw-text');
            var msgId = $(this).data('msg-id');

            // Replace content div with a textarea
            var currentText = rawText;
            var $textarea = $('<textarea class="hermes-edit-input"></textarea>');
            $textarea.val(currentText);
            $content.replaceWith($textarea);

            // Replace action buttons with save/cancel
            var $actions = $bubble.find('.hermes-msg-actions');
            $actions.hide();
            var $editActions = $(
                '<div class="hermes-edit-actions">' +
                '<button class="btn btn-success btn-sm hermes-edit-save">Save</button> ' +
                '<button class="btn btn-secondary btn-sm hermes-edit-cancel">Cancel</button>' +
                '</div>'
            );
            $actions.after($editActions);

            $textarea.focus();
            var ta = $textarea[0];
            ta.selectionStart = ta.selectionEnd = ta.value.length;
            ta.style.height = 'auto';
            ta.style.height = ta.scrollHeight + 'px';

            // Save
            $editActions.find('.hermes-edit-save').on('click', function() {
                var newContent = $textarea.val().trim();
                if (!newContent) return;
                ajax.call([{
                    methodname: 'local_hermesagent_edit_message',
                    args: { messageid: msgId, content: newContent }
                }])[0].then(function() {
                    // Re-render the message
                    var $newContent = $('<div class="hermes-content"></div>');
                    $textarea.replaceWith($newContent);
                    setMarkdownContent($newContent, newContent);
                    $editActions.remove();
                    $actions.show();
                    // Update data-raw-text on all buttons
                    $bubble.find('[data-raw-text]').attr('data-raw-text', newContent);
                }).catch(function(ex) {
                    console.error('[Hermes] edit failed:', ex);
                    alert('Edit failed: ' + (ex.message || ex));
                });
            });

            // Cancel
            $editActions.find('.hermes-edit-cancel').on('click', function() {
                var $newContent = $('<div class="hermes-content"></div>');
                $textarea.replaceWith($newContent);
                setMarkdownContent($newContent, currentText);
                $editActions.remove();
                $actions.show();
            });
        });

        // --- Delete message ---
        $(document).on('click', '.hermes-delete-btn', function() {
            var msgId = $(this).data('msg-id');
            var $msg = $(this).closest('.hermes-message');
            if (!confirm('Delete this message?')) return;
            ajax.call([{
                methodname: 'local_hermesagent_delete_message',
                args: { messageid: msgId }
            }])[0].then(function() {
                $msg.fadeOut(200, function() { $(this).remove(); });
            }).catch(function(ex) {
                console.error('[Hermes] delete failed:', ex);
                alert('Delete failed: ' + (ex.message || ex));
            });
        });

        // --- Paste image into textarea ---
        $('#hermes-message-input').on('paste', function(e) {
            var items = (e.clipboardData || e.originalEvent.clipboardData).items;
            for (var i = 0; i < items.length; i++) {
                if (items[i].type.indexOf('image/') !== -1) {
                    e.preventDefault();
                    var blob = items[i].getAsFile();
                    var reader = new FileReader();
                    reader.onload = function(ev) {
                        var dataUri = ev.target.result;
                        // Upload via web service
                        addSystemMessage('Uploading image...');
                        ajax.call([{
                            methodname: 'local_hermesagent_upload_image',
                            args: { image: dataUri, conversationid: config.conversationid }
                        }])[0].then(function(res) {
                            var input = $('#hermes-message-input');
                            // Use local_path in the markdown so Hermes can read the file directly.
                            // The browser renders this as a broken img link, so we also store the
                            // display URL in a data attribute for the renderMarkdown step.
                            var md = '![image](' + res.local_path + ')';
                            var currentVal = input.val();
                            input.val(currentVal + (currentVal ? '\n' : '') + md);
                            input.focus();
                            // Remove the "Uploading image..." system message
                            $('.hermes-system-message').last().fadeOut(200, function() { $(this).remove(); });
                        }).catch(function(ex) {
                            console.error('[Hermes] image upload failed:', ex);
                            alert('Image upload failed: ' + (ex.message || ex));
                        });
                    };
                    reader.readAsDataURL(blob);
                    break;
                }
            }
        });

        // Double-click message content to select it
        $(document).on('dblclick', '.hermes-content', function() {
            var range = document.createRange();
            range.selectNodeContents(this);
            var sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
        });

        // --- Sidebar collapse / expand ---
        $('#hermes-sidebar-collapse').on('click', function(e) {
            e.stopPropagation();
            $('.hermes-sidebar').addClass('hermes-sidebar-collapsed');
            $('#hermes-sidebar-expand').show();
        });
        $('#hermes-sidebar-expand').on('click', function() {
            $('.hermes-sidebar').removeClass('hermes-sidebar-collapsed');
            $('#hermes-sidebar-expand').hide();
        });

        // --- Sidebar resize (drag handle) ---
        var $sidebar = $('.hermes-sidebar');
        var $resizer = $('#hermes-sidebar-resizer');
        var isResizing = false;
        var startX = 0;
        var startWidth = 0;

        $resizer.on('mousedown', function(e) {
            e.preventDefault();
            e.stopPropagation();
            isResizing = true;
            startX = e.clientX;
            startWidth = $sidebar.width();
            $resizer.addClass('hermes-resizing');
            $('body').css('user-select', 'none').css('cursor', 'col-resize');
        });

        $(document).on('mousemove', function(e) {
            if (!isResizing) return;
            var delta = e.clientX - startX;
            var newWidth = startWidth + delta;
            // Clamp between 160 and 500
            newWidth = Math.max(160, Math.min(500, newWidth));
            $sidebar.css('width', newWidth + 'px').css('min-width', newWidth + 'px');
        });

        $(document).on('mouseup', function() {
            if (isResizing) {
                isResizing = false;
                $resizer.removeClass('hermes-resizing');
                $('body').css('user-select', '').css('cursor', '');
                // Persist width to localStorage
                try {
                    localStorage.setItem('hermes_sidebar_width', $sidebar.width());
                } catch (e) { /* ignore */ }
            }
        });

        // Touch support for resizing
        $resizer.on('touchstart', function(e) {
            e.preventDefault();
            isResizing = true;
            var touch = e.originalEvent.touches[0];
            startX = touch.clientX;
            startWidth = $sidebar.width();
            $resizer.addClass('hermes-resizing');
        });
        $(document).on('touchmove', function(e) {
            if (!isResizing) return;
            e.preventDefault();
            var touch = e.originalEvent.touches[0];
            var delta = touch.clientX - startX;
            var newWidth = startWidth + delta;
            newWidth = Math.max(160, Math.min(500, newWidth));
            $sidebar.css('width', newWidth + 'px').css('min-width', newWidth + 'px');
        });
        $(document).on('touchend', function() {
            if (isResizing) {
                isResizing = false;
                $resizer.removeClass('hermes-resizing');
                try {
                    localStorage.setItem('hermes_sidebar_width', $sidebar.width());
                } catch (e) { /* ignore */ }
            }
        });

        // Restore saved sidebar width
        try {
            var savedWidth = localStorage.getItem('hermes_sidebar_width');
            if (savedWidth) {
                $sidebar.css('width', savedWidth + 'px').css('min-width', savedWidth + 'px');
            }
        } catch (e) { /* ignore */ }
    };

    // ---------------------------------------------------------------------------
    // Message sending & streaming
    // ---------------------------------------------------------------------------

    var sendMessage = function() {
        var input = $('#hermes-message-input');
        var message = input.val().trim();
        if (!message) return;

        // Slash commands (!approve, !reject, /stop, /help, etc.) are allowed
        // even while streaming — they don't start a new SSE request and won't
        // interfere with the active stream.  Only normal chat messages are
        // blocked during streaming to avoid queuing multiple requests.
        if (message.startsWith('!')) {
            input.val('');
            handleApprovalCommand(message);
            return;
        }
        if (message.startsWith('/')) {
            input.val('');
            handleSlashCommand(message);
            return;
        }

        // Normal chat message — blocked while another response is streaming.
        if (isStreaming) return;

        // If there's a pending quote, prepend it as a markdown blockquote
        if (pendingQuote) {
            var quoted = pendingQuote.text.split('\n').map(function(line) {
                return '> ' + line;
            }).join('\n');
            var who = pendingQuote.role === 'assistant' ? 'Hermes' : 'Me';
            message = '> **' + who + ' said:**\n' + quoted + '\n\n' + message;
            pendingQuote = null;
            $('#hermes-quote-preview').hide();
        }

        input.data('lastmessage', message);
        input.val('');
        // Send to DB first to get message ID, then add to UI
        ajax.call([{
            methodname: 'local_hermesagent_send_message',
            args: { conversationid: config.conversationid, message: message }
        }])[0].then(function(res) {
            addUserMessage(message, res.messageid);
            streamResponse(config.conversationid);
        }).catch(function(ex) {
            console.error('[Hermes] send_message failed:', ex);
            // Still add the message and stream, just without an ID
            addUserMessage(message, null);
            streamResponse(config.conversationid);
        });
    };

    var handleSlashCommand = function(cmd) {
        var command = cmd.trim().split(/\s+/)[0].toLowerCase();
        switch (command) {
            case '/stop':
                stopStreaming();
                break;
            case '/new':
                window.location.href = M.cfg.wwwroot + '/local/hermesagent/chat.php?action=new';
                break;
            case '/clear':
                $('#hermes-chat-area').empty();
                addSystemMessage('Conversation view cleared.');
                break;
            case '/status':
                checkBridgeStatus();
                break;
            case '/help':
                addSystemMessage('Commands: /stop (abort), /new (new conversation), ' +
                    '/clear (clear view), /status (bridge health), /help\n' +
                    'Approval: !approve, !approve session, !approve always, !reject');
                break;
            default:
                addSystemMessage('Unknown command: ' + escapeHtml(command) + ' — type /help.');
        }
    };

    /**
     * Handle text-based approval commands:
     *   !approve           → allow_once
     *   !approve session   → allow_session
     *   !approve always    → allow_always
     *   !reject            → deny
     */
    var handleApprovalCommand = function(cmd) {
        var parts = cmd.trim().toLowerCase().split(/\s+/);
        var command = parts[0];
        var modifier = parts[1] || '';

        var outcome;
        if (command === '!approve') {
            if (modifier === 'session') {
                outcome = 'allow_session';
            } else if (modifier === 'always') {
                outcome = 'allow_always';
            } else {
                outcome = 'allow_once';
            }
        } else if (command === '!reject') {
            outcome = 'deny';
        } else {
            addSystemMessage('Unknown approval command. Use: !approve, !approve session, ' +
                '!approve always, or !reject');
            return;
        }

        // Find the most recent pending permission prompt
        var $pending = $('.hermes-perm-actions').last();
        if (!$pending.length) {
            addSystemMessage('No pending permission request to approve/reject.');
            return;
        }

        var permId = $pending.data('perm-id');
        handlePermissionResponse(permId, outcome, $pending);
    };

    var stopStreaming = function() {
        if (eventSourceRef) {
            eventSourceRef.close();
            eventSourceRef = null;
        }
        isStreaming = false;
        $('#hermes-send-btn').prop('disabled', false);
        $('.hermes-spinner').remove();
        $('.hermes-streaming').removeClass('hermes-streaming');
        $.ajax({
            url: config.api_url + '?action=abort&conversationid=' + config.conversationid,
            type: 'POST',
            timeout: 3000
        }).fail(function() { /* best effort */ });
        addSystemMessage('Response stopped.');
    };

    var streamResponse = function(conversationid) {
        isStreaming = true;
        $('#hermes-send-btn').prop('disabled', true);

        var messageEl = addAssistantMessage();
        var spinnerId = 'hermes-spinner-' + msgCounter;
        var rawMarkdown = '';

        // Open SSE stream directly — message was already saved in sendMessage()
        var es = new EventSource(
            M.cfg.wwwroot + '/local/hermesagent/api.php?action=stream' +
            '&conversationid=' + conversationid + '&sesskey=' + config.sesskey
        );
        eventSourceRef = es;

        es.addEventListener('message', function(e) {
                try {
                    var data = JSON.parse(e.data);
                    if (data.full === undefined) return;

                    if (data.type === 'reasoning') {
                        var rid = 'hermes-reasoning-' + msgCounter;
                        if (!$('#' + rid).length) {
                            messageEl.after(
                                '<details class="hermes-reasoning" id="' + rid + '">' +
                                '<summary class="hermes-reasoning-summary">Thinking...</summary>' +
                                '<div class="hermes-reasoning-content" id="' + rid + '-content"></div>' +
                                '</details>'
                            );
                        }
                        setMarkdownContent($('#' + rid + '-content'), data.full);
                        scrollToEnd();
                        return;
                    }

                    rawMarkdown = data.full;
                    setMarkdownContent(messageEl, data.full);
                    scrollToEnd();
                } catch (ex) {
                    console.error('[Hermes] SSE parse error:', ex);
                }
            });

            es.addEventListener('tool_call', function(e) {
                try {
                    addToolCallToChat(JSON.parse(e.data).tool_call);
                } catch (ex) {
                    console.error('[Hermes] tool_call parse error:', ex);
                }
            });

            es.addEventListener('permission', function(e) {
                try {
                    addPermissionToChat(JSON.parse(e.data));
                } catch (ex) {
                    console.error('[Hermes] permission parse error:', ex);
                }
            });

            es.addEventListener('error', function() {
                es.close();
                eventSourceRef = null;
                finishStreaming(spinnerId);
                if (rawMarkdown) {
                    saveAssistantResponse(conversationid, rawMarkdown);
                }
                messageEl.after('<div class="hermes-error">Connection error — check console for details.</div>');
            });

            es.addEventListener('done', function(e) {
                es.close();
                eventSourceRef = null;
                finishStreaming(spinnerId);
                // Save assistant response, then add action buttons with the message ID
                saveAssistantResponse(conversationid, rawMarkdown).then(function(res) {
                    var msgId = res && res.messageid ? res.messageid : null;
                    messageEl.parent().append(buildMessageActions(rawMarkdown, 'assistant', msgId));
                });
            });

            es.addEventListener('aborted', function() {
                es.close();
                eventSourceRef = null;
                finishStreaming(spinnerId);
                addSystemMessage('Response stopped by user.');
            });
    };

    /**
     * Reset streaming UI state (re-enable send button, remove spinner).
     */
    var finishStreaming = function(spinnerId) {
        isStreaming = false;
        $('#hermes-send-btn').prop('disabled', false);
        $('#' + spinnerId).remove();
        $('.hermes-streaming').removeClass('hermes-streaming');
    };

    /**
     * Save assistant response to DB via web service.
     */
    var saveAssistantResponse = function(conversationid, content) {
        return ajax.call([{
            methodname: 'local_hermesagent_save_assistant_response',
            args: { conversationid: conversationid, content: content }
        }])[0].catch(function(ex) {
            console.error('[Hermes] Failed to save assistant response:', ex);
        });
    };

    // ---------------------------------------------------------------------------
    // Tool approval (inline, non-blocking)
    // ---------------------------------------------------------------------------

    var addPermissionToChat = function(permData) {
        var permId = permData.permission_id;
        var title = permData.title || 'Tool execution requested';
        var desc = permData.description || '';

        // Re-enable the Send button so the user can type !approve / !reject
        // (the button was disabled by streamResponse).  The SSE stream stays
        // open; permission responses go via a separate POST endpoint.
        $('#hermes-send-btn').prop('disabled', false);

        var html = '<div class="hermes-message hermes-assistant-message hermes-perm-request">' +
            '<div class="hermes-avatar hermes-assistant-avatar">H</div>' +
            '<div class="hermes-bubble hermes-assistant-bubble hermes-perm-bubble">' +
            '<div class="hermes-perm-header">' +
            '<span class="hermes-perm-icon">&#9881;</span> ' +
            '<strong>' + escapeHtml(title) + '</strong>' +
            '</div>';
        if (desc) {
            html += '<pre class="hermes-perm-desc">' + escapeHtml(desc) + '</pre>';
        }
        html += '<div class="hermes-perm-prompt">Approve this action?</div>' +
            '<div class="hermes-perm-actions" data-perm-id="' + permId + '">' +
            '<button class="btn btn-success btn-sm hermes-perm-approve" data-outcome="allow_once">Approve</button> ' +
            '<button class="btn btn-success btn-sm hermes-perm-approve-session" data-outcome="allow_session">Approve session</button> ' +
            '<button class="btn btn-success btn-sm hermes-perm-approve-always" data-outcome="allow_always">Approve always</button> ' +
            '<button class="btn btn-danger btn-sm hermes-perm-reject" data-outcome="deny">Reject</button>' +
            '</div></div></div>';

        $('#hermes-chat-area').append(html);
        scrollToEnd();
    };

    var handlePermissionResponse = function(permId, outcome, $btnContainer) {
        if (permId === null || permId === undefined) return;

        $btnContainer.find('button').prop('disabled', true);

        $.ajax({
            url: config.api_url + '?action=permission_response',
            type: 'POST',
            data: {
                sesskey: config.sesskey,
                permission_id: permId,
                outcome: outcome
            }
        }).done(function(resp) {
            if (resp && resp.status === 'error') {
                $btnContainer.html('<span class="text-danger">⚠ ' +
                    escapeHtml(resp.message || 'Permission request failed') + '</span>');
            } else {
                var labels = {
                    'allow_once': '✓ Approved',
                    'allow_session': '✓ Approved for session',
                    'allow_always': '✓ Approved always',
                    'deny': '✗ Rejected'
                };
                var cls = outcome === 'deny' ? 'danger' : 'success';
                $btnContainer.html('<span class="text-' + cls + '">' +
                    (labels[outcome] || '✓ Done') + '</span>');
            }
            scrollToEnd();
        }).fail(function(ex) {
            console.error('[Hermes] permission response failed:', ex);
            $btnContainer.html('<span class="text-danger">⚠ Failed to reach server' +
                (ex.statusText ? ': ' + escapeHtml(ex.statusText) : '') + '</span>');
            scrollToEnd();
        });
    };

    // ---------------------------------------------------------------------------
    // Tool call display
    // ---------------------------------------------------------------------------

    var addToolCallToChat = function(tc) {
        var msgId = 'hermes-tool-call-' + msgCounter;
        var hasResult = tc.result && typeof tc.result === 'object' &&
            !tc.result.error && Object.keys(tc.result).length > 0;
        var resultText = hasResult
            ? (tc.result.rows ? buildTableMarkdown(tc.result) : JSON.stringify(tc.result, null, 2))
            : '';

        var html = '<div class="hermes-message hermes-assistant-message hermes-tool-call" id="' + msgId + '">' +
            '<div class="hermes-avatar hermes-assistant-avatar">H</div>' +
            '<div class="hermes-bubble hermes-assistant-bubble hermes-tool-bubble">' +
            '<details class="hermes-tool-details">' +
            '<summary class="hermes-tool-summary">' +
            '<span class="hermes-tool-icon">&#9881;</span> ' + escapeHtml(tc.name) +
            ' <span class="hermes-tool-status">' +
            (tc.status === 'completed'
                ? '<span class="text-success">completed</span>'
                : '<span class="text-warning">executing</span>') +
            '</span></summary>' +
            '<div class="hermes-tool-input"><strong>Input:</strong> ' +
            '<code>' + escapeHtml(JSON.stringify(tc.input, null, 2)) + '</code></div>';

        if (hasResult) {
            html += '<div class="hermes-tool-result"><strong>Result:</strong> ' +
                '<pre>' + escapeHtml(resultText) + '</pre></div>';
        } else if (tc.result && tc.result.error) {
            html += '<div class="hermes-tool-result" style="color: red;">' +
                '<strong>Error:</strong> ' + escapeHtml(tc.result.error) + '</div>';
        }

        html += '</details></div></div>';
        $('#hermes-chat-area').append(html);
        scrollToEnd();
    };

    var buildTableMarkdown = function(result) {
        if (!result.rows || result.rows.length === 0) return '0 rows returned';
        var cols = result.columns || [];
        if (cols.length === 0) return JSON.stringify(result);
        var md = '| ' + cols.join(' | ') + ' |\n| ' + cols.map(function() { return '---'; }).join(' | ') + ' |\n';
        result.rows.forEach(function(row) {
            md += '| ' + cols.map(function(c) {
                var v = row[c];
                return v === null ? 'NULL' : String(v);
            }).join(' | ') + ' |\n';
        });
        return md;
    };

    // ---------------------------------------------------------------------------
    // Message rendering
    // ---------------------------------------------------------------------------

    var loadHistory = function() {
        ajax.call([{
            methodname: 'local_hermesagent_get_history',
            args: { conversationid: config.conversationid }
        }])[0].then(function(data) {
            renderMessages(data.messages || []);
            scrollToEnd();
        }).catch(function(ex) {
            console.error('[Hermes] loadHistory failed:', ex);
            $('#hermes-chat-area').append('<div class="hermes-error">Failed to load history.</div>');
        });
    };

    var addUserMessage = function(content, msgId) {
        msgCounter++;
        var contentId = 'hermes-user-content-' + msgCounter;
        var actionsHtml = buildMessageActions(content, 'user', msgId);
        var timeHtml = buildTimestamp(null);
        $('#hermes-chat-area').append(
            '<div class="hermes-message hermes-user-message">' +
            '<div class="hermes-avatar hermes-user-avatar">U</div>' +
            '<div class="hermes-bubble hermes-user-bubble">' +
            timeHtml +
            '<div class="hermes-content" id="' + contentId + '"></div>' +
            actionsHtml +
            '</div></div>'
        );
        setMarkdownContent($('#' + contentId), content);
        scrollToEnd();
    };

    var addAssistantMessage = function() {
        msgCounter++;
        var contentId = 'hermes-assistant-content-' + msgCounter;
        var spinnerId = 'hermes-spinner-' + msgCounter;
        var timeHtml = buildTimestamp(null);

        $('#hermes-chat-area').append(
            '<div class="hermes-message hermes-assistant-message" id="hermes-assistant-msg-' + msgCounter + '">' +
            '<div class="hermes-avatar hermes-assistant-avatar">H</div>' +
            '<div class="hermes-bubble hermes-assistant-bubble">' +
            timeHtml +
            '<div class="hermes-content hermes-streaming" id="' + contentId + '"></div>' +
            '<div class="hermes-spinner" id="' + spinnerId + '"></div>' +
            '</div></div>'
        );
        scrollToEnd();
        return $('#' + contentId);
    };

    var addSystemMessage = function(text) {
        var $el = $(
            '<div class="hermes-system-message">' +
            '<div class="hermes-system-icon">⚙</div>' +
            '<div class="hermes-system-content">' + escapeHtml(text) + '</div>' +
            '</div>'
        );
        $('#hermes-chat-area').append($el);
        scrollToEnd();
        return $el;
    };

    /**
     * Build a timestamp element for a message.
     * @param {number|null} ts - Unix timestamp (seconds), or null for now
     * @returns {string} HTML for the timestamp
     */
    var buildTimestamp = function(ts) {
        var now = new Date();
        var date = ts ? new Date(ts * 1000) : now;
        var h = String(date.getHours()).padStart(2, '0');
        var m = String(date.getMinutes()).padStart(2, '0');
        var label = h + ':' + m;
        // Show date if not today
        var today = new Date();
        if (date.toDateString() !== today.toDateString()) {
            var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            label = months[date.getMonth()] + ' ' + date.getDate() + ', ' + label;
        }
        return '<div class="hermes-msg-time">' + escapeHtml(label) + '</div>';
    };

    /**
     * Build action buttons HTML for a message.
     * @param {string} content - Raw message text
     * @param {string} role - 'user' or 'assistant'
     * @param {number|null} msgId - Message ID from DB (null for streaming)
     */
    var buildMessageActions = function(content, role, msgId) {
        var escaped = escapeHtml(content);
        var idAttr = msgId ? ' data-msg-id="' + msgId + '"' : '';
        return '<div class="hermes-msg-actions">' +
            '<button class="hermes-reply-btn" data-raw-text="' + escaped + '" data-role="' + role + '" title="Quote">↩</button>' +
            '<button class="hermes-copy-btn" data-raw-text="' + escaped + '" title="Copy">📋</button>' +
            (msgId ? '<button class="hermes-edit-btn" data-raw-text="' + escaped + '"' + idAttr + ' title="Edit">✎</button>' +
             '<button class="hermes-delete-btn"' + idAttr + ' title="Delete">🗑</button>' : '') +
            '</div>';
    };

    var renderMessages = function(messages) {
        var chatArea = $('#hermes-chat-area');
        chatArea.empty();
        var promises = [];

        messages.forEach(function(msg, i) {
            if (!msg || !msg.content || !msg.content.trim()) return;
            var content = msg.content.trim();

            if (msg.role === 'user') {
                var userContentId = 'hermes-hist-user-' + i;
                var userActions = buildMessageActions(content, 'user', msg.id);
                var userTime = buildTimestamp(msg.timemodified);
                chatArea.append(
                    '<div class="hermes-message hermes-user-message">' +
                    '<div class="hermes-avatar hermes-user-avatar">U</div>' +
                    '<div class="hermes-bubble hermes-user-bubble">' +
                    userTime +
                    '<div class="hermes-content" id="' + userContentId + '"></div>' +
                    userActions +
                    '</div></div>'
                );
                (function(cid, text) {
                    var $el = $('#' + cid);
                    promises.push(
                        renderMarkdown(text).then(function(mdHtml) {
                            $el.html(mdHtml);
                            typesetMath($el[0]);
                        })
                    );
                })(userContentId, content);
            } else if (msg.role === 'assistant') {
                var assistantContentId = 'hermes-hist-asst-' + i;
                var asstActions = buildMessageActions(content, 'assistant', msg.id);
                var asstTime = buildTimestamp(msg.timemodified);
                chatArea.append(
                    '<div class="hermes-message hermes-assistant-message">' +
                    '<div class="hermes-avatar hermes-assistant-avatar">H</div>' +
                    '<div class="hermes-bubble hermes-assistant-bubble">' +
                    asstTime +
                    '<div class="hermes-content" id="' + assistantContentId + '"></div>' +
                    asstActions +
                    '</div></div>'
                );
                (function(cid, text) {
                    var $el = chatArea.find('#' + cid);
                    promises.push(
                        renderMarkdown(text).then(function(mdHtml) {
                            $el.html(mdHtml);
                            typesetMath($el[0]);
                        })
                    );
                })(assistantContentId, content);
            }
        });

        return Promise.all(promises);
    };

    // ---------------------------------------------------------------------------
    // Markdown & Math rendering
    // ---------------------------------------------------------------------------

    /**
     * Load marked.js from CDN via hidden iframe (avoids RequireJS/UMD conflict).
     */
    var loadMarked = function() {
        if (markedInstance) return Promise.resolve(markedInstance);
        if (markedPromise) return markedPromise;

        markedPromise = new Promise(function(resolve, reject) {
            var iframe = document.createElement('iframe');
            iframe.style.display = 'none';
            iframe.src = 'about:blank';
            document.head.appendChild(iframe);

            var win = iframe.contentWindow || iframe.contentDocument.defaultView;
            var doc = win.document || iframe.contentDocument;

            // Stub define() so marked UMD doesn't register as AMD
            var stub = doc.createElement('script');
            stub.text = 'var define = function() { return null; };';
            doc.head.appendChild(stub);

            var script = doc.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/marked@15.0.0/marked.min.js';
            script.onload = function() {
                if (win.marked) {
                    window.marked = win.marked;
                    markedInstance = win.marked;
                    markedInstance.setOptions({ gfm: true, breaks: false, headerIds: false, mangle: false });
                    document.head.removeChild(iframe);
                    resolve(markedInstance);
                } else {
                    document.head.removeChild(iframe);
                    reject(new Error('marked loaded but window.marked is undefined'));
                }
            };
            script.onerror = function() {
                document.head.removeChild(iframe);
                reject(new Error('Failed to load marked.js from CDN'));
            };
            doc.head.appendChild(script);
        });

        return markedPromise;
    };

    var configureMathJax = function() {
        if (mathjaxConfigured) return;
        mathjaxConfigured = true;
        try {
            if (window.MathJax) {
                window.MathJax.Hub.Config({
                    tex2jax: { inlineMath: [['$', '$'], ['\\(', '\\)']], displayMath: [['\\[', '\\]']] },
                    showProcessingMessages: false,
                    messageStyle: 'none'
                });
            }
        } catch (e) {
            console.warn('[Hermes] MathJax config error:', e);
        }
    };

    var typesetMath = function(element) {
        configureMathJax();
        mathjaxLoader.loadMathJax().then(function() {
            if (!window.MathJax) {
                console.error('[Hermes] MathJax not available after loadMathJax');
                return;
            }
            if (window.MathJax.startup && window.MathJax.startup.promise) {
                return window.MathJax.startup.promise.then(function() {
                    if (window.MathJax.typesetPromise) {
                        return window.MathJax.typesetPromise([element]);
                    }
                });
            } else if (window.MathJax.typesetPromise) {
                return window.MathJax.typesetPromise([element]);
            }
        }).catch(function(e) {
            console.error('[Hermes] MathJax error:', e);
        });
    };

    /**
     * Render markdown to HTML, protecting math delimiters from marked.js.
     * Pipeline: protect \[...\] and $$...$$ → render markdown → restore delimiters.
     */
    var renderMarkdown = function(text) {
        if (!text || !text.trim()) return Promise.resolve('');
        text = text.trim().replace(/\r\n/g, '\n');
        // Strip dangerous tags
        text = text.replace(/<\s*\/?(script|iframe|object|embed|form|link|meta|base)[^>]*>/gi, '');
        // Rewrite local file paths in image markdown to image.php URLs for browser display
        text = text.replace(/!\[([^\]]*)\]\((\/var\/www\/moodledata\/\.hermes\/images\/[^)]+)\)/g, function(match, alt, path) {
            var filename = path.split('/').pop();
            return '![' + alt + '](' + M.cfg.wwwroot + '/local/hermesagent/image.php?f=' + encodeURIComponent(filename) + ')';
        });
        text = protectMathDelimiters(text);
        return loadMarked().then(function(m) {
            return unescapeMathDelimiters(m.parse(text));
        }).catch(function(err) {
            console.error('[Hermes] markdown parse failed:', err);
            return escapeHtml(text);
        });
    };

    var setMarkdownContent = function(element, text) {
        renderMarkdown(text).then(function(html) {
            element.html(html);
            try { typesetMath(element[0]); } catch (e) { /* non-fatal */ }
        }).catch(function(e) { /* non-fatal */ });
    };

    // --- Math delimiter protection ---

    var isMathContent = function(eq) {
        if (!eq) return false;
        return /[=+\-^{}]/.test(eq) || eq.indexOf(BS) !== -1 ||
            /\b(sin|cos|tan|log|frac|sqrt|pi|infty|cdot|times|leq|geq|neq|approx|pm|right|left|lim|sum|int)\b/.test(eq);
    };

    var protectMathDelimiters = function(text) {
        var result = '';
        var start = 0;
        var OPEN = BS + '[';
        var CLOSE = BS + ']';

        while (start < text.length) {
            var oi = text.indexOf(OPEN, start);
            if (oi === -1) { result += text.substring(start); break; }
            var ci = text.indexOf(CLOSE, oi + OPEN.length);
            if (ci === -1) {
                result += text.substring(start, oi) + MATH_OPEN;
                start = oi + OPEN.length;
                continue;
            }
            var content = text.substring(oi + OPEN.length, ci);
            if (isMathContent(content.trim())) {
                result += text.substring(start, oi) + MATH_OPEN + content + MATH_CLOSE;
            } else {
                result += text.substring(start, oi + OPEN.length);
            }
            start = ci + CLOSE.length;
        }

        return convertLegacyDollars(protectBareBrackets(result));
    };

    var protectBareBrackets = function(text) {
        var nl = text.indexOf('\r\n') !== -1 ? '\r\n' : '\n';
        return text.split(nl).map(protectLineBareBrackets).join(nl);
    };

    var protectLineBareBrackets = function(line) {
        var oi = line.indexOf('[');
        if (oi === -1 || line.substring(0, oi).trim() !== '') return line;
        var ci = line.indexOf(']', oi + 1);
        if (ci === -1 || (ci + 1 < line.length && line[ci + 1] === '(')) return line;
        var eq = line.substring(oi + 1, ci).trim();
        if (isMathContent(eq)) {
            return line.substring(0, oi) + MATH_OPEN + eq + MATH_CLOSE + line.substring(ci + 1);
        }
        return line;
    };

    var convertLegacyDollars = function(text) {
        var result = '';
        var start = 0;
        while (start < text.length) {
            var oi = text.indexOf('$$', start);
            if (oi === -1) { result += text.substring(start); break; }
            var ci = text.indexOf('$$', oi + 2);
            if (ci === -1) { result += text.substring(oi); break; }
            var eq = text.substring(oi + 2, ci).trim();
            if (isMathContent(eq)) {
                result += text.substring(start, oi) + MATH_OPEN + eq + MATH_CLOSE;
            } else {
                result += text.substring(oi, ci + 2);
            }
            start = ci + 2;
        }
        return result;
    };

    var unescapeMathDelimiters = function(html) {
        return html.split(MATH_OPEN).join(BS + '[').split(MATH_CLOSE).join(BS + ']');
    };

    // ---------------------------------------------------------------------------
    // Utilities
    // ---------------------------------------------------------------------------

    var escapeHtml = function(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    };

    /**
     * Copy text to clipboard. Uses navigator.clipboard if available,
     * falls back to a hidden textarea + execCommand.
     */
    var copyToClipboard = function(text, btn) {
        var done = function() {
            if (btn) {
                var orig = btn.textContent;
                btn.textContent = '✓';
                setTimeout(function() { btn.textContent = orig; }, 1500);
            }
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function() {
                fallbackCopy(text);
                done();
            });
        } else {
            fallbackCopy(text);
            done();
        }
    };

    var fallbackCopy = function(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) { /* ignore */ }
        document.body.removeChild(ta);
    };

    var scrollToEnd = function(force) {
        if (force || shouldAutoScroll) {
            var area = document.getElementById('hermes-chat-area');
            area.scrollTop = area.scrollHeight;
        }
    };

    var checkBridgeStatus = function() {
        var $msg = addSystemMessage('Checking bridge status...');
        $.ajax({
            url: config.api_url + '?action=status&conversationid=' + config.conversationid,
            type: 'GET',
            timeout: 5000,
            dataType: 'json'
        }).done(function(res) {
            var detail = 'Bridge: ' + (res.status || 'unknown');
            if (res.port) detail += ' (port ' + res.port + ')';
            $msg.find('.hermes-system-content').text(detail);
        }).fail(function() {
            $msg.find('.hermes-system-content').text('Bridge: unreachable');
        });
    };

    // ---------------------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------------------

    return {
        init: init
    };
});
