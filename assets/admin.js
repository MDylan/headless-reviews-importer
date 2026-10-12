/* Settings page: tabs, API dependent rows, write-only fields, action buttons */
(function () {
    'use strict';

    var wrap = document.querySelector('.hri-settings');
    if (!wrap) {
        return;
    }

    var l10n = window.hriAdmin || {};
    var form = wrap.querySelector('.hri-form');

    /* Tabs: the link's hash is the key of the tab, the panel is #hri-tab-{key} */
    var tabs = wrap.querySelectorAll('.hri-tabs .nav-tab');

    function showTab(key) {
        var panel = key ? document.getElementById('hri-tab-' + key) : null;
        if (!panel || !panel.classList.contains('hri-panel')) {
            return false;
        }
        tabs.forEach(function (tab) {
            tab.classList.toggle('nav-tab-active', tab.hash === '#' + key);
        });
        wrap.querySelectorAll('.hri-panel').forEach(function (other) {
            other.classList.toggle('is-active', other === panel);
        });
        return true;
    }

    // Saving reloads the page without the hash: the last tab is remembered for the browser tab
    function storedTab(key) {
        try {
            if (key === undefined) {
                return window.sessionStorage.getItem('hriSettingsTab');
            }
            window.sessionStorage.setItem('hriSettingsTab', key);
        } catch (e) {
            // Storage can be blocked; the first tab is shown then
        }
        return null;
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function (event) {
            var key = tab.hash.slice(1);
            event.preventDefault();
            if (showTab(key)) {
                storedTab(key);
                window.history.replaceState(null, '', '#' + key);
            }
        });
    });

    window.addEventListener('hashchange', function () {
        showTab(window.location.hash.slice(1));
    });

    // The server already opened the tab of the action the page comes back from
    if (!showTab(window.location.hash.slice(1)) && !wrap.hasAttribute('data-hri-forced-tab')) {
        showTab(storedTab());
    }

    /* Google tab: admin.css hides the rows that do not belong to the chosen API */
    var apiPanel = wrap.querySelector('.hri-panel[data-api]');

    wrap.querySelectorAll('.hri-choices input[type="radio"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            apiPanel.setAttribute('data-api', radio.value);
        });
        // Browsers may restore the form state on reload without firing "change"
        if (radio.checked) {
            apiPanel.setAttribute('data-api', radio.value);
        }
    });

    /* Unsaved changes: the actions work from the saved settings and leaving the page would drop the changes */
    var dirty = false;

    function markDirty() {
        if (dirty) {
            return;
        }
        dirty = true;

        var noted = [];
        wrap.querySelectorAll('.hri-action').forEach(function (button) {
            button.disabled = true;
            if (noted.indexOf(button.parentNode) === -1) {
                var note = document.createElement('span');
                note.className = 'hri-dirty-note';
                note.textContent = l10n.saveFirst || '';
                button.parentNode.appendChild(note);
                noted.push(button.parentNode);
            }
        });
    }

    form.addEventListener('input', markDirty);
    form.addEventListener('change', markDirty);

    /* Write-only fields */
    wrap.querySelectorAll('.hri-secret.has-value').forEach(function (box) {
        var input = box.querySelector('.hri-secret-input');
        var clear = box.querySelector('.hri-secret-clear input');

        box.querySelector('.hri-secret-change').addEventListener('click', function () {
            box.classList.add('is-editing');
            input.focus();
        });
        box.querySelector('.hri-secret-cancel').addEventListener('click', function () {
            input.value = '';
            box.classList.remove('is-editing');
        });
        box.querySelector('.hri-secret-delete').addEventListener('click', function () {
            clear.checked = true;
            box.classList.add('is-clearing');
            markDirty();
        });
        box.querySelector('.hri-secret-undo').addEventListener('click', function () {
            clear.checked = false;
            box.classList.remove('is-clearing');
        });
    });

    /* Copy buttons */
    wrap.querySelectorAll('.hri-copy').forEach(function (button) {
        var label = button.textContent;

        function copied() {
            button.textContent = l10n.copied || label;
            window.setTimeout(function () {
                button.textContent = label;
            }, 1500);
        }

        button.addEventListener('click', function () {
            var field = document.getElementById(button.getAttribute('data-hri-copy'));

            function selectAndCopy() {
                field.select();
                if (document.execCommand('copy')) {
                    copied();
                }
            }

            // The Clipboard API only exists on https pages and may still be refused
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(field.value).then(copied, selectAndCopy);
            } else {
                selectAndCopy();
            }
        });
    });
})();
