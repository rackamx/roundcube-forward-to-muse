<?php
// Self-contained smoke test for the forward_to_muse plugin.
// Stubs the small slice of Roundcube API the plugin uses; no framework needed.
// Run: php roundcube-custom-plugins/forward_to_muse/tests/smoke.php
error_reporting(E_ALL);

const PLUGIN_DIR = __DIR__ . '/..';

class rcube_plugin
{
    public function init() {}
    public $ID = 'forward_to_muse';
    public $task;
    public $calls = [];
    public $hooks = [];
    public $actions = [];
    public function register_action($a, $cb) { $this->actions[$a] = $cb; }
    private static $texts = null;
    public function load_config($n = null) { $this->calls[] = ['load_config', $n]; }
    public function include_script($fn, $a = []) { $this->calls[] = ['script', $fn]; }
    public function include_stylesheet($fn) { $this->calls[] = ['css', $fn]; }
    public function add_texts($d, $m = false) { $this->calls[] = ['texts', $d, $m]; }
    public function add_button($p, $c) { $this->calls[] = ['button', $p, $c]; }
    public function add_hook($h, $cb) { $this->hooks[$h] = $cb; }
    public function local_skin_path($e = null, $s = null) { return 'skins/elastic'; }
    public function gettext($k)
    {
        if (self::$texts === null) {
            $labels = [];
            $messages = [];
            require PLUGIN_DIR . '/localization/en_US.inc';
            self::$texts = array_merge($labels, $messages);
        }
        return self::$texts[$k] ?? $k;
    }
}
class FakeConfig
{
    public function __construct(private array $v) {}
    public function get($k, $d = null) { return $this->v[$k] ?? $d; }
}
class FakeOutput
{
    public array $env = [];
    public array $commands = [];
    public array $messages = [];
    public bool $sent = false;
    public function set_env($k, $v) { $this->env[$k] = $v; }
    public function command($m, ...$a) { $this->commands[] = [$m, ...$a]; }
    public function show_message($m, $t = 'notice') { $this->messages[] = [$m, $t]; }
    public function send() { $this->sent = true; }
}
class rcmail
{
    public static ?rcmail $inst = null;
    public $task = 'mail';
    public $action = '';
    public FakeConfig $config;
    public FakeOutput $output;
    public $user;
    public $storage;
    public static function get_instance(): rcmail { return self::$inst ??= new rcmail(); }
    public function user_date() { return 'Thu, 01 Jan 2026 00:00:00 +0000'; }
    public function gen_message_id($from = null) { return '<test-message-id@example.com>'; }
}
class rcube_utils
{
    const INPUT_POST = 1;
    public static array $post = [];
    public static function get_input_string($k, $src = null) { return self::$post[$k] ?? null; }
    public static function get_input_value($k, $src = null) { return self::$post[$k] ?? null; }
}
const RCUBE_CHARSET = 'UTF-8';
function format_email_recipient($email, $name = null) { return $name ? "$name <$email>" : $email; }
function unslashify($s) { return rtrim($s, '/'); }
class rcmail_action
{
    public static $messageset = null;
    public static function get_uids($uids, $mbox = null, &$multi = false)
    {
        $multi = false;
        return self::$messageset ?? [$mbox => (array) $uids];
    }
}
class FakeUser
{
    public function get_identity() { return ['email' => 'me@example.com', 'name' => 'Me']; }
}
class FakeIndex
{
    public static $uids = [11, 12];
    public function get() { return self::$uids; }
}
class FakeStorage
{
    public array $folders = [];
    public function set_folder($f) { $this->folders[] = $f; }
    public function set_charset($c) {}
    public function get_raw_body($uid, $fp) { fwrite($fp, "RAW-MESSAGE-$uid"); }
    public function index($mbox) { return new FakeIndex(); }
}
class rcube_message
{
    public $headers;
    private $uid;
    public function __construct($uid)
    {
        $this->uid = $uid;
        $this->headers = (object) ['charset' => 'UTF-8'];
    }
    public function get_header($n) { return $n === 'subject' ? "Subject {$this->uid}" : null; }
}
class FakeMime
{
    public $headers;
    public $body;
    public array $attachments = [];
    public function addAttachment($path, $mime, $name, ...$rest) { $this->attachments[] = [$path, $mime, $name]; }
    public function headers() { return $this->headers; }
}
class rcmail_sendmail
{
    public static array $instances = [];
    public $data;
    public $options;
    public array $delivered = [];
    public array $saved = [];
    public function __construct($data = [], $options = [])
    {
        $this->data = (array) $data;
        $this->options = (array) $options;
        self::$instances[] = $this;
    }
    public function create_message($headers, $body, $html = false, $att = [])
    {
        $m = new FakeMime();
        $m->headers = $headers;
        $m->body = $body;
        return $m;
    }
    public function deliver_message($msg, $disc = true) { $this->delivered[] = $msg; return true; }
    public function save_message($msg) { $this->saved[] = $msg; return true; }
}
class html
{
    public static function label($attr, $cont) { return "<label>$cont</label>"; }
}
class rcube
{
    public static function Q($s) { return $s; }
}
class html_inputfield
{
    public function __construct(private array $attrib = []) {}
    public function show($value = null, $attrib = null)
    {
        if (is_array($attrib)) {
            $this->attrib = array_merge($this->attrib, $attrib);
        }
        return '<input name="' . ($this->attrib['name'] ?? '') . '" value="' . $value . '">';
    }
}

