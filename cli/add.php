<?php
define("CLI_SCRIPT", true);
require_once("../../../../config.php");
require_once($CFG->libdir ."/clilib.php");
$admin = $DB->get_record('user', ['id' => 2]);
\core\session\manager::set_user($admin);
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
$doc = [
    'id' => 'testdoc',
    'solr_vector_1356' => $vector,
    'title' => "this is a test document"
];
cli_heading("Adding document to solr");

$document = new \search_solrrag\document("1", "mod_page", "activity");
$document->set('title', 'test document');
$document->set('solr_vector_1536', $vector);
$document->set('content',$doccontent);
$document->set('contextid', \core\context\system::instance()->id);
$document->set('courseid', SITEID);
$document->set('owneruserid', $USER->id);
$document->set('modified', time());
var_dump($document);

$result = $engine->add_document($document);
var_dump($result);
if ($result == false) {
    cli_error("Failed to add document");
} else {
    cli_writeln("Document added to solr");
}

cli_writeln("End of script");
