<?php
define("CLI_SCRIPT", true);
require_once("../../../../config.php");
require_once($CFG->libdir ."/clilib.php");

$usage = "Perform a search against Moodle global index via AI.

Usage:
    # php search.php --provider=<provider_id> --user=<userid> --query=<query>

Options:
    -h, --help                      Print this help
    -p, --provider=<provider_id>    AI provider ID
    -c, --course=<courseid>         Course ID
    -a, --chat=<chatcmid>           Chat activity CMID
    -u, --user=<userid>             User (id) to execute as
    -q, --query=<query>             Search query
    -v, --verbose                   Verbose output
    -vv, --veryverbose              VeryVerbose output
";
[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'query' => null,
    'course' => null,
    'chat' => null, // 'chat cm id
    'user' => null,
    'provider' => null,
    'verbose' => false,
    'veryverbose' => false
], [
    'h' => 'help',
    'q' => 'query',
    'c' => 'course',
    'a' => 'chat cm id',
    'u' => 'user',
    'p' => 'provider',
    'v' => 'verbose',
    'vv' => 'veryverbose'
]);

if ($unrecognised) {
    $unrecognised = implode(PHP_EOL.'  ', $unrecognised);
    cli_error(get_string('cliunknowoption', 'core_admin', $unrecognised));
    exit(2);
}

if ($options['help']) {
    cli_write($usage);
    exit(2);
}

//if (empty($options['course'])) {
//    cli_error("course is required");
//}

if (empty($options['user'])) {
    cli_error("user is required");
}

$query = $options['query'];
if (empty($query)) {
    cli_error("Query is required");
}

$course = false;
if ($options['course']) {
    $course = get_course($options['course']);
}
$user = core_user::get_user($options['user']);
\core\session\manager::set_user($user);

if (empty($options['chat'])) {
    cli_error("The chat activity CMID is required");
}

$search = \core_search\manager::instance(true, true);

$provider = local_ai\api::get_provider($options['provider']);

[$cmcourse,$cm] = get_course_and_cm_from_cmid($options['chat']);

$settings = $provider->get_settings_for_user($cm, $user);
$settings['userquery'] = $query;
if ($course !== false) {
    $settings['courseids'] = [$course->id];
}
$settings['returnemptydocs'] = true;
cli_heading("RAG Search Settings");
var_dump($settings);
cli_separator();
cli_heading("Searching");
$results = $search->search((object)$settings);
$verbose = $options['verbose'];
$veryverbose = $options['veryverbose'];
$maxlength = 150; // Make option.
$omit = ['id','itemid', 'title', 'content'];
foreach($results as $doc) {
    //var_dump($doc);
    cli_writeln("SOLR id: {$doc->get('id')}");
    cli_writeln("itemid: {$doc->get('itemid')}");
    cli_writeln("Title: {$doc->get('title')}");
    cli_writeln("Score: {$doc->get('score')}");
    if ($doc->is_set('content')) {
        if ($verbose) {

        } else if ($veryverbose) {
            cli_writeln("{$doc->get('content')}");
        } else {
            $shortened = substr($doc->get('content'), 0, $maxlength);
            cli_writeln("$shortened");
        }
    } else {
        cli_problem("No Content");
    }
    cli_writeln($doc->get_doc_url());
    cli_separator();
}