require PLUGIN_DIR . '/forward_to_muse.php';

$fail = 0;
function check($name, $cond) { global $fail; echo ($cond ? 'PASS' : 'FAIL') . " $name\n"; if (!$cond) $fail++; }
function new_mail_rc($action = '', array $cfg = []) {
    rcmail::$inst = new rcmail();
    rcmail::$inst->task = 'mail';
    rcmail::$inst->action = $action;
    rcmail::$inst->config = new FakeConfig($cfg);
    rcmail::$inst->output = new FakeOutput();
    return rcmail::$inst;
}

// --- mail task: toolbar button + effective recipient ---
$p = new forward_to_muse();
check('task covers mail', str_contains($p->task, 'mail'));
check('task covers settings', str_contains($p->task, 'settings'));

$rc = new_mail_rc('', ['forward_to_muse_recipient' => 'user@example.com']);
$p->init();
$buttons = array_values(array_filter($p->calls, fn($c) => $c[0] === 'button'));
check('button added to toolbar', count($buttons) === 1 && $buttons[0][2] === 'toolbar');
check('button command', $buttons[0][1]['command'] === 'plugin.forward_to_muse');
check('send action registered', isset($p->actions['plugin.forward_to_muse.send']));
check('user pref wins', $p->recipient_address($rc) === 'user@example.com');

$rc = new_mail_rc('', ['forward_to_muse_recipient' => 'not-an-email']);
check('invalid value falls back to default',
    (new forward_to_muse())->recipient_address($rc) === 'muse@example.com');

$rc = new_mail_rc('show', []);
check('missing value falls back to default',
    (new forward_to_muse())->recipient_address($rc) === 'muse@example.com');

$rc = new_mail_rc('compose', ['forward_to_muse_recipient' => 'user@example.com']);
$p3 = new forward_to_muse();
$p3->init();
check('no button outside list/message view',
    count(array_filter($p3->calls, fn($c) => $c[0] === 'button')) === 0);

// --- settings task: hooks registered ---
rcmail::$inst = new rcmail();
rcmail::$inst->task = 'settings';
rcmail::$inst->action = 'preferences';
rcmail::$inst->config = new FakeConfig([]);
rcmail::$inst->output = new FakeOutput();
$ps = new forward_to_muse();
$ps->init();
foreach (['preferences_sections_list', 'preferences_list', 'preferences_save', 'preferences_update'] as $h) {
    check("hook $h registered", isset($ps->hooks[$h]));
}
check('settings includes skin css', in_array(['css', 'skins/elastic/forward_to_muse.css'], $ps->calls));
check('settings adds no toolbar button',
    count(array_filter($ps->calls, fn($c) => $c[0] === 'button')) === 0);

