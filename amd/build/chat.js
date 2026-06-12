/**
 * Hermes Agent chat client
 *
 * @module     local_hermesagent/chat
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/str'], function($, ajax, Str) {
    var config = {};
    var currentMessage = null;
    var isStreaming = false;

    /**
     * Initialize the chat
     */
    var init = function() {
        $(document).ready(function() {
            // Read config from data attribute on the page
            var $configEl = $('#hermes-config');
            if ($configEl.length) {
                // Use raw attribute and decode HTML entities (html_writer escapes JSON)
                try {
                    var rawConfig = $configEl[0].getAttribute('data-config');
                    var decodedConfig = $('<textarea>').html(rawConfig).text();
                    config = $.parseJSON(decodedConfig);
                } catch(e) {
                    console.error('[Hermes] Failed to parse config:', e);
                }
            }
            console.log('[Hermes] Config loaded:', config);
            setupEventListeners();
            loadHistory();
        });
    };

    /**
     * Set configuration from PHP
     */
    var setConfig = function(cfg) {
        config = JSON.parse(typeof cfg === 'string' ? cfg : JSON.stringify(cfg));
    };


    /**
     * Setup event listeners
     */
    var setupEventListeners = function() {
        // Send button
        $('#hermes-send-btn').on('click', function() {
            sendMessage();
        });

        // Enter key to send (Shift+Enter for newline)
        $('#hermes-message-input').on('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        // Conversation list clicks
        $(document).on('click', '.hermes-conv-item', function() {
            var convId = $(this).data('conv-id');
            window.location.href = M.cfg.wwwroot + '/local/hermesagent/chat.php?conversationid=' + convId;
        });

        // New conversation link
        $('#hermes-new-conv').on('click', function(e) {
            e.preventDefault();
            window.location.href = M.cfg.wwwroot + '/local/hermesagent/chat.php?action=new';
        });

        // Tool modal actions
        $('#hermes-tool-approve').on('click', function() {
            handleToolResponse(true);
        });
        $('#hermes-tool-reject').on('click', function() {
            handleToolResponse(false);
        });

        // Rename conversation
        $(document).on('click', '.hermes-conv-rename', function(e) {
            e.stopPropagation();
            var $btn = $(this);
            var convId = $btn.data('conv-id');
            var currentName = $btn.data('conv-name');

            var newName = prompt('Rename conversation:', currentName);
            if (newName && $.trim(newName) && $.trim(newName) !== currentName) {
                var renamePromises = ajax.call([{
                    methodname: 'local_hermesagent_rename_conversation',
                    args: {
                        conversationid: convId,
                        name: $.trim(newName)
                    }
                }]);

                renamePromises[0].then(function() {
                    $btn.siblings('.hermes-conv-name').text($.trim(newName));
                    $btn.data('conv-name', $.trim(newName));
                }).catch(function(ex) {
                    console.error('[Hermes] rename failed:', ex);
                });
            }
        });
    };

    /**
     * Load conversation history
     */
    var loadHistory = function() {
        var promises = ajax.call([{
            methodname: 'local_hermesagent_get_history',
            args: { conversationid: config.conversationid }
        }]);

        promises[0].then(function(data) {
            var messages = data.messages || [];
            renderMessages(messages);
            scrollToEnd();
        }).catch(function(ex) {
            console.error('[Hermes] loadHistory failed:', ex);
            $('#hermes-chat-area').append('<div class="hermes-error">Failed to load history.</div>');
        });
    };

    /**
     * Send a message
     */
    var sendMessage = function() {
        var input = $('#hermes-message-input');
        var message = input.val().trim();
        if (!message || isStreaming) return;

        // Store message BEFORE clearing
        input.data('lastmessage', message);
        input.val('');
        addUserMessage(message);

        // Start streaming response
        streamResponse(config.conversationid);
    };

    /**
     * Add user message to UI
     */
    var addUserMessage = function(content) {
        var html = '<div class="hermes-message hermes-user-message">';
        html += '<div class="hermes-avatar hermes-user-avatar">U</div>';
        html += '<div class="hermes-bubble hermes-user-bubble">';
        html += '<div class="hermes-content">' + escapeHtml(content) + '</div>';
        html += '</div></div>';

        $('#hermes-chat-area').append(html);
        scrollToEnd();
    };

    /**
     * Add assistant message to UI
     */
    var msgCounter = 0;

    /**
     * Add assistant message to UI
     */
    var addAssistantMessage = function() {
        msgCounter++;
        var msgId = 'hermes-assistant-msg-' + msgCounter;
        var contentId = 'hermes-assistant-content-' + msgCounter;
        var spinnerId = 'hermes-spinner-' + msgCounter;

        var html = '<div class="hermes-message hermes-assistant-message" id="' + msgId + '">';
        html += '<div class="hermes-avatar hermes-assistant-avatar">H</div>';
        html += '<div class="hermes-bubble hermes-assistant-bubble">';
        html += '<div class="hermes-content hermes-streaming" id="' + contentId + '"></div>';
        html += '<div class="hermes-spinner" id="' + spinnerId + '"></div>';
        html += '</div></div>';

        $('#hermes-chat-area').append(html);
        scrollToEnd();
        return $('#' + contentId);
    };

    /**
     * Stream response from ACP bridge
     */
    var streamResponse = function(conversationid) {
        isStreaming = true;
        $('#hermes-send-btn').prop('disabled', true);

        var messageEl = addAssistantMessage();
        var currentSpinnerId = 'hermes-spinner-' + msgCounter;

        // Get the message that was stored
        var message = $('#hermes-message-input').data('lastmessage') || '';

        // First save the user message via web service
        var sendPromises = ajax.call([{
            methodname: 'local_hermesagent_send_message',
            args: {
                conversationid: conversationid,
                message: message
            }
        }]);

        sendPromises[0].then(function() {
            console.log('[Hermes] User message saved, starting stream');
            
            var eventSource = new EventSource(
                M.cfg.wwwroot + '/local/hermesagent/api.php?action=stream&conversationid=' + conversationid + '&sesskey=' + config.sesskey
            );

            console.log('[Hermes] Stream URL:', eventSource.url);
            console.log('[Hermes] Sending message:', message.substring(0, 50));

        // Handle the 'message' event from api.php SSE stream
        eventSource.addEventListener('message', function(e) {
            try {
                var data = JSON.parse(e.data);
                if (data.full) {
                    messageEl.html(renderMarkdown(data.full));
                    scrollToEnd();
                }
            } catch(ex) {
                console.error('SSE parse error:', ex, e.data);
            }
        });

        // Handle session event (new ACP session started)
        eventSource.addEventListener('session', function(e) {
            // Session started — nothing special to do on client
        });

        eventSource.addEventListener('tool_call', function(e) {
            var data = JSON.parse(e.data);
            showToolModal(data);
        });

        eventSource.addEventListener('error', function(e) {
            console.error('SSE error:', e);
            if (eventSource.url) {
                console.error('URL:', eventSource.url);
            }
            messageEl.after('<div class="hermes-error">Connection error — check console for details.</div>');
            eventSource.close();
            isStreaming = false;
            $('#hermes-send-btn').prop('disabled', false);
            $('#' + currentSpinnerId).remove();
        });

        eventSource.addEventListener('done', function(e) {
            eventSource.close();
            isStreaming = false;
            $('#hermes-send-btn').prop('disabled', false);
            $('#' + currentSpinnerId).remove();
            messageEl.removeClass('hermes-streaming');
        });
        }).catch(function(ex) {
            console.error('[Hermes] streamResponse error:', ex);
            isStreaming = false;
            $('#hermes-send-btn').prop('disabled', false);
            $('#' + currentSpinnerId).remove();
        });
    };

    /**
     * Show tool confirmation modal
     */
    var showToolModal = function(toolCall) {
        var html = '<h4>' + escapeHtml(toolCall.name) + '</h4>';
        html += '<pre>' + escapeHtml(JSON.stringify(toolCall.input, null, 2)) + '</pre>';
        html += '<p>Do you want to approve this action?</p>';

        $('#hermes-tool-modal-body').html(html);
        $('#hermes-tool-modal').show();
        currentMessage = toolCall;
    };

    /**
     * Handle tool response (approve/reject)
     */
    var handleToolResponse = function(approved) {
        if (!currentMessage) return;

        var promises = ajax.call([{
            methodname: 'local_hermesagent_tool_response',
            args: {
                messageid: currentMessage.id,
                approved: approved
            }
        }]);

        promises[0].then(function() {
            $('#hermes-tool-modal').hide();
            currentMessage = null;
            scrollToEnd();
        }).catch(function(ex) {
            console.error('[Hermes] handleToolResponse failed:', ex);
        });
    };

    /**
     * Render messages to UI
     */
    var renderMessages = function(messages) {
        $('#hermes-chat-area').empty();

        messages.forEach(function(msg) {
            if (msg.role === 'user') {
                var html = '<div class="hermes-message hermes-user-message">';
                html += '<div class="hermes-avatar hermes-user-avatar">U</div>';
                html += '<div class="hermes-bubble hermes-user-bubble">';
                html += '<div class="hermes-content">' + escapeHtml(msg.content) + '</div>';
                html += '</div></div>';
                $('#hermes-chat-area').append(html);
            } else if (msg.role === 'assistant') {
                var html = '<div class="hermes-message hermes-assistant-message">';
                html += '<div class="hermes-avatar hermes-assistant-avatar">H</div>';
                html += '<div class="hermes-bubble hermes-assistant-bubble">';
                html += '<div class="hermes-content">' + renderMarkdown(msg.content) + '</div>';
                html += '</div></div>';
                $('#hermes-chat-area').append(html);
            }
        });
    };

    /**
     * Simple markdown renderer
     */
    var renderMarkdown = function(text) {
        if (!text) return '';
        // Basic markdown: code blocks, bold, italic, links
        text = text.replace(/```([^`]*)```/g, '<pre><code>$1</code></pre>');
        text = text.replace(/`([^`]*)`/g, '<code>$1</code>');
        text = text.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        text = text.replace(/\*([^*]+)\*/g, '<em>$1</em>');
        text = text.replace(/\n/g, '<br>');
        return text;
    };

    /**
     * Escape HTML
     */
    var escapeHtml = function(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    };

    /**
     * Scroll chat to bottom
     */
    var scrollToEnd = function() {
        var chatArea = document.getElementById('hermes-chat-area');
        chatArea.scrollTop = chatArea.scrollHeight;
    };

    return {
        init: init,
        setConfig: setConfig
    };
});
