/**
 * Forward to Muse plugin script
 *
 * Sends the selected message(s) straight to your Muse, no compose window.
 * Mirrors the markasjunk client/server round-trip.
 *
 * This is free and unencumbered software released into the public domain.
 * See the LICENSE file for details.
 */

rcube_webmail.prototype.forward_to_muse = function () {
    var uids = this.env.uid ? [this.env.uid] : (this.message_list ? this.message_list.get_selection() : []);
    if (uids && uids.length) {
        var lock = this.set_busy(true, 'sendingmessage');
        this.http_post('plugin.forward_to_muse.send', this.selection_post_data({ _uid: uids }), lock);
    }
};

if (window.rcmail) {
    rcmail.addEventListener('init', function () {
        // register command (directly enable in message view mode)
        rcmail.register_command('plugin.forward_to_muse', function () {
            rcmail.forward_to_muse();
        }, rcmail.env.uid);

        if (rcmail.message_list) {
            rcmail.message_list.addEventListener('select', function (list) {
                rcmail.enable_command('plugin.forward_to_muse', list.get_selection(false).length > 0);
            });
        }
    });
}