// --- preferences section + field ---
$r = $ps->prefs_sections(['list' => [], 'cols' => ['section']]);
check('muse section added', ($r['list']['muse']['id'] ?? '') === 'muse');
check('muse section labeled', !empty($r['list']['muse']['section']));

rcmail::$inst->config = new FakeConfig(['forward_to_muse_recipient' => 'user@example.com']);
$r = $ps->prefs_list(['section' => 'muse', 'blocks' => [], 'current' => 'muse']);
$html = json_encode($r['blocks']);
check('muse field rendered', str_contains($html, '_forward_to_muse_recipient'));
check('muse field prefilled', str_contains($html, 'user@example.com'));
$r = $ps->prefs_list(['section' => 'general', 'blocks' => [], 'current' => 'general']);
check('other sections untouched', $r['blocks'] === []);
rcmail::$inst->config = new FakeConfig(['dont_override' => ['forward_to_muse_recipient']]);
$r = $ps->prefs_list(['section' => 'muse', 'blocks' => [], 'current' => 'muse']);
check('locked pref hidden', $r['blocks'] === []);

// --- preferences save ---
rcmail::$inst->config = new FakeConfig([]);
rcube_utils::$post = ['_forward_to_muse_recipient' => 'new@example.com'];
$r = $ps->prefs_save(['section' => 'muse', 'prefs' => []]);
check('valid email saved', ($r['prefs']['forward_to_muse_recipient'] ?? null) === 'new@example.com');
rcube_utils::$post = ['_forward_to_muse_recipient' => '  '];
$r = $ps->prefs_save(['section' => 'muse', 'prefs' => []]);
check('empty clears override', array_key_exists('forward_to_muse_recipient', $r['prefs']) && $r['prefs']['forward_to_muse_recipient'] === null);
rcube_utils::$post = ['_forward_to_muse_recipient' => 'bogus'];
$r = $ps->prefs_save(['section' => 'muse', 'prefs' => []]);
check('invalid email not saved', !array_key_exists('forward_to_muse_recipient', $r['prefs']));
rcube_utils::$post = ['_forward_to_muse_recipient' => 'new@example.com'];
$r = $ps->prefs_save(['section' => 'general', 'prefs' => []]);
check('save scoped to muse section', $r['prefs'] === []);
rcmail::$inst->config = new FakeConfig(['dont_override' => ['forward_to_muse_recipient']]);
$r = $ps->prefs_save(['section' => 'muse', 'prefs' => []]);
check('save skipped when locked', $r['prefs'] === []);

// --- save validation (abort with message on invalid) ---
rcmail::$inst->config = new FakeConfig([]);
rcube_utils::$post = ['_section' => 'muse', '_forward_to_muse_recipient' => 'bogus'];
$r = $ps->prefs_update(['prefs' => [], 'old' => []]);
check('invalid email aborts save', !empty($r['abort']) && !empty($r['message']));
rcube_utils::$post = ['_section' => 'muse', '_forward_to_muse_recipient' => 'ok@example.com'];
$r = $ps->prefs_update(['prefs' => [], 'old' => []]);
check('valid email does not abort', empty($r['abort']));
rcube_utils::$post = ['_section' => 'general', '_forward_to_muse_recipient' => 'bogus'];
$r = $ps->prefs_update(['prefs' => [], 'old' => []]);
check('abort scoped to muse section', empty($r['abort']));

// --- one-click send action ---
function new_send_rc(array $post, array $cfg = [])
{
    rcmail::$inst = new rcmail();
    rcmail::$inst->task = 'mail';
    rcmail::$inst->action = 'plugin.forward_to_muse.send';
    rcmail::$inst->config = new FakeConfig($cfg + [
        'forward_to_muse_recipient' => 'agent@example.org',
        'temp_dir' => sys_get_temp_dir(),
        'mime_param_folding' => 0,
        'useragent' => 'TestAgent',
    ]);
    rcmail::$inst->output = new FakeOutput();
    rcmail::$inst->user = new FakeUser();
    rcmail::$inst->storage = new FakeStorage();
    rcube_utils::$post = $post;
    rcmail_sendmail::$instances = [];
    rcmail_action::$messageset = null;
    return rcmail::$inst;
}

