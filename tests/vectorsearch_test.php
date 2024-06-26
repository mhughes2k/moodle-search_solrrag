<?php

namespace search_solrrag;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/search/tests/fixtures/testable_core_search.php');
require_once($CFG->dirroot . '/search/tests/fixtures/mock_search_area.php');
//require_once($CFG->dirroot . '/search/engine/solr/tests/fixtures/testable_engine.php');

class vectorsearch_test extends \advanced_testcase {

    /**
     * @var \core_search\manager
     */
    protected $search = null;
    /**
     * @var Instace of core_search_generator.
     */
    protected $generator = null;

    /**
     * @var Instace of testable_engine.
     */
    protected $engine = null;
    public function setUp(): void {
        $this->resetAfterTest();
        set_config('enableglobalsearch', true);
        set_config('searchengine', 'solrrag');

        if (!function_exists('solr_get_version')) {
            $this->markTestSkipped('Solr extension is not loaded.');
        }

        if (!defined('TEST_SEARCH_SOLR_HOSTNAME') || !defined('TEST_SEARCH_SOLR_INDEXNAME') ||
            !defined('TEST_SEARCH_SOLR_PORT')) {
            $this->markTestSkipped('Solr extension test server not set.');
        }

        set_config('server_hostname', TEST_SEARCH_SOLR_HOSTNAME, 'search_solrrag');
        set_config('server_port', TEST_SEARCH_SOLR_PORT, 'search_solrrag');
        set_config('indexname', TEST_SEARCH_SOLR_INDEXNAME, 'search_solrrag');

        if (defined('TEST_SEARCH_SOLR_USERNAME')) {
            set_config('server_username', TEST_SEARCH_SOLR_USERNAME, 'search_solrrag');
        }

        if (defined('TEST_SEARCH_SOLR_PASSWORD')) {
            set_config('server_password', TEST_SEARCH_SOLR_PASSWORD, 'search_solrrag');
        }

        if (defined('TEST_SEARCH_SOLR_SSLCERT')) {
            set_config('secure', true, 'search_solr');
            set_config('ssl_cert', TEST_SEARCH_SOLR_SSLCERT, 'search_solrrag');
        }

        if (defined('TEST_SEARCH_SOLR_SSLKEY')) {
            set_config('ssl_key', TEST_SEARCH_SOLR_SSLKEY, 'search_solrrag');
        }

        if (defined('TEST_SEARCH_SOLR_KEYPASSWORD')) {
            set_config('ssl_keypassword', TEST_SEARCH_SOLR_KEYPASSWORD, 'search_solrrag');
        }

        if (defined('TEST_SEARCH_SOLR_CAINFOCERT')) {
            set_config('ssl_cainfo', TEST_SEARCH_SOLR_CAINFOCERT, 'search_solrrag');
        }

        set_config('fileindexing', 1, 'search_solrrag');

        // We are only test indexing small string files, so setting this as low as we can.
        set_config('maxindexfilekb', 1, 'search_solrrag');

        $this->generator = self::getDataGenerator()->get_plugin_generator('core_search');
        $this->generator->setup();

        // Inject search solr engine into the testable core search as we need to add the mock
        // search component to it.
        $this->engine = new \search_solrrag\testable_engine();
        $this->search = \testable_core_search::instance($this->engine);
        $areaid = \core_search\manager::generate_areaid('core_mocksearch', 'mock_search_area');
        $this->search->add_search_area($areaid, new \core_mocksearch\search\mock_search_area());

        $this->setAdminUser();

        // Cleanup before doing anything on it as the index it is out of this test control.
        $this->search->delete_index();

        // Add moodle fields if they don't exist.
        $schema = new \search_solrrag\schema($this->engine);
        $schema->setup(false);
    }

    public function tearDown(): void {
        parent::tearDown(); // TODO: Change the autogenerated stub
    }

    public function test_vectorsearch() {
        $this->markTestIncomplete('This test has not been implemented yet.');

        $this->engine->test_set_config('fileindexing', true);
        // id 1 is our "fake" but real implementation.
        $aiprovider = \core\ai\api::get_provider(1);
        $file = $this->generator->create_file();
        $record = new \stdClass();
        $record->attachfileids = [$file->get_id()];
        $this->generator->create_record($record);

        $this->search->index();
        $querydata = new \stdClass();
        $querydata->q = '"File contents"';
        $this->assertCount(1, $this->search->search($querydata));
    }
}
