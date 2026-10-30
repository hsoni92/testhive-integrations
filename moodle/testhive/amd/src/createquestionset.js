// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Poll Core wizard state; show progress and a sanitized preview before save.
 *
 * @module     mod_testhive/createquestionset
 */
define(['jquery'], function($) {
    /**
     * @param {HTMLElement} container
     * @param {Array} preview
     * @param {string} emptyMessage
     */
    const renderPreview = function(container, preview, emptyMessage) {
        container.innerHTML = '';
        if (!preview || preview.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'text-muted';
            empty.textContent = emptyMessage;
            container.appendChild(empty);
            return;
        }
        const list = document.createElement('ol');
        list.className = 'testhive-wizard-preview-list mb-0';
        preview.forEach(function(item) {
            const li = document.createElement('li');
            li.className = 'mb-2';
            const title = document.createElement('div');
            const strong = document.createElement('strong');
            strong.textContent = item.index + '. ';
            title.appendChild(strong);
            title.appendChild(document.createTextNode(item.stem || ''));
            li.appendChild(title);
            if (item.options && item.options.length > 0) {
                const ul = document.createElement('ul');
                ul.className = 'mb-0 ms-3 small';
                item.options.forEach(function(optionText) {
                    const opt = document.createElement('li');
                    opt.textContent = optionText;
                    ul.appendChild(opt);
                });
                li.appendChild(ul);
            }
            list.appendChild(li);
        });
        container.appendChild(list);
    };

    /**
     * @param {HTMLElement} node
     * @param {Object} data
     * @param {Object} config
     */
    const renderProgress = function(node, data, config) {
        if (data.failed) {
            node.textContent = config.failedMessage + (data.message ? ' ' + data.message : '');
            return;
        }
        if (data.progressLabel) {
            node.textContent = data.progressLabel;
            return;
        }
        if (data.ready && config.readyMessage) {
            node.textContent = config.readyMessage;
        }
    };

    const poll = function(config) {
        $.ajax({
            url: config.pollUrl,
            method: 'GET',
            dataType: 'json'
        }).done(function(data) {
            const progressNode = document.getElementById('testhive-wizard-progress');
            const previewNode = document.getElementById('testhive-wizard-preview');
            if (progressNode) {
                renderProgress(progressNode, data, config);
            }
            if (previewNode && data.preview) {
                renderPreview(previewNode, data.preview, config.previewEmptyMessage);
            }
            if (data.failed) {
                return;
            }
            if (data.ready) {
                const saveButton = $('input.testhive-wizard-save, button.testhive-wizard-save, #id_finalizebutton');
                saveButton.prop('disabled', false);
                if (!config.pollWhileReady) {
                    return;
                }
            }
            window.setTimeout(function() {
                poll(config);
            }, config.pollIntervalMs || 3000);
        }).fail(function() {
            window.setTimeout(function() {
                poll(config);
            }, config.pollIntervalMs || 3000);
        });
    };

    return {
        /**
         * @param {Object} config
         */
        init: function(config) {
            poll(config);
        }
    };
});