$rc = new_send_rc(['_uid' => [5, 6], '_mbox' => 'INBOX']);
$p->send_forward();
check('one send per message', count(rcmail_sendmail::$instances) === 2);
$first = rcmail_sendmail::$instances[0];
check('send addressed to muse', $first->options['mailto'] === 'agent@example.org');
check('send from identity', $first->options['from'] === 'me@example.com');
check('forward data passed for flagging',
    ($first->data['forward_uid'] ?? null) === 5 && ($first->data['mailbox'] ?? null) === 'INBOX');
$sent = $first->delivered[0];
check('delivered once', count($first->delivered) === 1);
check('subject forwarded', ($sent->headers['Subject'] ?? '') === 'Fwd: Subject 5');
check('to header', ($sent->headers['To'] ?? '') === 'agent@example.org');
check('message-id set', !empty($sent->headers['Message-ID']));
check('original attached as eml', count($sent->attachments) === 1
    && $sent->attachments[0][1] === 'message/rfc822'
    && str_ends_with($sent->attachments[0][2], '.eml'));
check('temp file cleaned', !file_exists($sent->attachments[0][0]));
check('saved to sent', count($first->saved) === 1);
check('rows marked forwarded', in_array(['set_message', 5, 'forwarded', true], $rc->output->commands)
    && in_array(['set_message', 6, 'forwarded', true], $rc->output->commands));
$confirm = array_values(array_filter($rc->output->commands, fn($c) => $c[0] === 'display_message'));
check('confirmation shown', count($confirm) === 1 && $confirm[0][2] === 'confirmation');
check('response sent', $rc->output->sent === true);

$rc = new_send_rc(['_uid' => [], '_mbox' => 'INBOX']);
$p->send_forward();
check('nothing selected sends nothing', rcmail_sendmail::$instances === []);
$err = array_values(array_filter($rc->output->commands, fn($c) => $c[0] === 'display_message'));
check('empty selection shows error', count($err) === 1 && $err[0][2] === 'error');

$rc = new_send_rc(['_uid' => '*', '_mbox' => 'INBOX']);
rcmail_action::$messageset = ['INBOX' => ['*']];
$p->send_forward();
check('select-all resolved via index', count(rcmail_sendmail::$instances) === 2);

$rc = new_send_rc(['_uid' => [1, 2], '_mbox' => 'A']);
rcmail_action::$messageset = ['A' => [1], 'B' => [2]];
$p->send_forward();
check('folder switched per group', $rc->storage->folders === ['A', 'B']);
check('multi-folder sends all', count(rcmail_sendmail::$instances) === 2);

$rc = new_send_rc(['_uid' => [5], '_mbox' => 'INBOX'], ['forward_to_muse_recipient' => 'bogus']);
$p->send_forward();
check('placeholder default never sends', rcmail_sendmail::$instances === []);
$nc = array_values(array_filter($rc->output->commands, fn($c) => $c[0] === 'display_message'));
check('unconfigured shows guidance', count($nc) === 1 && $nc[0][2] === 'error'
    && str_contains($nc[0][1], 'Settings -> Muse'));

// --- localization + dist config ---
$labels = [];
$messages = [];
require PLUGIN_DIR . '/localization/en_US.inc';
check('label buttonlabel', ($labels['buttonlabel'] ?? '') === 'Muse');
check('label settingssection', !empty($labels['settingssection']));
check('label recipientlabel', !empty($labels['recipientlabel']));
check('message invalidemail', !empty($messages['invalidemail']));
check('message notconfigured', !empty($messages['notconfigured']));
$config = [];
require PLUGIN_DIR . '/config.inc.php.dist';
check('dist recipient default', ($config['forward_to_muse_recipient'] ?? '') === 'muse@example.com');

exit($fail ? 1 : 0);
