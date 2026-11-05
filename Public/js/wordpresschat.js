/**
 * The "Send via" picker (Resources/views/conversation/reply_picker.blade.php)
 * renders at core's reply_form.after hook, OUTSIDE the reply <form>. Core never
 * submits the form natively: its .btn-reply-submit handler posts
 * $(".form-reply:first").serialize(), which only sees the form's descendants.
 * So the checked radio is copied into a hidden `wpc_channel` input inside the
 * form.
 *
 * Copied on every click that could be a send, from a capture-phase listener on
 * document (it runs before core's bubble-phase handler, and survives core
 * re-creating the toolbar buttons). Core also sends via a scripted
 * button.click() (Ctrl+Enter, send-after-draft); jQuery runs its own handlers
 * before the native event, so the capture listener would be late there. The
 * hidden input is therefore also kept in sync whenever the radio changes and
 * once on load.
 *
 * A note is never sent to the visitor, so the picker is hidden while the
 * form's `is_note` input has a value (core sets it in showNoteForm() and
 * clears it in showReplyForm(), both from the .conv-add-note and .conv-reply
 * click handlers, which run after a capture-phase listener: the picker is
 * synced a tick later).
 */
document.addEventListener('DOMContentLoaded', function () {
    function copyChoiceIntoForm() {
        var picker = document.querySelector('[data-wpc-picker="1"]');
        if (!picker) {
            return;
        }

        var checked = picker.querySelector('input[name="wpc_channel_choice"]:checked');
        var form = document.querySelector('.form-reply');
        if (!checked || !form) {
            return;
        }

        var hidden = form.querySelector('input[name="wpc_channel"]');
        if (!hidden) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'wpc_channel';
            form.appendChild(hidden);
        }
        hidden.value = checked.value;
    }

    function syncPickerWithNote() {
        var picker = document.querySelector('[data-wpc-picker="1"]');
        var note = document.querySelector('.form-reply [name="is_note"]');
        if (picker) {
            picker.classList.toggle('hidden', !!(note && note.value));
        }
    }

    document.addEventListener('click', function (e) {
        if (e.target && e.target.closest && e.target.closest('.conv-add-note, .conv-reply')) {
            setTimeout(syncPickerWithNote, 0);
        }
    }, true);

    document.addEventListener('click', function (e) {
        var target = e.target;
        while (target && target.nodeType === 1) {
            if (target.classList && target.classList.contains('btn-reply-submit')) {
                copyChoiceIntoForm();
                return;
            }
            target = target.parentNode;
        }
    }, true);

    document.addEventListener('change', function (e) {
        if (e.target && e.target.name === 'wpc_channel_choice') {
            copyChoiceIntoForm();
        }
    }, true);

    copyChoiceIntoForm();
    syncPickerWithNote();
});
