/**
 * Hermes Agent chat client
 *
 * @module     local_hermesagent/chat
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/str', 'filter_mathjaxloader/loader'], function($, ajax, Str, mathjaxLoader) {
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
        $(document).on('click', '.hermes-conv-item', function(e) {
            // Don't navigate if clicking the rename button
            if ($(e.target).closest('.hermes-conv-rename').length) {
                return;
            }
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
                    // Update the conversation name in the list item
                    var $li = $btn.closest('.hermes-conv-item');
                    $li.find('.hermes-conv-name').text($.trim(newName));
                    $btn.data('conv-name', $.trim(newName));
                    $li.data('conv-name', $.trim(newName));
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

        // Track the raw markdown from the LLM (not the rendered HTML)
        var rawMarkdown = '';

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
                    // Track the raw markdown for saving later
                    rawMarkdown = data.full;
                    // Render markdown to HTML for display
                    setMarkdownContent(messageEl, data.full);
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
            eventSource.close();
            isStreaming = false;
            $('#hermes-send-btn').prop('disabled', false);
            $('#' + currentSpinnerId).remove();

            // Save partial content — use raw markdown, not rendered HTML text
            if (rawMarkdown) {
                var savePromises = ajax.call([{
                    methodname: 'local_hermesagent_save_assistant_response',
                    args: {
                        conversationid: conversationid,
                        content: rawMarkdown
                    }
                }]);
                savePromises[0].catch(function(ex) {
                    console.error('[Hermes] Failed to save partial response:', ex);
                });
            }

            messageEl.after('<div class="hermes-error">Connection error — check console for details.</div>');
        });

        eventSource.addEventListener('done', function(e) {
            eventSource.close();
            isStreaming = false;
            $('#hermes-send-btn').prop('disabled', false);
            $('#' + currentSpinnerId).remove();
            messageEl.removeClass('hermes-streaming');

            // Save the final assistant response — use raw markdown from LLM
            // NOT messageEl.text() which strips all HTML tags
            var finalContent = rawMarkdown;
            var savePromises = ajax.call([{
                methodname: 'local_hermesagent_save_assistant_response',
                args: {
                    conversationid: conversationid,
                    content: finalContent
                }
            }]);
            savePromises[0].catch(function(ex) {
                console.error('[Hermes] Failed to save assistant response:', ex);
            });
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
        var chatArea = $('#hermes-chat-area');
        chatArea.empty();

        var promises = [];
        messages.forEach(function(msg) {
            if (!msg || !msg.content || !msg.content.trim()) {
                return; // Skip empty messages
            }
            if (msg.role === 'user') {
                var html = '<div class="hermes-message hermes-user-message">';
                html += '<div class="hermes-avatar hermes-user-avatar">U</div>';
                html += '<div class="hermes-bubble hermes-user-bubble">';
                html += '<div class="hermes-content">' + escapeHtml(msg.content.trim()) + '</div>';
                html += '</div></div>';
                chatArea.append(html);
            } else if (msg.role === 'assistant') {
                var html = '<div class="hermes-message hermes-assistant-message">';
                html += '<div class="hermes-avatar hermes-assistant-avatar">H</div>';
                html += '<div class="hermes-bubble hermes-assistant-bubble">';
                html += '<div class="hermes-content"></div>';
                html += '</div></div>';
                chatArea.append(html);
                
                // Render markdown + math asynchronously
                var $content = chatArea.find('.hermes-content').last();
                var promise = renderMarkdown(msg.content.trim()).then(function(mdHtml) {
                    $content.html(mdHtml);
                    typesetMath($content[0]);
                });
                promises.push(promise);
            }
        });
        
        // Return promise that resolves when all messages are rendered
        return Promise.all(promises);
    };

    /**
     * Simple markdown renderer
     */
    /**
     * Marked.js - loaded from CDN on first use
     * NOTE: Moodle sets define.amd=true globally, so marked UMD tries to register
     * as an AMD module instead of setting window.marked. We must temporarily
     * hide define.amd while loading the script.
     */
    var markedInstance = null;
    var markedPromise = null;
    
    var loadMarked = function() {
        if (markedInstance) return Promise.resolve(markedInstance);
        if (markedPromise) return markedPromise;
        
        markedPromise = new Promise(function(resolve, reject) {
            // Temporarily hide define.amd so marked falls through to global export
            var savedAmd = typeof define !== 'undefined' && define.amd;
            if (typeof define !== 'undefined') {
                // @ts-ignore
                define.amd = undefined;
            }
            
            var script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/marked@15.0.0/marked.min.js';
            script.onload = function() {
                // Restore define.amd
                if (typeof define !== 'undefined' && savedAmd !== undefined) {
                    define.amd = savedAmd;
                }
                
                if (window.marked) {
                    markedInstance = window.marked;
                    // marked v15 uses setOptions()
                    markedInstance.setOptions({
                        gfm: true,
                        breaks: false,
                        headerIds: false,
                        mangle: false
                    });
                    resolve(markedInstance);
                } else {
                    reject(new Error('marked loaded but window.marked is undefined'));
                }
            };
            script.onerror = function() {
                // Restore define.amd even on error
                if (typeof define !== 'undefined' && savedAmd !== undefined) {
                    define.amd = savedAmd;
                }
                reject(new Error('Failed to load marked.js from CDN'));
            };
            document.head.appendChild(script);
        });
        
        return markedPromise;
    };
    
    /**
     * Configure Moodle's MathJax loader.
     * We call mathjaxLoader.configure() with the URL and config that
     * Moodle's MathJax filter would normally set up, then use
     * mathjaxLoader.typesetNode() (via loadMathJax + manual typesetPromise).
     *
     * NOTE: typesetNode is NOT exported — we work around it by:
     * 1. Calling configure() to set up MathJax loading
     * 2. Directly calling MathJax.typesetPromise() after loadMathJax() resolves
     */
    var mathjaxConfigured = false;
    var configureMathJax = function() {
        if (mathjaxConfigured) return;
        mathjaxConfigured = true;
        
        // Use Moodle's MathJax CDN URL (same as filter_mathjaxloader default)
        var mathjaxUrl = 'https://cdn.jsdelivr.net/npm/mathjax@4.0.0/tex-mml-chtml.js';
        
        // Configure MathJax via Moodle's loader
        mathjaxLoader.configure({
            mathjaxurl: mathjaxUrl,
            mathjaxconfig: JSON.stringify({
                tex: {
                    packages: {'[+]': 'ams'},
                    inlineMath: [['$', '$'], ['\\(', '\\)']],
                    displayMath: [['$$', '$$'], ['\\[', '\\]']]
                }
            }),
            lang: 'en'
        });
        
        console.log('[Hermes] MathJax configured via Moodle loader');
    };
    
    /**
     * Typeset math in an element using Moodle's MathJax loader
     */
    var typesetMath = function(element) {
        configureMathJax();
        
        // Use Moodle's loadMathJax promise, then chain typesetPromise
        mathjaxLoader.loadMathJax().then(function() {
            if (window.MathJax && window.MathJax.typesetPromise) {
                // Chain through startup.promise like Moodle does
                window.MathJax.startup.promise = window.MathJax.startup.promise
                    .then(function() {
                        return window.MathJax.typesetPromise([element]);
                    })
                    .catch(function(e) {
                        console.error('[Hermes] MathJax typeset error:', e);
                    });
            }
        }).catch(function(e) {
            console.error('[Hermes] MathJax load error:', e);
        });
    };
    
    /**
     * Render markdown to HTML and typeset math
     * Uses marked.js for GFM rendering + MathJax v4 for math
     */
    var renderMarkdown = function(text) {
        if (!text) return Promise.resolve('');
        text = text.trim();
        if (!text) return Promise.resolve('');
        
        // Normalize line endings
        text = text.replace(/\r\n/g, '\n');
        
        // Sanitize dangerous HTML tags but preserve safe ones
        text = text.replace(/<\s*(script|iframe|object|embed|form|link|meta|base)[^>]*>/gi, '');
        text = text.replace(/<\s*\/?(script|iframe|object|embed|form|link|meta|base)[^>]*\s*>/gi, '');
        
        return loadMarked().then(function(m) {
            var html = m.parse(text);
            return html;
        }).catch(function(err) {
            console.error('[Hermes] Failed to parse markdown:', err);
            return Promise.resolve(escapeHtml(text));
        });
    };
    
    /**
     * Set content of an element with markdown + math rendering
     */
    var setMarkdownContent = function(element, text) {
        renderMarkdown(text).then(function(html) {
            element.html(html);
            typesetMath(element[0]);
        });
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
    var shouldAutoScroll = true;

    var scrollToEnd = function(force) {
        if (force || shouldAutoScroll) {
            var chatArea = document.getElementById('hermes-chat-area');
            chatArea.scrollTop = chatArea.scrollHeight;
        }
    };

    return {
        init: init,
        setConfig: setConfig
    };
});
