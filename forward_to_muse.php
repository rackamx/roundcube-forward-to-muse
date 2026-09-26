<?php

/**
 * Forward to Muse
 *
 * Adds a "Muse" button to the mail toolbar. Clicking it opens a forward
 * compose window for the selected message(s), pre-addressed to your Muse,
 * so you can add a note and hit Send to get it in the loop.
 *
 * Each user can set their own address under Settings -> Muse. When unset,
 * the global default below is used.
 *
 * Global default (in the main Roundcube config or this plugin's config.inc.php):
 *   $config['forward_to_muse_recipient'] = 'muse@example.com';
 *
 * This is free and unencumbered software released into the public domain.
 * See the LICENSE file for details.
 */
class forward_to_muse extends rcube_plugin
{
    public $task = 'mail|settings';

    const PREF_KEY = 'forward_to_muse_recipient';
    // Deliberately undeliverable placeholder (RFC 2606): sending is refused
    // until a real address is configured, so nobody can spam this by default.
    const DEFAULT_RECIPIENT = 'muse@example.com';
    const SECTION = 'muse';

    #[\Override]
    public function init()
    {
        $rcmail = rcmail::get_instance();
        $this->load_config();

        if ($rcmail->task == 'mail') {
            $this->register_action('plugin.forward_to_muse.send', [$this, 'send_forward']);

            if ($rcmail->action == '' || $rcmail->action == 'show') {
                $this->include_script('forward_to_muse.js');
                $this->add_texts('localization', true);
                $this->include_stylesheet($this->local_skin_path() . '/forward_to_muse.css');

                $this->add_button([
                    'command' => 'plugin.forward_to_muse',
                    'type' => 'link',
                    'class' => 'button buttonPas forwardtomuse disabled',
                    'classact' => 'button forwardtomuse',
                    'classsel' => 'button forwardtomuse pressed',
                    'title' => 'forward_to_muse.buttontitle',
                    'innerclass' => 'inner',
                    'label' => 'forward_to_muse.buttonlabel',
                ], 'toolbar');
            }
        } elseif ($rcmail->task == 'settings') {
            $this->include_stylesheet($this->local_skin_path() . '/forward_to_muse.css');
            $this->add_hook('preferences_sections_list', [$this, 'prefs_sections']);
            $this->add_hook('preferences_list', [$this, 'prefs_list']);
            $this->add_hook('preferences_save', [$this, 'prefs_save']);
            $this->add_hook('preferences_update', [$this, 'prefs_update']);
        }
    }

