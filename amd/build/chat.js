/**
 * Hermes Agent chat client
 *
 * @module     local_hermesagent/chat
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/str', 'filter_mathjaxloader/loader'], function($, ajax, Str, mathjaxLoader){
    console.log('[Hermes-JS] module loaded');
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
            var eventSource = new EventSource(
                M.cfg.wwwroot + '/local/hermesagent/api.php?action=stream&conversationid=' + conversationid + '&sesskey=' + config.sesskey
            );

        // Handle the 'message' event from api.php SSE stream
        eventSource.addEventListener('message', function(e) {
            console.log('[Hermes-SSE] message event received, raw:', e.data.substring(0, 200));
            try {
                var data = JSON.parse(e.data);
                console.log('[Hermes-SSE] parsed message - type:', data.type, 'delta_len:', (data.delta||'').length, 'full_len:', (data.full||'').length);
                if (data.full === undefined) return;
                
                // Handle reasoning separately - show as collapsible thinking
                if (data.type === 'reasoning') {
                    // Add reasoning to a collapsible section
                    var reasoningId = 'hermes-reasoning-' + msgCounter;
                    if (!$('#' + reasoningId).length) {
                        var reasoningHtml = '<details class="hermes-reasoning" id="' + reasoningId + '">';
                        reasoningHtml += '<summary class="hermes-reasoning-summary">Thinking...</summary>';
                        reasoningHtml += '<div class="hermes-reasoning-content" id="' + reasoningId + '-content"></div>';
                        reasoningHtml += '</details>';
                        messageEl.after(reasoningHtml);
                    }
                    // Update reasoning content
                    var $reasoningContent = $('#' + reasoningId + '-content');
                    setMarkdownContent($reasoningContent, data.full);
                    scrollToEnd();
                    return; // Don't accumulate reasoning in rawMarkdown
                }
                
                // Regular delta content - this is the visible answer
                rawMarkdown = data.full;
                setMarkdownContent(messageEl, data.full);
                scrollToEnd();
            } catch(ex) {
                console.error('SSE parse error:', ex, e.data);
            }
        });

        // Handle session event (new ACP session started)
        eventSource.addEventListener('session', function(e) {
            // Session started — nothing special to do on client
        });

        eventSource.addEventListener('tool_call', function(e) {
            console.log('[Hermes-SSE] tool_call event received, raw:', e.data.substring(0, 400));
            var data = JSON.parse(e.data);
            console.log('[Hermes-SSE] parsed tool_call - name:', data.tool_call?.name, 'has_result:', !!data.tool_call?.result, 'status:', data.tool_call?.status);
            // Show tool call as collapsible section in chat
            addToolCallToChat(data.tool_call);
        });

        eventSource.addEventListener('error', function(e) {
            console.error('[Hermes-SSE] Error:', e);
            console.error('[Hermes-SSE] EventSource readyState:', eventSource.readyState);
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
console.log('[Hermes-SSE] done event received');
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
     * Add tool call result to chat as a collapsible section
     */
    var addToolCallToChat = function(tc) {
        console.log('[Hermes-UI] addToolCallToChat called - name:', tc?.name, 'has_result:', !!tc?.result, 'result_type:', typeof tc?.result, 'status:', tc?.status);
        var msgId = 'hermes-tool-call-' + (msgCounter);
        var resultText = '';
        // Guard: result must be a real object with actual data, not an error object
        var hasResult = tc.result && typeof tc.result === 'object' && !tc.result.error && Object.keys(tc.result).length > 0;
        if (hasResult) {
            if (tc.result.rows) {
                resultText = buildTableMarkdown(tc.result);
            } else {
                resultText = JSON.stringify(tc.result, null, 2);
            }
        }

        var html = '<div class="hermes-message hermes-assistant-message hermes-tool-call" id="' + msgId + '">';
        html += '<div class="hermes-avatar hermes-assistant-avatar">H</div>';
        html += '<div class="hermes-bubble hermes-assistant-bubble hermes-tool-bubble">';
        html += '<details class="hermes-tool-details">';
        html += '<summary class="hermes-tool-summary">';
        html += '<span class="hermes-tool-icon">&#9881;</span> ';
        html += escapeHtml(tc.name);
        html += ' <span class="hermes-tool-status">';
        if (tc.status === 'completed') {
            html += '<span class="text-success">completed</span>';
        } else {
            html += '<span class="text-warning">executing</span>';
        }
        html += '</span>';
        html += '</summary>';
        html += '<div class="hermes-tool-input">';
        html += '<strong>Input:</strong> ';
        html += '<code>' + escapeHtml(JSON.stringify(tc.input, null, 2)) + '</code>';
        html += '</div>';
        if (hasResult) {
            html += '<div class="hermes-tool-result">';
            html += '<strong>Result:</strong> ';
            html += '<pre>' + escapeHtml(resultText) + '</pre>';
            html += '</div>';
        } else if (tc.result && tc.result.error) {
            html += '<div class="hermes-tool-result" style="color: red;">';
            html += '<strong>Error:</strong> ' + escapeHtml(tc.result.error);
            html += '</div>';
        }
        html += '</details>';
        html += '</div></div>';

        $('#hermes-chat-area').append(html);
        scrollToEnd();
    };

    /**
     * Build a simple markdown table from DB query result
     */
    var buildTableMarkdown = function(result) {
        if (!result.rows || result.rows.length === 0) {
            return '0 rows returned';
        }
        var cols = result.columns || [];
        if (cols.length === 0) return JSON.stringify(result);
        var md = '| ' + cols.join(' | ') + ' |\n| ' + cols.map(function() { return '---'; }).join(' | ') + ' |\n';
        for (var i = 0; i < result.rows.length; i++) {
            var cells = cols.map(function(c) {
                var v = result.rows[i][c];
                return v === null ? 'NULL' : String(v);
            });
            md += '| ' + cells.join(' | ') + ' |\n';
        }
        return md;
    };

    /**
     * Show tool confirmation modal (deprecated - kept for compatibility)
     */
    var showToolModal = function(toolCall) {
        var html = '<h4>' + escapeHtml(toolCall.name) + '</h4>';
        html += '<pre>' + escapeHtml(JSON.stringify(toolCall.input, null, 2)) + '</pre>';
        html += '<p>Do you want to approve this action?</p>';

        $('#hermes-tool-modal-body').html(html);
        $('#hermes-tool-modal').show();
        currentMessage = toolCall || {id: tc.id, name: tc.name, input: tc.input, result: tc.result};
    };

    /**
     * Handle tool response (approve/reject)
     */
    var handleToolResponse = function(approved) {
        if (!currentMessage) return;

        // Call api.php directly - avoids Moodle external API cache issues
        $.ajax({
            url: config.api_url + '?action=tool_response',
            type: 'POST',
            data: {
                sesskey: config.sesskey,
                messageid: currentMessage.id,
                approved: approved ? 1 : 0
            },
            success: function() {
                $('#hermes-tool-modal').hide();
                currentMessage = null;
                scrollToEnd();
            },
            error: function(ex) {
                console.error('[Hermes] handleToolResponse failed:', ex);
            }
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
     * Marked.js - loaded from CDN on first use.
     * Moodle sets define.amd=true globally, so marked UMD tries to register
     * as an AMD module instead of setting window.marked. We must temporarily
     * hide define.amd while loading the script.
     */
    var markedInstance = null;
    var markedPromise = null;
    
    var loadMarked = function() {
        if (markedInstance) return Promise.resolve(markedInstance);
        if (markedPromise) return markedPromise;
        
        markedPromise = new Promise(function(resolve, reject) {
            // Use an iframe to load marked without interfering with RequireJS
            var iframe = document.createElement('iframe');
            iframe.style.display = 'none';
            iframe.src = 'about:blank';
            document.head.appendChild(iframe);
            
            var win = iframe.contentWindow || iframe.contentDocument.defaultView;
            var doc = win.document || iframe.contentDocument;
            
            // Stub define() in iframe so marked thinks AMD exists but doesn't register
            var stubScript = doc.createElement('script');
            stubScript.text = 'var define = function() { return null; };';
            doc.head.appendChild(stubScript);
            
            var script = doc.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/marked@15.0.0/marked.min.js';
            script.onload = function() {
                if (win.marked) {
                    window.marked = win.marked;
                    markedInstance = win.marked;
                    markedInstance.setOptions({
                        gfm: true,
                        breaks: false,
                        headerIds: false,
                        mangle: false
                    });
                    document.head.removeChild(iframe);
                    resolve(markedInstance);
                } else {
                    document.head.removeChild(iframe);
                    reject(new Error('marked loaded in iframe but window.marked is undefined'));
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

    /**
     * Configure MathJax for streaming content.
     * Called once to set up MathJax config for dynamic typesetting.
     */
    var mathjaxConfigured = false;
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
        } catch(e) {
            console.warn('[Hermes] configureMathJax error:', e);
        }
    };

    /**
     * Typeset math in an element using Moodle's MathJax loader.
     * Waits for MathJax startup before calling typesetPromise.
     * @param {HTMLElement} element - DOM element containing math to typeset
     */
    var typesetMath = function(element) {
        configureMathJax();

        var loadPromise = mathjaxLoader.loadMathJax();

        loadPromise.then(function() {
            if (!window.MathJax) {
                console.error('[Hermes] MathJax not available after loadMathJax');
                return;
            }

            // Wait for startup.promise to resolve before calling typesetPromise
            // On page revisit, MathJax may still be initializing
            if (window.MathJax.startup && window.MathJax.startup.promise) {
                return window.MathJax.startup.promise.then(function() {
                    if (window.MathJax.typesetPromise) {
                        return window.MathJax.typesetPromise([element]);
                    } else {
                        console.error('[Hermes] typesetPromise not available after startup');
                    }
                });
            } else if (window.MathJax.typesetPromise) {
                // Startup already complete (edge case)
                return window.MathJax.typesetPromise([element]);
            } else {
                console.error('[Hermes] neither startup.promise nor typesetPromise available');
            }
        }).catch(function(e) {
            console.error('[Hermes] loadMathJax error:', e);
        });
    };
    
    /**
     * Math rendering pipeline — converts LLM math markup to MathJax-compatible TeX.
     *
     * Pipeline stages:
     * 1. Protect math delimiters: replace \[...\], [...], and $$...$$ with
     *    unicode placeholders so marked.js doesn't mangle them.
     * 2. Render markdown with marked.js (GFM mode).
     * 3. Unescape placeholders back to \[...\] for MathJax.
     * 4. Call MathJax.typesetPromise() to render the math in the DOM.
     *
     * String.fromCharCode(92) is used for backslash to avoid RequireJS escaping.
     */
    var BS = String.fromCharCode(92);
    var MATH_OPEN_PLACEHOLDER = String.fromCharCode(57344);
    var MATH_CLOSE_PLACEHOLDER = String.fromCharCode(57345);

    var protectMathDelimiters = function(text) {
        var result = '';
        var searchStart = 0;
        var OPEN = BS + '[';
        var CLOSE = BS + ']';
        while (searchStart < text.length) {
            var openIdx = text.indexOf(OPEN, searchStart);
            if (openIdx === -1) {
                result += text.substring(searchStart);
                break;
            }
            var closeIdx = text.indexOf(CLOSE, openIdx + OPEN.length);
            if (closeIdx === -1) {
                result += text.substring(searchStart, openIdx) + MATH_OPEN_PLACEHOLDER;
                searchStart = openIdx + OPEN.length;
                continue;
            }
            var content = text.substring(openIdx + OPEN.length, closeIdx);
            var eq = content.trim();
            if (isMathContent(eq)) {
                result += text.substring(searchStart, openIdx) + MATH_OPEN_PLACEHOLDER + content + MATH_CLOSE_PLACEHOLDER;
            } else {
                result += text.substring(searchStart, openIdx + OPEN.length);
            }
            searchStart = closeIdx + CLOSE.length;
        }

        result = protectBareBrackets(result);
        result = convertLegacyDollars(result);
        return result;
    };

    var protectBareBrackets = function(text) {
        var lineBreak = text.indexOf('\r\n') !== -1 ? '\r\n' : '\n';
        var parts = text.split(lineBreak);
        var result = [];
        for (var i = 0; i < parts.length; i++) {
            result.push(protectLineBareBrackets(parts[i]));
        }
        return result.join(lineBreak);
    };

    var protectLineBareBrackets = function(line) {
        var oi = line.indexOf('[');
        if (oi === -1) return line;
        var before = line.substring(0, oi);
        if (before.trim() !== '') return line;
        var ci = line.indexOf(']', oi + 1);
        if (ci === -1) return line;
        if (ci + 1 < line.length && line[ci + 1] === '(') return line;
        var eq = line.substring(oi + 1, ci).trim();
        if (isMathContent(eq)) {
            return before + MATH_OPEN_PLACEHOLDER + eq + MATH_CLOSE_PLACEHOLDER + line.substring(ci + 1);
        }
        return line;
    };

    var convertLegacyDollars = function(text) {
        var result = '';
        var searchStart = 0;
        while (searchStart < text.length) {
            var oi = text.indexOf('$$', searchStart);
            if (oi === -1) { result += text.substring(searchStart); break; }
            var ci = text.indexOf('$$', oi + 2);
            if (ci === -1) { result += text.substring(oi); break; }
            var eq = text.substring(oi + 2, ci).trim();
            if (isMathContent(eq)) {
                result += text.substring(searchStart, oi) + MATH_OPEN_PLACEHOLDER + eq + MATH_CLOSE_PLACEHOLDER;
            } else {
                result += text.substring(oi, ci + 2);
            }
            searchStart = ci + 2;
        }
        return result;
    };

    var unescapeMathDelimiters = function(html) {
        return html.split(MATH_OPEN_PLACEHOLDER).join(BS + '[')
                   .split(MATH_CLOSE_PLACEHOLDER).join(BS + ']');
    };

    var isMathContent = function(eq) {
        if (!eq) return false;
        var B = String.fromCharCode(92);
        return eq.indexOf('=') !== -1 || eq.indexOf('+') !== -1 ||
               eq.indexOf('-') !== -1 || eq.indexOf('^') !== -1 ||
               eq.indexOf('{') !== -1 || eq.indexOf('}') !== -1 ||
               eq.indexOf(B) !== -1 || eq.indexOf('sin') !== -1 ||
               eq.indexOf('cos') !== -1 || eq.indexOf('log') !== -1 ||
               eq.indexOf('frac') !== -1 || eq.indexOf('sqrt') !== -1 ||
               eq.indexOf('pi') !== -1 || eq.indexOf('infty') !== -1 ||
               eq.indexOf('cdot') !== -1 || eq.indexOf('times') !== -1 ||
               eq.indexOf('leq') !== -1 || eq.indexOf('geq') !== -1 ||
               eq.indexOf('neq') !== -1 || eq.indexOf('approx') !== -1 ||
               eq.indexOf('pm') !== -1 || eq.indexOf('right') !== -1 ||
               eq.indexOf('left') !== -1 || eq.indexOf('lim') !== -1 ||
               eq.indexOf('sum') !== -1 || eq.indexOf('int') !== -1;
    };

    /**
     * Render markdown to HTML (protects math delimiters during rendering).
     * @param {string} text - Markdown text possibly containing math
     * @returns {Promise<string>} HTML string with math delimiters intact
     */
    var renderMarkdown = function(text) {
        if (!text) return Promise.resolve('');
        text = text.trim();
        if (!text) return Promise.resolve('');
        text = text.replace(/\r\n/g, '\n');
        text = text.replace(/<\s*(script|iframe|object|embed|form|link|meta|base)[^>]*>/gi, '');
        text = text.replace(/<\s*\/?(script|iframe|object|embed|form|link|meta|base)[^>]*\s*>/gi, '');
        text = protectMathDelimiters(text);
        return loadMarked().then(function(m) {
            var html = m.parse(text);
            html = unescapeMathDelimiters(html);
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
            try {
                typesetMath(element[0]);
            } catch(e) {
                console.warn('[Hermes] typesetMath failed (non-fatal):', e.message);
            }
        }).catch(function(e) {
            console.warn('[Hermes] setMarkdownContent failed (non-fatal):', e.message);
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
