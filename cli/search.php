<?php
define("CLI_SCRIPT", true);
require_once("../../../../config.php");
require_once($CFG->libdir ."/clilib.php");
$search = $search = \core_search\manager::instance(true, true);

$engine = $search->get_engine();

/**
 * \core\ai\AIProvider
 */
$provider = core\ai\api::get_provider(1);

$doccontent = file_get_contents($CFG->dirroot . "/search/engine/solrrag/tests/testdoc.txt");
if (file_exists($CFG->dirroot . "/search/engine/solrrag/tests/testdoc_vector.txt")) {
    $vector = file_get_contents($CFG->dirroot . "/search/engine/solrrag/tests/testdoc_vector.txt");
    $vector = json_decode($vector, true);
} else {
    $client = new \core\ai\AIClient($provider);
    $vector = $client->embed_query($doccontent);
    file_put_contents(
        $CFG->dirroot . "/search/engine/solrrag/tests/testdoc_vector.txt",
        json_encode($vector)
    );
}
$admin = $DB->get_record('user', ['id' => 2]);
\core\session\manager::set_user($admin);
$doc = [
    'id' => 'testdoc',
    'solr_vector_1356' => $vector,
    'title' => "this is a test document"
];
$formdata = (object) [
    'q' => 'directory',
    'areaids' => [],
    'title' => '',
    'courseids' => [],
    'timestart' => 0,
    'timeend' => 0,
    'context' => \core\context\system::instance(),
];
//print_r($formdata);
cli_heading("Searching for document");
$result = $search->search($formdata,0);

var_dump($result);


cli_heading("Similarity search");
$formdata->similarity = true;
$formdata->vector = $vector;
$result = $search->search($formdata,0);
var_dump($result);


cli_writeln("End of script");