    /**
     * Effective recipient address: the user's own setting, else the
     * configured global default. Anything that is not a valid email
     * address falls back to the default.
     */
    public function recipient_address($rcmail = null)
    {
        $rcmail = $rcmail ?: rcmail::get_instance();
        $email = trim((string) $rcmail->config->get(self::PREF_KEY, ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = self::DEFAULT_RECIPIENT;
        }

        return $email;
    }

    /**
     * Plugin action: forward the submitted message(s) to the Muse address
     * immediately, each as its own email with the original attached.
     * Adapted from the core markasjunk email_learn driver.
     */
    public function send_forward()
    {
        $rcmail = rcmail::get_instance();
        $this->add_texts('localization');

        $mailto = $this->recipient_address($rcmail);

        // Refuse to send until a real address is configured
        if ($mailto === self::DEFAULT_RECIPIENT) {
            $rcmail->output->command('display_message', $this->gettext('notconfigured'), 'error');
            $rcmail->output->send();

            return;
        }

        $uids = rcube_utils::get_input_value('_uid', rcube_utils::INPUT_POST);
        $mbox = rcube_utils::get_input_string('_mbox', rcube_utils::INPUT_POST);
        $messageset = rcmail_action::get_uids($uids, $mbox, $multifolder);

        // select-all resolves to '*' which get_uids() does not expand
        if ($uids == '*' && !$multifolder) {
            $messageset = [$mbox => $rcmail->storage->index($mbox)->get()];
        }

        $total = 0;
        foreach ($messageset as $folder_uids) {
            $total += count((array) $folder_uids);
        }

        $count = 0;
        foreach ($messageset as $folder => $folder_uids) {
            $rcmail->storage->set_folder($folder);

            foreach ((array) $folder_uids as $uid) {
                // keep the SMTP connection open until the last message
                if ($this->forward_one($uid, $folder, $mailto, $count + 1 >= $total)) {
                    $count++;
                    $rcmail->output->command('set_message', $uid, 'forwarded', true);
                }
            }
        }

        if ($count > 0) {
            $rcmail->output->command('display_message', $this->gettext('sent'), 'confirmation');
        } else {
            $rcmail->output->command('display_message', $this->gettext('sendfailed'), 'error');
        }

        $rcmail->output->send();
    }

    /**
     * Forward a single message: new mail from the user's identity to the
     * Muse address, original message attached as .eml, copy saved to Sent.
     * The original is flagged as forwarded on success.
     *
     * @return bool True when the message was sent
     */
    protected function forward_one($uid, $mbox, $mailto, $disconnect = true)
    {
        $rcmail = rcmail::get_instance();
        $identity = $rcmail->user->get_identity();
        $from = $identity['email'];
        $from_string = format_email_recipient($from, $identity['name']);
        $temp_dir = unslashify($rcmail->config->get('temp_dir'));

        $message = new rcube_message($uid);

        if (empty($message->headers)) {
            return false;
        }

        if (!empty($message->headers->charset)) {
            $rcmail->storage->set_charset($message->headers->charset);
        }

        $output = $rcmail->output;
        $sendmail = new rcmail_sendmail(
            ['forward_uid' => $uid, 'mailbox' => $mbox],
            [
                'sendmail' => true,
                'from' => $from,
                'mailto' => $mailto,
                'dsn_enabled' => false,
                'charset' => 'UTF-8',
                'error_handler' => static function (...$args) use ($output) {
                    call_user_func_array([$output, 'show_message'], $args);
                    $output->send();
                },
            ]
        );

        $orig_subject = (string) $message->get_header('subject');
        $headers = [
            'Date' => $rcmail->user_date(),
            'From' => $from_string,
            'To' => $mailto,
            'Subject' => 'Fwd: ' . $orig_subject,
            'User-Agent' => $rcmail->config->get('useragent'),
            'Message-ID' => $rcmail->gen_message_id($from),
            'X-Sender' => $from,
        ];

        // attach the original message source as-is (lossless, any content type)
        $disp_name = ($orig_subject !== '' ? $orig_subject : 'message_rfc822') . '.eml';
        $message_file = tempnam($temp_dir, 'rcm');
        $attachment = [];

        if ($message_file && ($fp = fopen($message_file, 'w'))) {
            $rcmail->storage->get_raw_body($uid, $fp);
            fclose($fp);

            $attachment = [
                'name' => $disp_name,
                'mimetype' => 'message/rfc822',
                'path' => $message_file,
                'size' => filesize($message_file),
                'charset' => $message->headers->charset,
            ];
        }

        // without the attachment there is nothing worth sending
        if (empty($attachment)) {
            if ($message_file) {
                @unlink($message_file);
            }

            return false;
        }

        $mime = $sendmail->create_message($headers, $this->gettext('forwardbody'), false, [$attachment]);

        $folding = (int) $rcmail->config->get('mime_param_folding');
        $mime->addAttachment($attachment['path'],
            $attachment['mimetype'], $attachment['name'], true,
            '8bit', 'attachment', $attachment['charset'], '', '',
            $folding ? 'quoted-printable' : null,
            $folding == 2 ? 'quoted-printable' : null,
            '', RCUBE_CHARSET
        );

        $sent = $sendmail->deliver_message($mime, $disconnect);

        if ($sent) {
            $sendmail->save_message($mime);
        }

        @unlink($message_file);

        return $sent;
    }

    /**
     * Hook to add the "Muse" section to the Settings page.
     */
    public function prefs_sections($p)
    {
        $this->add_texts('localization');

        $p['list'][self::SECTION] = [
            'id' => self::SECTION,
            'section' => $this->gettext('settingssection'),
        ];

        return $p;
    }

    /**
     * Hook to render the per-user Muse address field.
     */
    public function prefs_list($p)
    {
        $rcmail = rcmail::get_instance();
        $dont_override = (array) $rcmail->config->get('dont_override', []);

        if ($p['section'] == self::SECTION && !in_array(self::PREF_KEY, $dont_override)) {
            $this->add_texts('localization');

            $field_id = 'ff_forward_to_muse';
            $input = new html_inputfield(['name' => '_' . self::PREF_KEY, 'id' => $field_id, 'size' => 40]);
            $p['blocks']['main']['options'][self::PREF_KEY] = [
                'title' => html::label($field_id, rcube::Q($this->gettext('recipientlabel'))),
                'content' => $input->show($this->recipient_address($rcmail))
                    . '<div class="hint">' . rcube::Q($this->gettext('recipienthint')) . '</div>',
            ];
        }

        return $p;
    }

    /**
     * Hook to save the per-user Muse address. An empty field clears the
     * override so the global default applies again.
     */
    public function prefs_save($p)
    {
        $rcmail = rcmail::get_instance();
        $dont_override = (array) $rcmail->config->get('dont_override', []);

        if ($p['section'] == self::SECTION && !in_array(self::PREF_KEY, $dont_override)) {
            $email = trim((string) rcube_utils::get_input_string('_' . self::PREF_KEY, rcube_utils::INPUT_POST));

            if ($email === '') {
                $p['prefs'][self::PREF_KEY] = null;
            } elseif (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $p['prefs'][self::PREF_KEY] = $email;
            }
            // invalid input keeps the previous value; prefs_update() aborts with an error
        }

        return $p;
    }

    /**
     * Hook to reject invalid addresses with an error message instead of saving.
     */
    public function prefs_update($p)
    {
        $rcmail = rcmail::get_instance();
        $dont_override = (array) $rcmail->config->get('dont_override', []);

        if (rcube_utils::get_input_string('_section', rcube_utils::INPUT_POST) == self::SECTION
            && !in_array(self::PREF_KEY, $dont_override)
        ) {
            $email = trim((string) rcube_utils::get_input_string('_' . self::PREF_KEY, rcube_utils::INPUT_POST));

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->add_texts('localization');
                $p['abort'] = true;
                $p['message'] = $this->gettext('invalidemail');
            }
        }

        return $p;
    }
}
